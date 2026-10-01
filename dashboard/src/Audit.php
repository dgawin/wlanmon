<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Secrets.php';

/*
 * Änderungsprotokoll: wer wann was an Geräte-Konfiguration, Standorten,
 * Benutzern, Alerting und Datenhaltung geändert hat (Tabelle
 * "config_audit"). Normale Werte stehen als "alt -> neu" drin, geheime
 * Felder (PSK, Passwörter, Schlüssel, Tokens - siehe SECRET_FIELD_NAMES)
 * nur als "geändert", nie mit Wert. Die Tabelle wird beim ersten Eintrag
 * angelegt; alte Einträge räumt cleanup_data.php mit der Aufbewahrung der
 * Tests weg.
 */

const AUDIT_DDL = 'CREATE TABLE IF NOT EXISTS config_audit (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at   DATETIME NOT NULL,
    user_id      INT UNSIGNED NULL,
    username     VARCHAR(191) NULL,
    object_type  VARCHAR(32) NOT NULL,
    object_id    VARCHAR(191) NOT NULL,
    action       VARCHAR(32) NOT NULL,
    changes      LONGTEXT NULL,
    INDEX idx_object (object_type, object_id, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

/** Längere Werte (z.B. Zertifikate) im Protokoll kürzen. */
const AUDIT_MAX_VALUE = 80;

/**
 * Eintrag schreiben. Scheitert das (z.B. fehlende Rechte fürs Anlegen der
 * Tabelle), wird nur geloggt - die eigentliche Änderung darf daran nicht
 * scheitern.
 *
 * @param array<int, array<string, mixed>> $changes siehe audit_diff()
 */
function audit_log(string $objectType, string $objectId, string $action, array $changes = []): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $params = [
        gmdate('Y-m-d H:i:s'),
        $user !== null ? (int) $user['id'] : null,
        $user !== null ? (string) $user['username'] : null,
        $objectType,
        $objectId,
        $action,
        $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $insert = static function () use ($params): void {
        db()->prepare(
            'INSERT INTO config_audit (created_at, user_id, username, object_type, object_id, action, changes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute($params);
    };
    try {
        $insert();
    } catch (PDOException $e) {
        try {
            db()->exec(AUDIT_DDL);
            $insert();
        } catch (PDOException $e2) {
            error_log('audit_log: Eintrag nicht gespeichert (' . $e2->getMessage() . ')');
        }
    }
}

/**
 * Geräte-Config in eine vergleichbare Form bringen: Ziel-SSIDs nach SSID
 * statt nach Position (sonst meldet das Löschen der ersten SSID lauter
 * Änderungen an allen folgenden).
 */
function audit_device_view(array $config): array
{
    $targets = [];
    foreach ((array) ($config['connection_tests']['targets'] ?? []) as $t) {
        if (is_array($t)) {
            $targets['SSID „' . ($t['ssid'] ?? '?') . '“'] = $t;
        }
    }
    if (isset($config['connection_tests'])) {
        $config['connection_tests']['targets'] = $targets;
    }
    return $config;
}

/** Verschachteltes Array zu "a.b.c" => Wert (Listen von Werten als Text). */
function audit_flatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
            $out += audit_flatten($v, $key);
        } elseif (is_array($v)) {
            $out[$key] = implode(', ', array_map('strval', $v));
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}

/** Wert fürs Protokoll: bool/null lesbar, lange Texte gekürzt. */
function audit_value($v): string
{
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if ($v === null || $v === '') {
        return '–';
    }
    $s = str_replace(["\r", "\n"], ' ', (string) $v);
    // UTF-8-sicher kürzen ohne mbstring (optionale Extension, siehe Alerting.php).
    if (preg_match('/^(.{' . (AUDIT_MAX_VALUE - 1) . '}).+/us', $s, $m)) {
        return $m[1] . '…';
    }
    return $s;
}

/** Assoziatives (nicht leeres) Array, also ein Block mit Unterfeldern statt einer Werteliste. */
function audit_is_block($v): bool
{
    return is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1);
}

/**
 * Unterschiede zwischen $old und $new (beliebig verschachtelt).
 * Normale Felder: ['field' => .., 'old' => .., 'new' => ..];
 * geheime Felder: ['field' => .., 'secret' => 'set'|'changed'|'removed'];
 * ganzer Block neu/weg (z.B. eine Ziel-SSID): ['field' => .., 'block' => 'added'|'removed'].
 * Werte gelten als gleich, wenn sie gleich angezeigt werden (0 und 0.0,
 * leer und fehlend).
 *
 * @return array<int, array<string, string>>
 */
function audit_diff(array $old, array $new, string $prefix = ''): array
{
    $changes = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
        $field = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        $inOld = array_key_exists($k, $old);
        $inNew = array_key_exists($k, $new);
        $va = $old[$k] ?? null;
        $vb = $new[$k] ?? null;
        if (audit_is_block($va) && audit_is_block($vb)) {
            $changes = array_merge($changes, audit_diff($va, $vb, $field));
            continue;
        }
        if ((audit_is_block($va) && !$inNew) || (audit_is_block($vb) && !$inOld)) {
            $changes[] = ['field' => $field, 'block' => $inNew ? 'added' : 'removed'];
            continue;
        }
        if (in_array((string) $k, SECRET_FIELD_NAMES, true)) {
            $had = is_string($va) && $va !== '';
            $has = is_string($vb) && $vb !== '';
            if ($va !== $vb && ($had || $has)) {
                $changes[] = ['field' => $field, 'secret' => !$had ? 'set' : (!$has ? 'removed' : 'changed')];
            }
            continue;
        }
        $shownOld = is_array($va) ? audit_value(implode(', ', audit_flatten(['' => $va]))) : audit_value($va);
        $shownNew = is_array($vb) ? audit_value(implode(', ', audit_flatten(['' => $vb]))) : audit_value($vb);
        if ($shownOld !== $shownNew) {
            $changes[] = ['field' => $field, 'old' => $shownOld, 'new' => $shownNew];
        }
    }
    return $changes;
}

/**
 * Letzte Einträge, optional nur für ein Objekt. Fehlt die Tabelle noch
 * (noch nie etwas protokolliert), ist die Liste leer.
 *
 * @return array<int, array<string, mixed>>
 */
function audit_list(?string $objectType = null, ?string $objectId = null, int $limit = 300): array
{
    $where = [];
    $params = [];
    if ($objectType !== null) {
        $where[] = 'object_type = ?';
        $params[] = $objectType;
    }
    if ($objectId !== null) {
        $where[] = 'object_id = ?';
        $params[] = $objectId;
    }
    try {
        $stmt = db()->prepare(
            'SELECT * FROM config_audit' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    foreach ($rows as &$row) {
        $row['changes'] = json_decode((string) ($row['changes'] ?? ''), true) ?: [];
    }
    return $rows;
}
