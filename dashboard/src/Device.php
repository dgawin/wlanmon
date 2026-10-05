<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Secrets.php';
require_once __DIR__ . '/Capture.php';

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
    // Mitschnitte haengen nicht per Fremdschluessel am Geraet (Tabelle entsteht erst spaeter).
    captures_delete_for_device($id);
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

/** Hoechstens so oft (Sekunden) landet ein Heartbeat zusaetzlich als Verlaufspunkt (kind "health"). */
const HEALTH_SAMPLE_SECONDS = 300;

/** Auswahl fuer das Heartbeat-Intervall in der Geraete-Konfiguration (Sekunden). */
const HEARTBEAT_INTERVALS = [15, 30, 60, 120, 300, 600];

/**
 * Systemwerte aus einem Heartbeat auf bekannte Felder mit festen Typen und
 * plausiblen Grenzen reduzieren - der Inhalt kommt vom Geraet.
 *
 * @return array<string, mixed>
 */
function device_health_clean(array $h): array
{
    $num = static function ($v, float $min, float $max): ?float {
        return is_int($v) || is_float($v) ? max($min, min($max, (float) $v)) : null;
    };
    $clean = [
        'cpu_percent' => $num($h['cpu_percent'] ?? null, 0, 100),
        'cpu_count' => isset($h['cpu_count']) && is_int($h['cpu_count']) ? max(1, min(1024, $h['cpu_count'])) : null,
        'mem_total_mb' => $num($h['mem_total_mb'] ?? null, 0, 1e7),
        'mem_available_mb' => $num($h['mem_available_mb'] ?? null, 0, 1e7),
        'mem_used_percent' => $num($h['mem_used_percent'] ?? null, 0, 100),
        'disk_total_mb' => $num($h['disk_total_mb'] ?? null, 0, 1e9),
        'disk_free_mb' => $num($h['disk_free_mb'] ?? null, 0, 1e9),
        'disk_used_percent' => $num($h['disk_used_percent'] ?? null, 0, 100),
        'temperature_c' => $num($h['temperature_c'] ?? null, -40, 150),
        'uptime_seconds' => $num($h['uptime_seconds'] ?? null, 0, 1e10),
        'queue_unsent' => $num($h['queue_unsent'] ?? null, 0, 1e9),
        'throttled' => isset($h['throttled']) && is_int($h['throttled']) ? max(0, min(0xFFFFFFFF, $h['throttled'])) : null,
    ];
    // Stromversorgung (Probe ab 1.0.1.55): HAT-Name aus dem Device-Tree, PoE-Stromquelle.
    if (is_string($h['hat'] ?? null)) {
        $hat = trim((string) preg_replace('/[^\x20-\x7E]/', '', $h['hat']));
        $clean['hat'] = $hat !== '' ? substr($hat, 0, 80) : null;
    }
    $clean['power_source'] = in_array($h['power_source'] ?? null, ['poe_hat'], true) ? $h['power_source'] : null;
    $clean['poe_online'] = is_bool($h['poe_online'] ?? null) ? $h['poe_online'] : null;
    // Raspberry Pi 5 (Probe ab 1.0.1.56): 5-V-Spannung, Strom laut Quelle, USB-Strombegrenzung aufgehoben?
    $clean['ext5v_volts'] = $num($h['ext5v_volts'] ?? null, 0, 10);
    $clean['psu_max_current_ma'] = isset($h['psu_max_current_ma']) && is_int($h['psu_max_current_ma'])
        ? max(0, min(20000, $h['psu_max_current_ma'])) : null;
    $clean['usb_max_current_enable'] = is_bool($h['usb_max_current_enable'] ?? null) ? $h['usb_max_current_enable'] : null;
    $clean['wifi_usb'] = is_bool($h['wifi_usb'] ?? null) ? $h['wifi_usb'] : null;
    if (is_array($h['load'] ?? null)) {
        $load = array_values(array_filter(array_slice($h['load'], 0, 3), fn($v) => is_int($v) || is_float($v)));
        $clean['load'] = count($load) === 3 ? array_map(fn($v) => max(0.0, min(1e4, (float) $v)), $load) : null;
    }
    return array_filter($clean, fn($v) => $v !== null);
}

/**
 * Systemwerte und Zeitpunkt des letzten Heartbeats speichern. Fehlen die
 * Spalten (aeltere Installation ohne neues schema.sql), einmalig anlegen und
 * erneut schreiben - wie site_alerting_set() bei neuen Spalten.
 */
function device_store_health(string $id, array $health): void
{
    $write = static function () use ($id, $health): void {
        db()->prepare('UPDATE devices SET health = ?, heartbeat_at = ? WHERE id = ?')
            ->execute([json_encode($health, JSON_UNESCAPED_SLASHES), utc_now(), $id]);
    };
    try {
        $write();
    } catch (PDOException $e) {
        foreach (['health' => 'LONGTEXT NULL', 'heartbeat_at' => 'DATETIME NULL'] as $column => $definition) {
            try {
                db()->query("SELECT $column FROM devices LIMIT 0");
            } catch (PDOException $missing) {
                db()->exec("ALTER TABLE devices ADD COLUMN $column $definition");
            }
        }
        $write();
    }
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
