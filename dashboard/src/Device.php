<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Secrets.php';

/** site_name (aus sites, per LEFT JOIN) fuer Anzeige - site_id bleibt die massgebliche Zuordnung. */
function device_find(string $id): ?array
{
    $stmt = db()->prepare(
        'SELECT d.*, s.name AS site_name FROM devices d LEFT JOIN sites s ON s.id = d.site_id WHERE d.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array<string, mixed> Bricht mit 404 ab, wenn das Gerät nicht existiert. */
function device_find_or_404(string $id): array
{
    $device = device_find($id);
    if ($device === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        // Nur von der Weboberfläche aufgerufen (dort ist src/I18n.php geladen).
        echo '404 - ' . (function_exists('__') ? __('Gerät nicht gefunden') : 'Gerät nicht gefunden') . "\n";
        exit;
    }
    return $device;
}

function device_create(string $id, ?int $siteId, string $apiKeyHash): void
{
    $stmt = db()->prepare('INSERT INTO devices (id, site_id, api_key_hash) VALUES (?, ?, ?)');
    $stmt->execute([$id, $siteId, $apiKeyHash]);
}

function device_set_config(string $id, array $config): void
{
    // Zugangsdaten nur verschlüsselt ablegen (sofern ein Schlüssel eingerichtet
    // ist, siehe src/Secrets.php); schon verschlüsselte Werte bleiben unverändert.
    $config = device_config_map_secrets($config, 'secret_encrypt');
    $stmt = db()->prepare('UPDATE devices SET config = ? WHERE id = ?');
    $stmt->execute([json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
}

/** Zuordnung ändern (Admin-only, siehe require_role('admin') an der Route). */
function device_set_site(string $id, ?int $siteId): void
{
    $stmt = db()->prepare('UPDATE devices SET site_id = ? WHERE id = ?');
    $stmt->execute([$siteId, $id]);
}

function device_set_notes(string $id, string $notes): void
{
    $stmt = db()->prepare('UPDATE devices SET notes = ? WHERE id = ?');
    $stmt->execute([$notes, $id]);
}

function device_rotate_key(string $id, string $apiKeyHash): void
{
    $stmt = db()->prepare('UPDATE devices SET api_key_hash = ? WHERE id = ?');
    $stmt->execute([$apiKeyHash, $id]);
}

function device_delete(string $id): void
{
    $stmt = db()->prepare('DELETE FROM devices WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Der Probe-Client meldet seit dem Site-Umbau keinen Standort mehr -
 * last_seen_at und optional die vom Client mitgeschickte probe_version
 * (nur bei nicht-leerem Wert aktualisiert, damit ein aelterer Client ohne
 * VERSION-Datei eine schon bekannte Version nicht mit NULL ueberschreibt).
 */
function device_touch_last_seen(string $id, ?string $probeVersion = null): void
{
    // Auch hier explizit selbst berechnetes UTC statt MySQLs NOW(),
    // aus demselben Grund wie in Measurement.php.
    if ($probeVersion !== null && $probeVersion !== '') {
        $stmt = db()->prepare('UPDATE devices SET last_seen_at = ?, probe_version = ? WHERE id = ?');
        $stmt->execute([utc_now(), $probeVersion, $id]);
        return;
    }
    $stmt = db()->prepare('UPDATE devices SET last_seen_at = ? WHERE id = ?');
    $stmt->execute([utc_now(), $id]);
}

/**
 * Vom Probe-Client gemeldeter Auto-Update-Stand (an/aus, Branch, Repo-URL,
 * Checkout-Pfad) in devices.auto_update speichern - nur die bekannten
 * Felder, mit festen Typen und Laengen, da der Inhalt vom Geraet kommt.
 * Fehlt die Spalte noch (Schema-Update aus schema.sql nicht eingespielt),
 * wird das nur geloggt: die Messwert-Annahme darf daran nie scheitern.
 */
function device_store_auto_update(string $id, array $info): void
{
    $str = fn(string $key, int $max): ?string => is_string($info[$key] ?? null) ? substr($info[$key], 0, $max) : null;
    // Ergebnis des letzten update_probe.py-Laufs (ab Probe 1.0.1.3).
    $result = in_array($info['last_result'] ?? null, ['current', 'updated', 'disabled', 'error'], true)
        ? $info['last_result'] : null;
    $clean = [
        'enabled' => ($info['enabled'] ?? null) === true,
        'branch' => $str('branch', 100),
        'repo_url' => $str('repo_url', 300),
        'repo_dir' => $str('repo_dir', 300),
        'commit' => $str('commit', 12),
        'last_run_at' => $str('last_run_at', 40),
        'last_result' => $result,
        'last_message' => $str('last_message', 300),
        // Fehlschläge in Folge (Probe ab 1.0.1.12), null bei älteren Probes.
        'fail_count' => is_int($info['fail_count'] ?? null) ? max(0, min($info['fail_count'], 100000)) : null,
        'reported_at' => utc_now(),
    ];
    try {
        $stmt = db()->prepare('UPDATE devices SET auto_update = ? WHERE id = ?');
        $stmt->execute([json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
    } catch (PDOException $e) {
        error_log('device_store_auto_update: ' . $e->getMessage() . ' (Spalte devices.auto_update angelegt? siehe schema.sql)');
    }
}

/**
 * Schwere eines fehlgeschlagenen Update-Laufs für die Anzeige: null = kein
 * Fehler, 'hint' = einzelner Fehlschlag (z.B. Netz kurz weg, grau), 'error'
 * = mindestens zwei in Folge (rot). Ältere Probes ohne fail_count melden
 * jeden Fehler als 'error', wie bisher.
 */
function auto_update_failure_level(?array $au): ?string
{
    if (!is_array($au) || empty($au['enabled']) || ($au['last_result'] ?? null) !== 'error') {
        return null;
    }
    $count = $au['fail_count'] ?? null;
    return ($count === 1) ? 'hint' : 'error';
}

/**
 * Anzeigetext für eine Repo-URL: GitHub-URLs (SSH oder HTTPS) als
 * "owner/repo", alles andere unverändert.
 */
function format_repo_url(?string $url): string
{
    if ($url === null || $url === '') {
        return '–';
    }
    if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#', $url, $m)) {
        return $m[1];
    }
    return $url;
}

/** @return array<int, array<string, mixed>> Inkl. site_name, nach Standort/ID sortiert. */
function device_list(): array
{
    return db()->query(
        'SELECT d.*, s.name AS site_name FROM devices d LEFT JOIN sites s ON s.id = d.site_id ORDER BY s.name, d.id'
    )->fetchAll();
}
