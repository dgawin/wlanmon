<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Settings.php';

/*
 * Datenhaltung: wie lange Messungen aufbewahrt werden und das nächtliche
 * Aufräumen (cleanup_data.php). Die Aufbewahrung pflegt der Admin unter
 * /settings/retention (Tabelle "settings", Schlüssel "retention");
 * 0 Tage = unbegrenzt. Das DB-Backup (backup_db.php) läuft davor, sodass
 * gelöschte Daten noch im letzten Backup stecken.
 */

/** Standard-Aufbewahrung in Tagen, solange nichts gespeichert ist. */
const RETENTION_DEFAULTS = ['scan_days' => 90, 'test_days' => 365];

/** Welche Messarten zu welcher Einstellung gehören. */
const RETENTION_KINDS = ['scan_days' => ['scan'], 'test_days' => ['connection_test', 'lan_test']];

/** Obergrenze für die Eingabe (10 Jahre). */
const RETENTION_MAX_DAYS = 3650;

/**
 * In Portionen löschen: ein einzelnes DELETE über Millionen Zeilen würde die
 * Tabelle lange sperren und die Probes beim Hochladen blockieren.
 */
const RETENTION_BATCH = 5000;

/** @return array{scan_days: int, test_days: int} */
function retention_config(): array
{
    $saved = setting_get('retention') ?? [];
    $cfg = [];
    foreach (RETENTION_DEFAULTS as $key => $default) {
        $cfg[$key] = isset($saved[$key]) && is_numeric($saved[$key])
            ? min(RETENTION_MAX_DAYS, max(0, (int) $saved[$key]))
            : $default;
    }
    return $cfg;
}

function retention_cutoff(int $days): string
{
    return (new DateTime("-{$days} days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/** Führt $sql (DELETE ohne LIMIT) portionsweise aus, gibt die Gesamtzahl zurück. */
function retention_delete_batched(string $sql, array $params): int
{
    $total = 0;
    do {
        $stmt = db()->prepare($sql . ' LIMIT ' . RETENTION_BATCH);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        $total += $count;
    } while ($count === RETENTION_BATCH);
    return $total;
}

/**
 * Löscht alles, was älter als die eingestellte Aufbewahrung ist, und merkt
 * sich den Lauf (Schlüssel "retention_last_run", Anzeige im Dashboard).
 *
 * @return array<string, int> gelöschte Zeilen je Art
 */
function retention_cleanup(): array
{
    $cfg = retention_config();
    $deleted = [];
    foreach (RETENTION_KINDS as $key => $kinds) {
        foreach ($kinds as $kind) {
            $deleted[$kind] = $cfg[$key] > 0
                ? retention_delete_batched(
                    'DELETE FROM measurements WHERE kind = ? AND received_at < ?',
                    [$kind, retention_cutoff($cfg[$key])]
                )
                : 0;
        }
    }
    // Die Störungshistorie gehört zu den Tests: aufgelöste Alerts genauso lange.
    $deleted['alerts'] = $cfg['test_days'] > 0
        ? retention_delete_batched(
            'DELETE FROM alerts WHERE resolved_at IS NOT NULL AND resolved_at < ?',
            [retention_cutoff($cfg['test_days'])]
        )
        : 0;
    // Änderungsprotokoll so lange wie die Tests; Tabelle gibt es erst nach dem ersten Eintrag.
    try {
        $deleted['audit'] = $cfg['test_days'] > 0
            ? retention_delete_batched('DELETE FROM config_audit WHERE created_at < ?', [retention_cutoff($cfg['test_days'])])
            : 0;
    } catch (PDOException $e) {
        $deleted['audit'] = 0;
    }
    // Abgelaufene Einladungslinks sind wertlos.
    $deleted['invites'] = retention_delete_batched('DELETE FROM invites WHERE expires_at < ?', [gmdate('Y-m-d H:i:s')]);

    setting_set('retention_last_run', ['at' => gmdate('Y-m-d H:i:s'), 'deleted' => $deleted]);
    return $deleted;
}

/**
 * Überblick für /settings/retention: Anzahl und älteste Messung je Art sowie
 * die Größe der Tabelle (laut information_schema, ohne Rechte dort null).
 *
 * @return array{kinds: array<string, array{count: int, oldest: ?string}>, size_bytes: ?int}
 */
function retention_overview(): array
{
    $kinds = [];
    foreach (db()->query('SELECT kind, COUNT(*) AS n, MIN(received_at) AS oldest FROM measurements GROUP BY kind')->fetchAll() as $row) {
        $kinds[(string) $row['kind']] = ['count' => (int) $row['n'], 'oldest' => $row['oldest']];
    }
    $size = null;
    try {
        $stmt = db()->prepare(
            'SELECT data_length + index_length FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute(['measurements']);
        $value = $stmt->fetchColumn();
        $size = $value !== false && $value !== null ? (int) $value : null;
    } catch (PDOException $e) {
        // Kein Zugriff auf information_schema - Größe dann nicht anzeigen.
    }
    return ['kinds' => $kinds, 'size_bytes' => $size];
}

/** Bytes lesbar, z.B. "1,4 GB" bzw. "1.4 GB". */
function format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '–';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    $decimal = function_exists('effective_lang') && effective_lang() === 'en' ? '.' : ',';
    return number_format($value, $i === 0 ? 0 : 1, $decimal, '') . ' ' . $units[$i];
}
