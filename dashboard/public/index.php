<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/admin_auth.php';
require_once __DIR__ . '/../src/zabbix_auth.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Device.php';
require_once __DIR__ . '/../src/Capture.php';
require_once __DIR__ . '/../src/Measurement.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Alerting.php';
require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/User.php';
require_once __DIR__ . '/../src/Site.php';
require_once __DIR__ . '/../src/Cirrus.php';
require_once __DIR__ . '/../src/Timeline.php';
require_once __DIR__ . '/../src/Retention.php';
require_once __DIR__ . '/../src/Audit.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/ProbeError.php';

session_boot();

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'), '/');
if ($path === '') {
    $path = '/';
}

try {
    // ---- CSRF-Schutz fuer die Web-Oberflaeche (Session-Cookie) -----------
    // Zentral statt an jeder einzelnen Route: /api/v1/* nutzt Bearer-/
    // Basic-Auth statt Session-Cookies und ist von klassischem CSRF gar
    // nicht betroffen, siehe src/Session.php.
    if ($method === 'POST' && strpos($path, '/api/v1/') !== 0) {
        require_csrf();
    }

    // ---- Client-facing: Messwerte empfangen (Gegenstück zu sender.py) ----
    if ($method === 'POST' && $path === '/api/v1/measurements') {
        handle_ingest_measurements();
        exit;
    }

    // ---- Client-facing: Mitschnitt eines fehlgeschlagenen Tests (sender.py) ----
    if ($method === 'POST' && preg_match('#^/api/v1/devices/([^/]+)/captures/([0-9a-f]{32})$#', $path, $m)) {
        handle_upload_capture(rawurldecode($m[1]), $m[2]);
        exit;
    }

    // ---- Client-facing: Heartbeat mit Systemwerten (sender.py, Probe ab 1.0.1.54) ----
    if ($method === 'POST' && preg_match('#^/api/v1/devices/([^/]+)/heartbeat$#', $path, $m)) {
        handle_heartbeat($m[1]);
        exit;
    }

    // ---- Client-facing: zentrale Config abrufen (config_manager.py) ----
    if ($method === 'GET' && preg_match('#^/api/v1/devices/([^/]+)/config$#', $path, $m)) {
        handle_get_device_config($m[1]);
        exit;
    }

    // ---- Admin: Geräte anlegen/konfigurieren/löschen ----
    if ($method === 'POST' && $path === '/api/v1/admin/devices') {
        require_admin();
        handle_create_device();
        exit;
    }
    if ($method === 'PUT' && preg_match('#^/api/v1/admin/devices/([^/]+)/config$#', $path, $m)) {
        require_admin();
        handle_set_device_config($m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/api/v1/admin/devices/([^/]+)/rotate-key$#', $path, $m)) {
        require_admin();
        handle_rotate_key($m[1]);
        exit;
    }
    if ($method === 'DELETE' && preg_match('#^/api/v1/admin/devices/([^/]+)$#', $path, $m)) {
        require_admin();
        handle_delete_device($m[1]);
        exit;
    }

    // ---- Zabbix: schreibgeschützte Discovery/Status-Endpunkte ----
    if ($method === 'GET' && $path === '/api/v1/zabbix/discovery/devices') {
        handle_zabbix_discover_devices();
        exit;
    }
    if ($method === 'GET' && preg_match('#^/api/v1/zabbix/devices/([^/]+)/status$#', $path, $m)) {
        handle_zabbix_device_status($m[1]);
        exit;
    }

    // ---- Login/Logout (siehe src/Session.php) ----
    if ($method === 'GET' && $path === '/login') {
        render_login();
        exit;
    }
    if ($method === 'POST' && $path === '/login') {
        handle_login();
        exit;
    }
    if ($method === 'POST' && $path === '/logout') {
        logout();
        header('Location: /login');
        exit;
    }
    if ($method === 'GET' && preg_match('#^/invite/([0-9a-f]{64})$#', $path, $m)) {
        render_invite($m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/invite/([0-9a-f]{64})$#', $path, $m)) {
        handle_invite_submit($m[1]);
        exit;
    }
    // Sprache wählen (Benutzermenü bzw. Login-Seite), zurück zur vorigen Seite.
    if ($method === 'POST' && $path === '/account/language') {
        lang_set((string) ($_POST['lang'] ?? ''));
        header('Location: ' . safe_local_redirect_target((string) ($_POST['back'] ?? '/')));
        exit;
    }
    if ($method === 'GET' && $path === '/about') {
        render_about();
        exit;
    }
    if ($method === 'GET' && $path === '/account/password') {
        require_login();
        render_account_password();
        exit;
    }
    if ($method === 'POST' && $path === '/account/password') {
        require_login();
        handle_change_password();
        exit;
    }

    // ---- Dashboard (HTML) ----
    if ($method === 'GET' && $path === '/') {
        $user = require_login();
        render_dashboard($user);
        exit;
    }
    if ($method === 'GET' && $path === '/devices/new') {
        require_role('admin');
        render_device_new_form();
        exit;
    }
    if ($method === 'POST' && $path === '/devices/new') {
        require_role('admin');
        handle_create_device_via_form();
        exit;
    }
    if ($method === 'GET' && preg_match('#^/devices/([^/]+)$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        require_device_access($device);
        render_device_detail($device);
        exit;
    }
    if ($method === 'GET' && preg_match('#^/devices/([^/]+)/captures/([0-9a-f]{32})\.(pcap|txt|log)$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        // Enthaelt MACs und ggf. 802.1X-Identitaeten - nicht fuer Viewer.
        require_write_access(require_device_access($device));
        handle_download_capture($device['id'], $m[2], $m[3]);
        exit;
    }
    if ($method === 'GET' && preg_match('#^/devices/([^/]+)/config$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        $user = require_device_access($device);
        require_write_access($user);
        render_device_config($device);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/config$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        $user = require_device_access($device);
        require_write_access($user);
        handle_set_device_config_via_form($device['id']);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/delete$#', $path, $m)) {
        require_role('admin');
        handle_delete_device_via_form($m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/rotate-key$#', $path, $m)) {
        require_role('admin');
        handle_rotate_key_via_form($m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/measurements/delete-all$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        $user = require_device_access($device);
        require_write_access($user);
        handle_delete_all_measurements_via_form($device['id']);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/measurements/delete-for-ssid$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        $user = require_device_access($device);
        require_write_access($user);
        handle_delete_measurements_for_ssid($device['id']);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/measurements/delete-many$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        $user = require_device_access($device);
        require_write_access($user);
        handle_delete_measurements_many($device['id']);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/devices/([^/]+)/measurements/(\d+)/delete$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        $user = require_device_access($device);
        require_write_access($user);
        handle_delete_measurement_via_form($device['id'], (int) $m[2]);
        exit;
    }
    // Daten für den Tab "Verlauf" (JSON, nachgeladen von static/timeline.js),
    // damit die Geräteseite selbst nicht bei jedem Aufruf Tausende Scans auswertet.
    if ($method === 'GET' && preg_match('#^/devices/([^/]+)/timeline$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        require_device_access($device);
        [$from, $to, $hours, $rangeError] = timeline_range_from_query($_GET);
        if ($rangeError !== null) {
            json_error(400, $rangeError);
            exit;
        }
        json_response(timeline_build($device, $from, $to, $hours));
    }
    if ($method === 'GET' && preg_match('#^/devices/([^/]+)/measurements/(\d+)/raw$#', $path, $m)) {
        $device = device_find_or_404($m[1]);
        require_device_access($device);
        render_measurement_raw($device['id'], (int) $m[2]);
        exit;
    }

    // ---- Standorte: Liste/Anlegen/Umbenennen/Löschen nur Admin, ----
    // ---- Bemerkung + Alerting auch für 'user' (nur eigene Sites)  ----
    if ($method === 'GET' && $path === '/access-points') {
        require_login();
        render_access_points();
        exit;
    }
    if ($method === 'GET' && $path === '/sites') {
        require_role('admin', 'user');
        render_sites();
        exit;
    }
    if ($method === 'POST' && $path === '/sites/new') {
        require_role('admin');
        handle_create_site();
        exit;
    }
    if ($method === 'POST' && preg_match('#^/sites/(\d+)/rename$#', $path, $m)) {
        require_role('admin');
        handle_rename_site((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/sites/(\d+)/notes$#', $path, $m)) {
        require_site_access((int) $m[1]);
        handle_set_site_notes((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/sites/(\d+)/delete$#', $path, $m)) {
        require_role('admin');
        handle_delete_site((int) $m[1]);
        exit;
    }
    if ($method === 'GET' && preg_match('#^/sites/(\d+)/alerting$#', $path, $m)) {
        require_site_access((int) $m[1]);
        render_site_alerting((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/sites/(\d+)/alerting$#', $path, $m)) {
        require_site_access((int) $m[1]);
        handle_set_site_alerting((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/sites/(\d+)/alerting/test$#', $path, $m)) {
        require_site_access((int) $m[1]);
        handle_test_site_alerting((int) $m[1]);
        exit;
    }

    // ---- Benutzer (nur Admin) ----
    if ($method === 'GET' && $path === '/users') {
        require_role('admin');
        render_users();
        exit;
    }
    if ($method === 'POST' && $path === '/users/new') {
        require_role('admin');
        handle_create_user();
        exit;
    }
    if ($method === 'GET' && preg_match('#^/users/(\d+)/edit$#', $path, $m)) {
        require_role('admin');
        render_user_edit((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/users/(\d+)/edit$#', $path, $m)) {
        require_role('admin');
        handle_user_edit((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/users/(\d+)/delete$#', $path, $m)) {
        require_role('admin');
        handle_delete_user((int) $m[1]);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/users/(\d+)/(disable|enable)$#', $path, $m)) {
        require_role('admin');
        handle_set_user_disabled((int) $m[1], $m[2] === 'disable');
        exit;
    }
    if ($method === 'POST' && preg_match('#^/users/(\d+)/resend-invite$#', $path, $m)) {
        require_role('admin');
        handle_resend_invite((int) $m[1]);
        exit;
    }

    // ---- Server-weite Einstellungen: nur die gemeinsamen SMTP-/Telegram- ----
    // ---- Zugangsdaten (nur Admin) - Ein/Aus/Empfänger/Zeitfenster je    ----
    // ---- Site siehe /sites/<id>/alerting oben.                        ----
    if ($method === 'GET' && $path === '/settings/alerting') {
        require_role('admin');
        render_settings_alerting();
        exit;
    }
    if ($method === 'POST' && $path === '/settings/alerting') {
        require_role('admin');
        handle_set_settings_alerting();
        exit;
    }
    // ---- Änderungsprotokoll (nur Admin) und Verschlüsselung der Zugangsdaten ----
    if ($method === 'GET' && $path === '/settings/audit') {
        require_role('admin');
        $auditEntries = audit_list(null, null, 300);
        require __DIR__ . '/../src/templates/settings_audit.php';
        exit;
    }
    if ($method === 'POST' && $path === '/settings/secrets/encrypt') {
        require_role('admin');
        $count = secrets_encrypt_all();
        if ($count > 0) {
            audit_log('system', 'secrets', 'encrypted', [['field' => 'secrets', 'old' => (string) $count, 'new' => 'enc:v1']]);
        }
        header('Location: /settings/retention?encrypted=' . $count);
        exit;
    }
    // ---- Datenhaltung: Aufbewahrung, Stand von Aufräumen und Backup (nur Admin) ----
    if ($method === 'GET' && $path === '/settings/retention') {
        require_role('admin');
        render_settings_retention();
        exit;
    }
    if ($method === 'POST' && $path === '/settings/retention') {
        require_role('admin');
        handle_set_settings_retention();
        exit;
    }

    json_error(404, 'Nicht gefunden');
} catch (Throwable $e) {
    error_log('[wlanmon] ' . $e->getMessage());
    json_error(500, 'Interner Serverfehler');
}

// ============================================================
// Handler-Funktionen
// ============================================================

/**
 * Mitschnitt eines fehlgeschlagenen Connection-Tests annehmen (multipart:
 * "events" Text, "pcap" optional). Authentifiziert wie die Messwerte mit dem
 * API-Key des Geraets; die capture_id steht schon im Testergebnis.
 */
function handle_upload_capture(string $deviceId, string $captureId): void
{
    $token = extract_bearer_token();
    if ($token === null) {
        json_error(401, "Authorization-Header muss 'Bearer <token>' sein");
    }
    $device = device_find($deviceId);
    if ($device === null || !verify_api_key($token, $device['api_key_hash'])) {
        json_error(401, 'Unbekanntes Gerät oder ungültiger API-Key');
    }
    // Groesser als post_max_size: PHP verwirft den Body still, $_FILES bleibt leer.
    if ($_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        json_error(413, 'Mitschnitt zu groß für die PHP-Einstellungen (post_max_size)');
    }
    $read = static function (string $field, int $max): ?string {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > $max) {
            json_error(413, "$field zu groß");
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            json_error(400, "$field: Upload fehlgeschlagen");
        }
        return (string) file_get_contents($file['tmp_name']);
    };
    $events = $read('events', CAPTURE_MAX_EVENTS_BYTES) ?? '';
    $pcap = $read('pcap', CAPTURE_MAX_PCAP_BYTES);
    // Log von wpa_supplicant, Probe ab 1.0.1.38 (aeltere schicken es nicht).
    $wpaLog = $read('wpa_log', CAPTURE_MAX_WPA_LOG_BYTES);
    if ($pcap !== null && !capture_is_pcap($pcap)) {
        json_error(400, 'pcap: keine pcap-/pcapng-Datei');
    }
    if ($pcap === null && trim($events) === '' && trim((string) $wpaLog) === '') {
        json_error(400, 'Leerer Mitschnitt');
    }
    capture_store($captureId, $deviceId, $pcap, $events, $wpaLog);
    json_response(['ok' => true], 201);
}

function handle_download_capture(string $deviceId, string $captureId, string $type): void
{
    // pcap = Mitschnitt, txt = iw-Ereignisse, log = wpa_supplicant-Log
    $columns = ['pcap' => 'pcap', 'txt' => 'events', 'log' => 'wpa_log'];
    $suffixes = ['pcap' => '.pcap', 'txt' => '-events.txt', 'log' => '-wpa_supplicant.txt'];
    $capture = capture_find($captureId, $deviceId);
    $content = $capture === null ? null : ($capture[$columns[$type]] ?? null);
    if (is_resource($content)) {
        $content = stream_get_contents($content);
    }
    if ($content === null || $content === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Mitschnitt nicht gefunden (evtl. nach Ablauf der Aufbewahrung gelöscht)') . "\n";
        exit;
    }
    $stamp = gmdate('Ymd-His', strtotime((string) $capture['created_at'] . ' UTC') ?: time());
    $name = 'wlanmon-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $deviceId) . "-$stamp" . $suffixes[$type];
    header('Content-Type: ' . ($type === 'pcap' ? 'application/vnd.tcpdump.pcap' : 'text/plain; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen((string) $content));
    echo $content;
}

function handle_ingest_measurements(): void
{
    $token = extract_bearer_token();
    if ($token === null) {
        json_error(401, "Authorization-Header muss 'Bearer <token>' sein");
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > INGEST_MAX_BODY_BYTES) {
        json_error(413, sprintf('Request zu gross (max. %d MB)', INGEST_MAX_BODY_BYTES / 1024 / 1024));
    }

    $body = read_json_body();
    $deviceId = $body['device_id'] ?? null;
    // "site" wird nicht mehr ausgewertet (Standort-Zuordnung laeuft ueber
    // devices.site_id, Admin-verwaltet im Dashboard) - ein aelterer
    // Probe-Client, der das Feld noch mitschickt, stoert dadurch nicht.
    $probeVersion = $body['probe_version'] ?? null;
    $measurements = $body['measurements'] ?? null;

    if (!is_string($deviceId) || $deviceId === '' || !is_array($measurements)) {
        json_error(400, 'device_id und measurements sind erforderlich');
    }

    $device = device_find($deviceId);
    if ($device === null || !verify_api_key($token, $device['api_key_hash'])) {
        json_error(401, 'Unbekanntes Gerät oder ungültiger API-Key');
    }
    if (count($measurements) > INGEST_MAX_BATCH) {
        json_error(413, sprintf('Zu viele Messungen in einem Request (max. %d, batch_size verkleinern)', INGEST_MAX_BATCH));
    }
    if (measurement_count_recent($deviceId, INGEST_RATE_WINDOW_SECONDS) + count($measurements) > INGEST_RATE_MAX_MEASUREMENTS) {
        header('Retry-After: 60');
        json_error(429, sprintf('Zu viele Messungen (max. %d in %d Minuten), später erneut senden',
            INGEST_RATE_MAX_MEASUREMENTS, INGEST_RATE_WINDOW_SECONDS / 60));
    }

    $accepted = 0;
    foreach ($measurements as $m) {
        $kind = $m['kind'] ?? null;
        $data = $m['data'] ?? null;
        if (!in_array($kind, ['scan', 'connection_test', 'lan_test'], true) || !is_array($data)) {
            // Einzelnen kaputten Eintrag überspringen statt den ganzen
            // Batch zu verwerfen - der Client würde ihn sonst endlos
            // erneut versuchen.
            continue;
        }
        $clientTimestamp = null;
        if (!empty($data['timestamp'])) {
            // Explizite DateTime-Konvertierung nach UTC statt
            // strtotime()+date() - Letzteres würde die PHP-Default-
            // Zeitzone des Servers zur Formatierung heranziehen, was zu
            // falschen gespeicherten Zeiten führen kann, wenn diese
            // nicht UTC ist. Der Client sendet den Zeitstempel mit
            // explizitem Offset (z.B. "+00:00"), das parsen wir hier
            // zeitzonenunabhängig korrekt.
            try {
                $dt = new DateTime((string) $data['timestamp']);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $clientTimestamp = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $clientTimestamp = null;
            }
        }
        measurement_insert($deviceId, $kind, $clientTimestamp, $data);
        $accepted++;
    }

    device_touch_last_seen($deviceId, is_string($probeVersion) ? $probeVersion : null);
    // Ab Probe 1.0.1.2 mitgeschickt (an/aus, Branch; Repo und letzter Lauf ab 1.0.1.3) - aeltere Clients
    // senden es nicht, dann bleibt der zuletzt bekannte Stand stehen.
    if (is_array($body['auto_update'] ?? null)) {
        device_store_auto_update($deviceId, $body['auto_update']);
    }

    json_response(['accepted' => $accepted], 201);
}

/**
 * Lebenszeichen einer Probe: aktualisiert "zuletzt gesehen" auch ohne
 * Messdaten, speichert die Systemwerte am Geraet und hoechstens alle
 * HEALTH_SAMPLE_SECONDS zusaetzlich einen Verlaufspunkt. Die Antwort traegt
 * eine Kennung der zentralen Config - aendert sie sich, holt die Probe die
 * Config sofort statt beim naechsten Poll.
 */
function handle_heartbeat(string $deviceId): void
{
    $token = extract_bearer_token();
    if ($token === null) {
        json_error(401, "Authorization-Header muss 'Bearer <token>' sein");
    }
    $device = device_find($deviceId);
    if ($device === null || !verify_api_key($token, $device['api_key_hash'])) {
        json_error(401, 'Unbekanntes Gerät oder ungültiger API-Key');
    }
    $body = read_json_body();
    $probeVersion = is_string($body['probe_version'] ?? null) ? substr($body['probe_version'], 0, 32) : null;
    device_touch_last_seen($deviceId, $probeVersion);

    $health = device_health_clean(is_array($body['health'] ?? null) ? $body['health'] : []);
    if ($health !== []) {
        device_store_health($deviceId, $health);
        $last = measurement_last($deviceId, 'health');
        $lastAt = $last !== null ? strtotime((string) $last['received_at'] . ' UTC') : 0;
        if (time() - $lastAt >= HEALTH_SAMPLE_SECONDS) {
            measurement_insert($deviceId, 'health', utc_now(), $health);
        }
    }
    json_response([
        'ok' => true,
        'config_version' => !empty($device['config']) ? substr(sha1((string) $device['config']), 0, 16) : 'none',
    ]);
}

function handle_get_device_config(string $deviceId): void
{
    $token = extract_bearer_token();
    if ($token === null) {
        json_error(401, "Authorization-Header muss 'Bearer <token>' sein");
    }

    $device = device_find($deviceId);
    if ($device === null || !verify_api_key($token, $device['api_key_hash'])) {
        json_error(401, 'Unbekanntes Gerät oder ungültiger API-Key');
    }

    if (empty($device['config'])) {
        // Der Client behandelt 404 als "keine zentrale Config vorhanden"
        // und bleibt bei seinen lokalen/gecachten Werten.
        json_error(404, 'Keine zentrale Konfiguration für dieses Gerät hinterlegt');
    }

    $failed = 0;
    $config = device_config_decrypt(json_decode((string) $device['config'], true) ?: [], $failed);
    if ($failed > 0) {
        // Lieber gar keine Config als leere PSKs: die Probe behält bei einem
        // Fehler ihren zuletzt gecachten Stand (config_manager.py).
        error_log("[wlanmon] Config für $deviceId: $failed Zugangsdaten nicht entschlüsselbar (secret_key fehlt oder falsch)");
        json_error(500, 'Zugangsdaten nicht entschlüsselbar - secret_key auf dem Server prüfen');
    }
    // Standortname nur zur Anzeige auf der Probe (Log, OLED) - die Zuordnung
    // selbst bleibt devices.site_id. Aeltere Probes verwerfen den Schluessel.
    json_response([
        'scan' => $config['scan'] ?? null,
        'connection_tests' => $config['connection_tests'] ?? null,
        'site' => $device['site_name'] ?? null,
        'heartbeat' => $config['heartbeat'] ?? null,
    ]);
}

function handle_create_device(): void
{
    $body = read_json_body();
    $deviceId = $body['device_id'] ?? null;
    // Optional: ID einer bestehenden Site (siehe /sites) - Zuordnung
    // lässt sich auch später über das Dashboard setzen/ändern.
    $siteId = isset($body['site_id']) ? (int) $body['site_id'] : null;

    if (!is_string($deviceId) || $deviceId === '') {
        json_error(400, 'device_id ist erforderlich');
    }
    if (device_find($deviceId) !== null) {
        json_error(409, 'Device-ID existiert bereits');
    }

    $apiKey = bin2hex(random_bytes(32));
    device_create($deviceId, $siteId, hash_api_key($apiKey));
    audit_log('device', $deviceId, 'created');

    // api_key nur hier im Klartext - sofort in die config.yaml des
    // Clients übernehmen, danach ist nur noch der Hash gespeichert.
    json_response(['device_id' => $deviceId, 'api_key' => $apiKey], 201);
}

function handle_set_device_config(string $deviceId): void
{
    $device = device_find($deviceId);
    if ($device === null) {
        json_error(404, 'Gerät nicht gefunden');
    }
    $oldConfig = json_decode((string) ($device['config'] ?? ''), true) ?: [];
    $body = read_json_body();
    $config = [];
    if (isset($body['scan'])) {
        $config['scan'] = $body['scan'];
    }
    if (isset($body['connection_tests'])) {
        $config['connection_tests'] = $body['connection_tests'];
    }
    if (is_array($body['heartbeat'] ?? null)) {
        // Wie im Formular: an/aus und ein Intervall aus der festen Auswahl.
        $interval = (int) ($body['heartbeat']['interval_seconds'] ?? 60);
        $config['heartbeat'] = [
            'enabled' => ($body['heartbeat']['enabled'] ?? true) !== false,
            'interval_seconds' => in_array($interval, HEARTBEAT_INTERVALS, true) ? $interval : 60,
        ];
    }
    audit_log('device', $deviceId, 'config', audit_diff(audit_device_view($oldConfig), audit_device_view($config)));
    device_set_config($deviceId, $config);
    json_response(['status' => 'ok']);
}

/**
 * Formular-Gegenstück zu handle_set_device_config() für die Dashboard-UI.
 * Baut dieselbe Config-Struktur aus $_POST statt aus JSON und leitet
 * danach zurück auf die Geräteseite (statt JSON-Antwort wie bei der API).
 */
function handle_set_device_config_via_form(string $deviceId): void
{
    // Lokal statt als Datei-Konstante: eine top-level const wird erst
    // ausgeführt, wenn der sequentielle Kontrollfluss an ihr vorbeikommt -
    // der Router oben in der Datei ruft diese Funktion aber auf, bevor er
    // dort ankommt, was zu "Undefined constant" führt.
    $allowedSecurity = ['open', 'wpa2-psk', 'wpa3-psk', 'wpa2-wpa3-psk', 'wpa2-eap'];

    $device = device_find($deviceId);
    if ($device === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    // Bisher gespeicherter Stand (Geheimnisse so wie gespeichert, ggf.
    // verschlüsselt): Quelle für leer gelassene Passwortfelder und fürs
    // Änderungsprotokoll.
    $oldConfig = json_decode((string) ($device['config'] ?? ''), true) ?: [];
    $oldTargets = array_values((array) ($oldConfig['connection_tests']['targets'] ?? []));

    $config = [
        'scan' => [
            'interval_seconds' => max(1, (int) ($_POST['scan_interval_seconds'] ?? 60)),
        ],
        // Heartbeat mit Systemwerten (Probe ab 1.0.1.54), Intervall aus fester Auswahl.
        'heartbeat' => [
            'enabled' => !empty($_POST['hb_enabled']),
            'interval_seconds' => in_array((int) ($_POST['hb_interval_seconds'] ?? 60), HEARTBEAT_INTERVALS, true)
                ? (int) $_POST['hb_interval_seconds'] : 60,
        ],
        'connection_tests' => [
            'interval_seconds' => max(1, (int) ($_POST['ct_interval_seconds'] ?? 900)),
            'connect_timeout_seconds' => max(1, (int) ($_POST['ct_connect_timeout_seconds'] ?? 30)),
            'ping_target' => trim((string) ($_POST['ct_ping_target'] ?? '')),
            'ping_count' => max(1, (int) ($_POST['ct_ping_count'] ?? 5)),
            'captive_portal_url' => trim((string) ($_POST['ct_captive_portal_url'] ?? ''))
                ?: 'http://connectivitycheck.gstatic.com/generate_204',
            // Nur der Fallback fuer SSIDs, die unten keinen eigenen
            // iperf3-Server/-Dauer eintragen (z.B. bei unterschiedlichen
            // VLANs je SSID) - siehe iperf3_server/iperf3_duration_seconds
            // je Ziel weiter unten.
            'iperf3_server' => trim((string) ($_POST['ct_iperf3_server'] ?? '')),
            'iperf3_duration_seconds' => max(1, (int) ($_POST['ct_iperf3_duration_seconds'] ?? 5)),
            'iperf3_port' => min(65535, max(1, (int) ($_POST['ct_iperf3_port'] ?? 5201))),
            // iperf3 je SSID höchstens alle N Minuten (0 = bei jedem Test), Probe ab 1.0.1.14.
            'iperf3_min_interval_minutes' => min(10080, max(0, (int) ($_POST['ct_iperf3_min_interval_minutes'] ?? 0))),
            // Mitschnitt fehlgeschlagener Tests (pcap + iw-Ereignisse), Probe ab 1.0.1.35.
            'capture_on_failure' => !empty($_POST['ct_capture_on_failure']),
            'iperf3_lan' => [
                'enabled' => !empty($_POST['lan_enabled']),
                'server' => trim((string) ($_POST['lan_server'] ?? '')),
                'interface' => trim((string) ($_POST['lan_interface'] ?? '')) ?: 'eth0',
                'duration_seconds' => max(1, (int) ($_POST['lan_duration_seconds'] ?? 5)),
                'port' => min(65535, max(1, (int) ($_POST['lan_port'] ?? 5201))),
                // Leer = allgemeiner iperf3-Abstand gilt (Probe ab 1.0.1.53).
                'min_interval_minutes' => trim((string) ($_POST['lan_min_interval_minutes'] ?? '')) === ''
                    ? null
                    : min(10080, max(0, (int) $_POST['lan_min_interval_minutes'])),
            ],
            'targets' => [],
        ],
    ];

    foreach ((array) ($_POST['targets'] ?? []) as $t) {
        if (!is_array($t)) {
            continue;
        }
        $ssid = trim((string) ($t['ssid'] ?? ''));
        if ($ssid === '') {
            continue;
        }
        $security = (string) ($t['security'] ?? 'wpa2-psk');
        if (!in_array($security, $allowedSecurity, true)) {
            $security = 'wpa2-psk';
        }
        $target = [
            'ssid' => $ssid,
            'security' => $security,
            'psk' => '',
            'captive_portal_check' => !empty($t['captive_portal_check']),
            // Leer = Default-Gateway des Netzes dieser SSID (siehe main.py/
            // wifi_ops.py); connection_tests.ping_target ist nur der
            // letzte Fallback, falls kein Gateway ermittelbar ist.
            'ping_target' => clean_line($t['ping_target'] ?? ''),
            'iperf3_enabled' => !empty($t['iperf3_enabled']),
            // Leer = Standardwert aus connection_tests.iperf3_server/-duration
            // verwenden (siehe main.py); nur bei abweichendem VLAN/Server
            // fuer diese SSID eintragen.
            'iperf3_server' => trim((string) ($t['iperf3_server'] ?? '')),
            'iperf3_duration_seconds' => (int) ($t['iperf3_duration_seconds'] ?? 0),
            'iperf3_port' => min(65535, max(0, (int) ($t['iperf3_port'] ?? 0))),
            'iperf3_download' => !empty($t['iperf3_download']),
            // Ratenbegrenzung in Mbit/s (iperf3 -b), 0 = ohne Begrenzung.
            'iperf3_bitrate_mbps' => min(10000.0, max(0.0, round((float) str_replace(',', '.', (string) ($t['iperf3_bitrate_mbps'] ?? 0)), 1))),
            'random_mac' => !empty($t['random_mac']),
            'captive_portal_login' => [
                'enabled' => !empty($t['portal_login']['enabled']),
                'type' => in_array((string) ($t['portal_login']['type'] ?? 'auto'), ['auto', 'cirrus', 'form'], true)
                    ? (string) $t['portal_login']['type']
                    : 'auto',
                'logoff' => !empty($t['portal_login']['logoff']),
                'username' => clean_line($t['portal_login']['username'] ?? ''),
                'password' => (string) ($t['portal_login']['password'] ?? ''),
            ],
        ];
        if ($security === 'wpa2-eap') {
            $target['eap'] = build_eap_config(is_array($t['eap'] ?? null) ? $t['eap'] : []);
        } elseif ($security !== 'open') {
            $target['psk'] = (string) ($t['psk'] ?? '');
        }
        // Passwortfelder zeigen gespeicherte Werte nie an: leer gelassen =
        // bisheriger Wert. Zuordnung über die ursprüngliche Position UND SSID
        // der Karte (_orig/_orig_ssid), damit nach Löschen/Umbenennen einer
        // anderen SSID kein Passwort beim falschen Ziel landet.
        $orig = isset($t['_orig']) && ctype_digit((string) $t['_orig']) ? (int) $t['_orig'] : null;
        $oldTarget = $orig !== null && isset($oldTargets[$orig])
            && is_array($oldTargets[$orig])
            && (string) ($oldTargets[$orig]['ssid'] ?? '') === (string) ($t['_orig_ssid'] ?? "\0")
            ? $oldTargets[$orig] : [];
        foreach (TARGET_SECRET_FORM_FIELDS as [$storedPath, $formPath]) {
            if (array_path_get($target, $storedPath) === null
                || ($storedPath === ['psk'] && in_array($security, ['open', 'wpa2-eap'], true))) {
                continue;  // Feld gehört nicht zur gewählten Sicherheit/EAP-Methode
            }
            $clearPath = $formPath;
            $clearPath[count($clearPath) - 1] .= '_clear';
            $stored = array_path_get($oldTarget, $storedPath);
            $target = array_path_set($target, $storedPath, secret_from_form(
                array_path_get($t, $formPath),
                !empty(array_path_get($t, $clearPath)),
                is_string($stored) ? $stored : null
            ));
        }
        $config['connection_tests']['targets'][] = $target;
    }

    // trim() statt clean_line(): die Bemerkung darf mehrzeilig sein.
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $old = audit_device_view($oldConfig) + ['notes' => (string) ($device['notes'] ?? ''), 'site' => (string) ($device['site_name'] ?? '')];
    $new = audit_device_view($config) + ['notes' => $notes, 'site' => $old['site']];

    device_set_config($deviceId, $config);
    device_set_notes($deviceId, $notes);
    // Standort-Zuordnung ändern bleibt Admin-only, auch wenn 'user' diese
    // Seite fürs übrige Formular schreiben darf (siehe require_write_access()
    // an der Route) - ein User könnte sich sonst selbst ein Gerät aus
    // seinem Standort heraus "wegzuordnen".
    if ((current_user()['role'] ?? null) === 'admin') {
        $siteId = ($_POST['site_id'] ?? '') !== '' ? (int) $_POST['site_id'] : null;
        device_set_site($deviceId, $siteId);
        $site = $siteId !== null ? site_find($siteId) : null;
        $new['site'] = (string) ($site['name'] ?? '');
    }
    $changes = audit_diff($old, $new);
    if ($changes !== []) {
        audit_log('device', $deviceId, 'config', $changes);
    }
    header('Location: /devices/' . rawurlencode($deviceId) . '/config?saved=1');
    exit;
}

/** Einzeilige Eingabe: Zeilenumbrüche entfernen, trimmen. */
function clean_line($value): string
{
    return trim(str_replace(["\r", "\n"], '', (string) $value));
}

/**
 * PEM-Text aus einem Formular-Textarea: CRLF normalisieren, trimmen, auf
 * eine sinnvolle Größe begrenzen (ein Zertifikat/Schlüssel hat wenige KB).
 */
function clean_pem($value): string
{
    $pem = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    return strlen($pem) > 20000 ? '' : $pem;
}

/**
 * Baut den "eap"-Block eines Ziels aus den Formulardaten und behält nur die
 * zur gewählten Methode passenden Felder (kein Rest von einer früheren
 * Methode in der gespeicherten Config).
 */
function build_eap_config(array $e): array
{
    $method = strtolower(clean_line($e['method'] ?? 'peap'));
    if (!in_array($method, ['peap', 'ttls', 'tls'], true)) {
        $method = 'peap';
    }
    $eap = [
        'method' => $method,
        'identity' => clean_line($e['identity'] ?? ''),
        'anonymous_identity' => clean_line($e['anonymous_identity'] ?? ''),
        'verify_server' => !empty($e['verify_server']),
        'ca_cert' => clean_pem($e['ca_cert'] ?? ''),
        'server_name' => clean_line($e['server_name'] ?? ''),
    ];
    if ($method === 'tls') {
        $eap['client_cert'] = clean_pem($e['client_cert'] ?? '');
        $eap['private_key'] = clean_pem($e['private_key'] ?? '');
        $eap['private_key_password'] = (string) ($e['private_key_password'] ?? '');
    } else {
        $eap['password'] = (string) ($e['password'] ?? '');
        if ($method === 'ttls') {
            $phase2 = strtoupper(clean_line($e['phase2'] ?? 'PAP'));
            $eap['phase2'] = in_array($phase2, ['PAP', 'MSCHAPV2', 'MSCHAP', 'CHAP'], true) ? $phase2 : 'PAP';
        } else {
            $eap['phase2'] = 'MSCHAPV2';
        }
    }
    return $eap;
}

/** Formular-Gegenstück zu handle_delete_device() (DELETE-API) - normale
 * HTML-Formulare kennen kein DELETE, daher eigene POST-Route. */
function handle_delete_device_via_form(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    device_delete($deviceId);
    audit_log('device', $deviceId, 'deleted');
    header('Location: /?deleted=' . rawurlencode($deviceId));
    exit;
}

/**
 * Web-Formular-Gegenstück zu handle_rotate_key() (API) - erzeugt einen
 * neuen API-Key und zeigt ihn einmalig an, gleiches Template/Muster wie
 * beim Anlegen eines Geräts (device_created.php erwartet $deviceId +
 * $apiKey, wird hier 1:1 wiederverwendet statt eines eigenen Templates).
 */
function handle_rotate_key_via_form(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    $apiKey = bin2hex(random_bytes(32));
    device_rotate_key($deviceId, hash_api_key($apiKey));
    audit_log('device', $deviceId, 'api_key_rotated');
    $rotated = true;
    require __DIR__ . '/../src/templates/device_created.php';
}

function handle_delete_measurement_via_form(string $deviceId, int $measurementId): void
{
    if (device_find($deviceId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    measurement_delete($measurementId, $deviceId);
    header('Location: /devices/' . rawurlencode($deviceId));
    exit;
}

/**
 * Rohdaten einer einzelnen Messung als JSON, zum Nachvollziehen, was der
 * Client tatsächlich gesendet/die Datenbank tatsächlich gespeichert hat -
 * ohne SSH+mysql-Konsole. "data" wird bewusst aus dem gespeicherten
 * JSON-Text neu decodiert (nicht über eine der Anzeige-Funktionen), damit
 * hier exakt der Rohzustand zu sehen ist, unverändert durch etwaige
 * Anzeigelogik.
 */
function render_measurement_raw(string $deviceId, int $measurementId): void
{
    $m = measurement_find($measurementId, $deviceId);
    if ($m === null) {
        json_error(404, 'Messung nicht gefunden');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'id' => (int) $m['id'],
        'device_id' => $m['device_id'],
        'kind' => $m['kind'],
        'client_timestamp' => $m['client_timestamp'],
        'received_at' => $m['received_at'],
        'data' => json_decode((string) $m['data'], true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_delete_all_measurements_via_form(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    $kind = trim((string) ($_POST['kind'] ?? ''));
    $kind = in_array($kind, ['scan', 'connection_test', 'lan_test'], true) ? $kind : null;
    measurement_delete_all($deviceId, $kind);
    header('Location: /devices/' . rawurlencode($deviceId));
    exit;
}

/**
 * Löscht nur die Connection-Tests EINER SSID (Gegenstück zu
 * handle_delete_all_measurements_via_form() mit kind=connection_test,
 * das immer alle SSIDs auf einmal trifft). "(unbekannt)" ist derselbe
 * Platzhalter wie in render_device_detail()/device_detail.php für
 * Messungen ohne SSID im Payload - kommt hier als NULL an.
 */
function handle_delete_measurements_for_ssid(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    $ssid = (string) ($_POST['ssid'] ?? '');
    measurement_delete_all_for_ssid($deviceId, $ssid === '(unbekannt)' ? null : $ssid);
    header('Location: /devices/' . rawurlencode($deviceId));
    exit;
}

/**
 * Bulk-Loeschen einer per Checkbox ausgewaehlten Teilmenge (siehe
 * "Letzte Tests"-Tabelle, Auswahl-Aktionsleiste in device_detail.php).
 * $_POST['ids'] kommt aus per JS dynamisch angehaengten Hidden-Inputs
 * (ids[]), nicht aus einem echten Formular-Feld mit fester Struktur -
 * daher die Typ-/Array-Pruefung hier statt einfach blind zu casten.
 */
function handle_delete_measurements_many(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Gerät nicht gefunden') . "\n";
        exit;
    }
    $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
    measurement_delete_many($deviceId, $ids);
    header('Location: /devices/' . rawurlencode($deviceId));
    exit;
}

function handle_rotate_key(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        json_error(404, 'Gerät nicht gefunden');
    }
    $apiKey = bin2hex(random_bytes(32));
    device_rotate_key($deviceId, hash_api_key($apiKey));
    audit_log('device', $deviceId, 'api_key_rotated');
    json_response(['device_id' => $deviceId, 'api_key' => $apiKey]);
}

function handle_delete_device(string $deviceId): void
{
    if (device_find($deviceId) === null) {
        json_error(404, 'Gerät nicht gefunden');
    }
    device_delete($deviceId);
    audit_log('device', $deviceId, 'deleted');
    http_response_code(204);
    exit;
}

/**
 * LLD-Discovery-Endpunkt für Zabbix (HTTP-Agent-Discovery-Regel): liefert
 * je registriertem Gerät die Makros {#DEVICE_ID}/{#SITE}, aus denen Zabbix
 * per Host-Prototyp automatisch einen Host anlegt.
 */
function handle_zabbix_discover_devices(): void
{
    require_zabbix_auth();
    $data = [];
    foreach (device_list() as $d) {
        $data[] = [
            '{#DEVICE_ID}' => $d['id'],
            '{#SITE}' => $d['site_name'] ?? '',
        ];
    }
    json_response(['data' => $data]);
}

/**
 * Aggregierter Status pro Gerät für ein einzelnes Zabbix-HTTP-Agent-Item
 * (Master-Item), von dem die eigentlichen Items per JSONPath abgeleitet
 * werden (last_seen, Scan-Ergebnis, Connection-Test-Zusammenfassung).
 * Enthält zusätzlich das volle Array je SSID, falls später einmal
 * granularere Per-SSID-Items/Discovery gewünscht sind.
 */
function handle_zabbix_device_status(string $deviceId): void
{
    require_zabbix_auth();
    $device = device_find($deviceId);
    if ($device === null) {
        json_error(404, 'Gerät nicht gefunden');
    }

    $secondsSinceLastSeen = null;
    if (!empty($device['last_seen_at'])) {
        $lastSeen = new DateTime((string) $device['last_seen_at'], new DateTimeZone('UTC'));
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $secondsSinceLastSeen = $now->getTimestamp() - $lastSeen->getTimestamp();
    }

    $lastScan = measurement_last($deviceId, 'scan');
    $lastScanAt = null;
    $lastScanNetworkCount = null;
    if ($lastScan !== null) {
        $lastScanAt = $lastScan['client_timestamp'] ?? $lastScan['received_at'];
        $scanData = json_decode((string) $lastScan['data'], true) ?: [];
        $lastScanNetworkCount = count($scanData['networks'] ?? []);
    }

    // Neuesten Test je SSID ermitteln: measurement_list() liefert nach
    // received_at DESC sortiert, der erste Treffer je SSID ist also der
    // aktuellste - ein fester Lookback von 100 reicht, da pro Durchlauf
    // nur eine Handvoll Ziel-SSIDs getestet wird.
    $bySsid = [];
    foreach (measurement_list($deviceId, 'connection_test', 100) as $t) {
        $data = json_decode((string) $t['data'], true) ?: [];
        $ssid = $data['ssid'] ?? null;
        if ($ssid === null || isset($bySsid[$ssid])) {
            continue;
        }
        $pingSent = $data['ping_sent'] ?? null;
        $pingReceived = $data['ping_received'] ?? null;
        // Ping zum Gateway hinter einem Captive Portal ohne Login: viele
        // Portale (z.B. Meraki) verwerfen ICMP, der Verlust ist dann kein
        // Netzfehler - nicht in max_ping_loss_percent einfliessen lassen.
        $portalPing = ($data['ping_target_source'] ?? '') === 'portal_gateway';
        $bySsid[$ssid] = [
            'ssid' => $ssid,
            'timestamp' => $t['client_timestamp'] ?? $t['received_at'],
            'connected' => !empty($data['connected']),
            'assoc_seconds' => $data['assoc_seconds'] ?? null,
            'dhcp_seconds' => $data['dhcp_seconds'] ?? null,
            'ping_sent' => $pingSent,
            'ping_received' => $pingReceived,
            'ping_rtt_avg_ms' => $data['ping_rtt_avg_ms'] ?? null,
            'ping_loss_percent' => (!$portalPing && $pingSent !== null && $pingSent > 0)
                ? round((($pingSent - (int) $pingReceived) / $pingSent) * 100, 1)
                : null,
            'iperf3_mbps' => $data['iperf3_mbps'] ?? null,
            'iperf3_download_mbps' => $data['iperf3_download_mbps'] ?? null,
            'error' => $data['error'] ?? null,
        ];
    }
    $connectionTests = array_values($bySsid);

    $failedSsids = [];
    $okCount = 0;
    $worstRtt = null;
    $maxLoss = null;
    $minThroughput = null;
    $minDownload = null;
    foreach ($connectionTests as $ct) {
        if ($ct['connected']) {
            $okCount++;
        } else {
            $failedSsids[] = $ct['ssid'];
        }
        if ($ct['ping_rtt_avg_ms'] !== null && ($worstRtt === null || $ct['ping_rtt_avg_ms'] > $worstRtt)) {
            $worstRtt = $ct['ping_rtt_avg_ms'];
        }
        if ($ct['ping_loss_percent'] !== null && ($maxLoss === null || $ct['ping_loss_percent'] > $maxLoss)) {
            $maxLoss = $ct['ping_loss_percent'];
        }
        if ($ct['iperf3_mbps'] !== null && ($minThroughput === null || $ct['iperf3_mbps'] < $minThroughput)) {
            $minThroughput = $ct['iperf3_mbps'];
        }
        if ($ct['iperf3_download_mbps'] !== null && ($minDownload === null || $ct['iperf3_download_mbps'] < $minDownload)) {
            $minDownload = $ct['iperf3_download_mbps'];
        }
    }

    // Letzter LAN-iperf3-Test (nur vorhanden, wenn am Geraet aktiviert).
    $lanUp = null;
    $lanDown = null;
    $lanTest = measurement_last($deviceId, 'lan_test');
    if ($lanTest !== null) {
        $lanData = json_decode((string) $lanTest['data'], true) ?: [];
        $lanUp = $lanData['upload_mbps'] ?? null;
        $lanDown = $lanData['download_mbps'] ?? null;
    }

    $zbxHealth = json_decode((string) ($device['health'] ?? ''), true) ?: [];
    $payload = [
        'device_id' => $device['id'],
        'site' => $device['site_name'],
        'last_seen_at' => $device['last_seen_at'],
        'seconds_since_last_seen' => $secondsSinceLastSeen,
        'last_scan_at' => $lastScanAt,
        'last_scan_network_count' => $lastScanNetworkCount,
        'connection_tests_total' => count($connectionTests),
        'connection_tests_ok' => $okCount,
        'connection_tests_failed' => count($failedSsids),
        'connection_tests_failed_ssids' => implode(', ', $failedSsids),
        'worst_ping_rtt_ms' => $worstRtt,
        'max_ping_loss_percent' => $maxLoss,
        'min_iperf3_mbps' => $minThroughput,
        'min_iperf3_download_mbps' => $minDownload,
        'lan_iperf3_upload_mbps' => $lanUp,
        'lan_iperf3_download_mbps' => $lanDown,
        // Systemwerte aus dem letzten Heartbeat (Probe ab 1.0.1.54).
        'seconds_since_heartbeat' => !empty($device['heartbeat_at'])
            ? max(0, time() - (int) strtotime($device['heartbeat_at'] . ' UTC')) : null,
        'temperature_c' => $zbxHealth['temperature_c'] ?? null,
        'cpu_percent' => $zbxHealth['cpu_percent'] ?? null,
        'mem_used_percent' => $zbxHealth['mem_used_percent'] ?? null,
        'disk_used_percent' => $zbxHealth['disk_used_percent'] ?? null,
        'uptime_seconds' => $zbxHealth['uptime_seconds'] ?? null,
        'queue_unsent' => $zbxHealth['queue_unsent'] ?? null,
        'connection_tests' => $connectionTests,
    ];

    // Optionale Werte, die (noch) nicht vorliegen, komplett weglassen statt
    // als JSON "null" zu senden: Zabbix' JSONPath-Preprocessing meldet für
    // einen fehlenden Schlüssel einen echten "Pfad nicht gefunden"-Fehler,
    // den "Custom on fail" auf dem JSONPath-Schritt zuverlässig abfängt.
    // Ein explizites JSON-null dagegen wird vom JSONPath-Schritt erfolgreich
    // als String "null" durchgereicht und scheitert erst danach bei der
    // Typkonvertierung in "Numeric (float)" - ein Fehler, den "Custom on
    // fail" auf dem JSONPath-Schritt nicht abdeckt.
    foreach ([
        'last_seen_at', 'seconds_since_last_seen', 'last_scan_at',
        'last_scan_network_count', 'worst_ping_rtt_ms',
        'max_ping_loss_percent', 'min_iperf3_mbps', 'min_iperf3_download_mbps',
        'lan_iperf3_upload_mbps', 'lan_iperf3_download_mbps',
        'seconds_since_heartbeat', 'temperature_c', 'cpu_percent', 'mem_used_percent',
        'disk_used_percent', 'uptime_seconds', 'queue_unsent',
    ] as $key) {
        if ($payload[$key] === null) {
            unset($payload[$key]);
        }
    }

    json_response($payload);
}

/** @param array<string, mixed> $user Aus require_login() - filtert die Geräteliste auf die erlaubten Sites (Admin sieht alles). */
function render_dashboard(array $user): void
{
    $devices = array_values(array_filter(
        device_list(),
        fn(array $d): bool => user_can_access_site($user, isset($d['site_id']) ? (int) $d['site_id'] : null)
    ));
    $rows = [];
    // Zeit-Schwellen je Standort (Alarmierungsseite) - faerben die Ø-Zeiten
    // in der Liste: 802.1X ist zugleich die Schwelle der Alarmregel "802.1X
    // langsam", Assoziation und DHCP sind reine Anzeige. Keine festen Grenzen,
    // weil sie vom Standort abhaengen (Cloud-RADIUS, DHCP-Server, Scanzeit
    // des Adapters). Ein Lookup je Standort.
    $slowBySite = [];
    foreach ($devices as $d) {
        $siteId = isset($d['site_id']) ? (int) $d['site_id'] : null;
        if ($siteId !== null && !array_key_exists($siteId, $slowBySite)) {
            try {
                $sa = site_alerting_get($siteId);
            } catch (PDOException $e) {
                $sa = null;
            }
            $slowBySite[$siteId] = [];
            foreach (['assoc', 'auth', 'dhcp'] as $kind) {
                $v = isset($sa[$kind . '_slow_seconds']) ? (float) $sa[$kind . '_slow_seconds'] : 0.0;
                $slowBySite[$siteId][$kind] = $v > 0 ? $v : null;
            }
        }
    }
    foreach ($devices as $d) {
        $lastScan = measurement_last($d['id'], 'scan');
        // Dieselben 100 Tests wie auf der Geräteseite, damit die Kennzahlen
        // hier und dort übereinstimmen.
        $tests = measurement_list($d['id'], 'connection_test', 100);
        $lastTest = $tests[0] ?? null;
        $lastLan = measurement_last($d['id'], 'lan_test');
        $networkCount = null;
        if ($lastScan) {
            $scanData = json_decode((string) $lastScan['data'], true) ?: [];
            $networkCount = count($scanData['networks'] ?? []);
        }
        $rows[] = [
            'device' => $d,
            'last_scan' => $lastScan,
            'last_test' => $lastTest,
            'last_lan' => $lastLan,
            'test_summary' => connection_test_summary($tests),
            'network_count' => $networkCount,
            'online' => device_is_online($d['last_seen_at'] ?? null),
            'slow_seconds' => isset($d['site_id']) ? ($slowBySite[(int) $d['site_id']] ?? []) : [],
        ];
    }
    $deletedDevice = isset($_GET['deleted']) ? (string) $_GET['deleted'] : null;
    require __DIR__ . '/../src/templates/dashboard.php';
}

function render_device_new_form(?string $error = null): void
{
    $sites = site_list();
    require __DIR__ . '/../src/templates/device_new.php';
}

function handle_create_device_via_form(): void
{
    $deviceId = trim((string) ($_POST['device_id'] ?? ''));
    $siteId = ($_POST['site_id'] ?? '') !== '' ? (int) $_POST['site_id'] : null;

    if ($deviceId === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $deviceId)) {
        render_device_new_form(
            __('Device-ID ist erforderlich und darf nur Buchstaben, Zahlen, Punkt, Bindestrich und Unterstrich enthalten.')
        );
        return;
    }
    if (device_find($deviceId) !== null) {
        render_device_new_form(__('Diese Device-ID existiert bereits.'));
        return;
    }

    $apiKey = bin2hex(random_bytes(32));
    device_create($deviceId, $siteId, hash_api_key($apiKey));
    audit_log('device', $deviceId, 'created');

    // $deviceId und $apiKey stehen dem Template über den PHP-Scope von
    // require zur Verfügung - Key wird hier bewusst nur dieses eine
    // Mal im Klartext angezeigt.
    require __DIR__ . '/../src/templates/device_created.php';
}

/** @param array<string, mixed> $device Bereits per device_find_or_404() geladen (siehe Route). */
function render_device_detail(array $device): void
{
    $deviceId = $device['id'];

    // Anzahl über ?scan_limit=<n> einstellbar (Standard 20), auf eine
    // sinnvolle Spanne begrenzt statt beliebige Werte/Text zuzulassen.
    $allowedScanLimits = [20, 50, 100, 200, 500];
    $scanLimit = (int) ($_GET['scan_limit'] ?? 20);
    if (!in_array($scanLimit, $allowedScanLimits, true)) {
        $scanLimit = 20;
    }

    $scans = measurement_list($deviceId, 'scan', $scanLimit);
    $tests = measurement_list($deviceId, 'connection_test', 100);
    $lanTests = measurement_list($deviceId, 'lan_test', 100);
    // Mitschnitte zu fehlgeschlagenen Tests (nur fuer Rollen mit Schreibrecht sichtbar).
    $captureIds = [];
    foreach ($tests as $t) {
        $cid = (json_decode((string) $t['data'], true) ?: [])['capture_id'] ?? null;
        if (is_string($cid)) {
            $captureIds[] = $cid;
        }
    }
    $captureInfo = captures_existing($deviceId, $captureIds);

    // Systemwerte (Heartbeat, Probe ab 1.0.1.54): aktueller Stand am Geraet,
    // Verlauf aus den hoechstens alle 5 min gespeicherten Punkten (24 h).
    $health = json_decode((string) ($device['health'] ?? ''), true) ?: null;
    $healthSeries = ['labels' => [], 'temperature' => [], 'cpu' => [], 'mem' => [], 'disk' => []];
    foreach (array_reverse(measurement_list($deviceId, 'health', 288)) as $h) {
        $hd = json_decode((string) $h['data'], true) ?: [];
        $healthSeries['labels'][] = format_local((string) ($h['client_timestamp'] ?? $h['received_at']), 'd.m. H:i');
        $healthSeries['temperature'][] = $hd['temperature_c'] ?? null;
        $healthSeries['cpu'][] = $hd['cpu_percent'] ?? null;
        $healthSeries['mem'][] = $hd['mem_used_percent'] ?? null;
        $healthSeries['disk'][] = $hd['disk_used_percent'] ?? null;
    }

    // LAN-Zeitreihe chronologisch aufsteigend fuer das Diagramm.
    $lanSeries = ['labels' => [], 'upload' => [], 'download' => []];
    foreach (array_reverse($lanTests) as $t) {
        $data = json_decode((string) $t['data'], true) ?: [];
        $lanSeries['labels'][] = format_local((string) ($t['client_timestamp'] ?? $t['received_at']), 'd.m. H:i');
        $lanSeries['upload'][] = $data['upload_mbps'] ?? null;
        $lanSeries['download'][] = $data['download_mbps'] ?? null;
    }

    // Pro SSID eigene Zeitreihen aufbauen (chronologisch aufsteigend),
    // damit im Dashboard je Ziel-Netz eigene Charts gerendert werden
    // können statt eines einzigen, SSID-übergreifend vermischten Graphen.
    $testsBySsid = [];
    foreach (array_reverse($tests) as $t) {
        $data = json_decode((string) $t['data'], true) ?: [];
        $ssid = $data['ssid'] ?? '(unbekannt)';
        // client_timestamp = tatsächlicher Messzeitpunkt (vom Client
        // gesendet); Fallback auf received_at nur falls kein
        // Zeitstempel im Payload war.
        $ts = $t['client_timestamp'] ?? $t['received_at'];

        if (!isset($testsBySsid[$ssid])) {
            $testsBySsid[$ssid] = ['labels' => [], 'rtt' => [], 'assoc' => [], 'scan' => [], 'dhcp' => [], 'auth' => [], 'signal' => [], 'iperf3' => [], 'iperf3_down' => []];
        }
        $testsBySsid[$ssid]['labels'][] = format_local((string) $ts, 'd.m. H:i');
        $testsBySsid[$ssid]['rtt'][] = $data['ping_rtt_avg_ms'] ?? null;
        $testsBySsid[$ssid]['assoc'][] = $data['assoc_seconds'] ?? null;
        // Anteil des Scans an der Assoziationszeit (ab Probe 1.0.1.34).
        $testsBySsid[$ssid]['scan'][] = $data['scan_seconds'] ?? null;
        $testsBySsid[$ssid]['dhcp'][] = $data['dhcp_seconds'] ?? null;
        $testsBySsid[$ssid]['auth'][] = $data['auth_seconds'] ?? null;
        // Signalstaerke des Links am Testende; aeltere Tests ohne "link"-Block
        // ergeben null (Luecke im Diagramm).
        $testsBySsid[$ssid]['signal'][] = is_array($data['link'] ?? null)
            ? ($data['link']['signal_dbm'] ?? null)
            : null;
        $testsBySsid[$ssid]['iperf3'][] = $data['iperf3_mbps'] ?? null;
        $testsBySsid[$ssid]['iperf3_down'][] = $data['iperf3_download_mbps'] ?? null;
    }
    ksort($testsBySsid);

    // Kennzahlen-Kacheln oben auf der Seite: Online-Status sowie wie
    // viele der zuletzt getesteten SSIDs aktuell verbunden vs.
    // fehlgeschlagen sind (neuester Test je SSID aus $tests, das ist
    // bereits nach received_at DESC sortiert).
    $online = device_is_online($device['last_seen_at'] ?? null);
    $testSummary = connection_test_summary($tests);
    $latestPerSsid = $testSummary['latest_per_ssid'];
    $ssidOkCount = $testSummary['ssid_ok'];
    $ssidTotalCount = $testSummary['ssid_total'];
    $lastScan = $scans[0] ?? null;
    $lastScanCount = null;
    $localIps = [];
    if ($lastScan !== null) {
        $lastScanData = json_decode((string) $lastScan['data'], true) ?: [];
        $lastScanCount = count($lastScanData['networks'] ?? []);
        $localIps = is_array($lastScanData['local_ips'] ?? null) ? $lastScanData['local_ips'] : [];
    }

    require __DIR__ . '/../src/templates/device_detail.php';
}

/** @param array<string, mixed> $device Bereits per device_find_or_404() geladen (siehe Route). */
function render_device_config(array $device): void
{
    $config = [];
    if (!empty($device['config'])) {
        $config = json_decode((string) $device['config'], true) ?: [];
    }
    $configSaved = isset($_GET['saved']);
    $sites = site_list();
    $auditEntries = audit_list('device', (string) $device['id'], 20);

    require __DIR__ . '/../src/templates/device_config.php';
}

/** Nur noch die gemeinsamen SMTP-/Telegram-Zugangsdaten - Ein/Aus/Empfänger/Zeitfenster siehe render_site_alerting(). */
function render_settings_retention(?string $error = null): void
{
    $retention = retention_config();
    $overview = retention_overview();
    $lastCleanup = setting_get('retention_last_run');
    $lastBackup = setting_get('backup_last_run');
    $secretKey = secret_key_status();
    $secrets = secrets_overview();
    $encryptedNow = isset($_GET['encrypted']) ? (int) $_GET['encrypted'] : null;
    $settingsSaved = isset($_GET['saved']);
    require __DIR__ . '/../src/templates/settings_retention.php';
}

/** Speichert die Aufbewahrung (Tabelle "settings", Schlüssel "retention"). */
function handle_set_settings_retention(): void
{
    $values = [];
    foreach (array_keys(RETENTION_DEFAULTS) as $key) {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '' || !ctype_digit($raw) || (int) $raw > RETENTION_MAX_DAYS) {
            render_settings_retention(__('Bitte ganze Tage zwischen 0 und %d eingeben (0 = unbegrenzt).', RETENTION_MAX_DAYS));
            return;
        }
        $values[$key] = (int) $raw;
    }
    $changes = audit_diff(retention_config(), $values);
    setting_set('retention', $values);
    if ($changes !== []) {
        audit_log('settings', 'retention', 'changed', $changes);
    }
    header('Location: /settings/retention?saved=1');
    exit;
}

function render_settings_alerting(): void
{
    // Gespeicherte Form (ggf. verschlüsselt) - das Formular fragt nur ab, OB
    // ein Passwort/Token gesetzt ist, und zeigt es nie an.
    $alerting = setting_get('alerting') ?? [];
    $settingsSaved = isset($_GET['saved']);

    require __DIR__ . '/../src/templates/settings_alerting.php';
}

/**
 * Speichert die SMTP-/Telegram-Zugangsdaten (Tabelle "settings", Schluessel
 * "alerting") aus dem Formular in src/templates/settings_alerting.php.
 */
function handle_set_settings_alerting(): void
{
    $old = setting_get('alerting') ?? [];
    $alerting = [
        'email' => [
            'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
            'smtp_port' => min(65535, max(1, (int) ($_POST['smtp_port'] ?? 587))),
            'smtp_user' => trim((string) ($_POST['smtp_user'] ?? '')),
            // Leer gelassen = bisheriges Passwort (Feld zeigt es nie an).
            'smtp_pass' => secret_from_form($_POST['smtp_pass'] ?? '', !empty($_POST['smtp_pass_clear']), array_path_get($old, ['email', 'smtp_pass'])),
            'smtp_secure' => in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl', 'none'], true)
                ? $_POST['smtp_secure'] : 'tls',
            'from' => trim((string) ($_POST['email_from'] ?? '')),
            'from_name' => trim((string) ($_POST['email_from_name'] ?? '')) ?: 'WLANMON',
        ],
        'telegram' => [
            'bot_token' => secret_from_form(trim((string) ($_POST['bot_token'] ?? '')), !empty($_POST['bot_token_clear']), array_path_get($old, ['telegram', 'bot_token'])),
        ],
    ];

    $changes = audit_diff($old, $alerting);
    setting_set('alerting', secrets_map($alerting, ALERTING_SECRET_PATHS, 'secret_encrypt'));
    if ($changes !== []) {
        audit_log('settings', 'alerting', 'changed', $changes);
    }
    header('Location: /settings/alerting?saved=1');
    exit;
}

// ============================================================
// Login / Einladungen / Über (siehe src/Session.php, src/User.php)
// ============================================================

/** Nur Pfade, die mit genau einem "/" beginnen, sonst Open-Redirect-Risiko (z.B. "//evil.tld"). */
function safe_local_redirect_target(?string $path): string
{
    // Nur eigene Pfade: beginnt mit genau einem "/", danach kein "/" oder "\"
    // (Browser deuten "/\host" wie "//host" als fremden Host - Open Redirect),
    // keine Backslashes und keine Leer-/Steuerzeichen.
    if (!is_string($path) || preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) !== 1) {
        return '/';
    }
    return $path;
}

function render_login(?string $error = null): void
{
    if (current_user() !== null) {
        header('Location: /');
        exit;
    }
    $next = safe_local_redirect_target($_GET['next'] ?? $_POST['next'] ?? null);
    require __DIR__ . '/../src/templates/login.php';
}

function handle_login(): void
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next = safe_local_redirect_target($_POST['next'] ?? null);

    if ($username === '' || $password === '' || !login($username, $password)) {
        render_login(__('Benutzername oder Passwort falsch.'));
        return;
    }
    header('Location: ' . $next);
    exit;
}

function render_invite(string $token): void
{
    $invite = invite_find_valid($token);
    if ($invite === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Einladungslink ungültig oder abgelaufen') . "\n";
        exit;
    }
    $user = user_find((int) $invite['user_id']);
    $error = null;
    require __DIR__ . '/../src/templates/invite.php';
}

function handle_invite_submit(string $token): void
{
    $invite = invite_find_valid($token);
    if ($invite === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Einladungslink ungültig oder abgelaufen') . "\n";
        exit;
    }
    $user = user_find((int) $invite['user_id']);
    $password = (string) ($_POST['password'] ?? '');
    $passwordRepeat = (string) ($_POST['password_repeat'] ?? '');

    $error = null;
    if (strlen($password) < 8) {
        $error = __('Passwort muss mindestens 8 Zeichen lang sein.');
    } elseif ($password !== $passwordRepeat) {
        $error = __('Passwörter stimmen nicht überein.');
    } elseif (is_weak_password($user['username'], $password)) {
        $error = __('Passwort ist zu schwach/vorhersehbar (z.B. „admin“ oder identisch zum Benutzernamen) - bitte ein anderes wählen.');
    }
    if ($error !== null) {
        require __DIR__ . '/../src/templates/invite.php';
        return;
    }

    invite_consume($invite, $password);
    header('Location: /login?activated=1');
    exit;
}

function render_about(): void
{
    require __DIR__ . '/../src/templates/about.php';
}

function render_account_password(?string $error = null): void
{
    $passwordChanged = isset($_GET['saved']);
    require __DIR__ . '/../src/templates/account_password.php';
}

function handle_change_password(): void
{
    $user = current_user();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $repeat = (string) ($_POST['new_password_repeat'] ?? '');

    if (!user_verify_password((int) $user['id'], $current)) {
        render_account_password(__('Aktuelles Passwort ist falsch.'));
        return;
    }
    if (strlen($new) < 8) {
        render_account_password(__('Neues Passwort muss mindestens 8 Zeichen lang sein.'));
        return;
    }
    if ($new !== $repeat) {
        render_account_password(__('Neue Passwörter stimmen nicht überein.'));
        return;
    }
    if (is_weak_password($user['username'], $new)) {
        render_account_password(__('Passwort ist zu schwach/vorhersehbar (z.B. „admin“ oder identisch zum Benutzernamen) - bitte ein anderes wählen.'));
        return;
    }
    user_set_password((int) $user['id'], $new);
    header('Location: /account/password?saved=1');
    exit;
}

// ============================================================
// Access Points (Cirrus-Funkzustand, siehe src/Cirrus.php)
// ============================================================

/**
 * Read-only fuer alle eingeloggten Rollen (admin/user/viewer) - anders
 * als bei Geraeten/Standorten gibt es hier (noch) keine Site-basierte
 * Zugriffsbeschraenkung: cirrus_aps.site_name ist ein freier Cirrus-
 * Text ohne Verknuepfung zur lokalen sites-Tabelle (site_id), gegen die
 * user_can_access_site() prueft.
 */
function render_access_points(): void
{
    $status = cirrus_sync_status();
    $aps = $status['configured'] ? cirrus_ap_overview() : [];
    require __DIR__ . '/../src/templates/access_points.php';
}

// ============================================================
// Standorte (nur Admin, siehe src/Site.php)
// ============================================================

/** Admin sieht alle Sites (+ Anlegen/Umbenennen/Löschen), 'user' nur seine eigenen zugewiesenen. */
function render_sites(?string $error = null): void
{
    $user = current_user();
    $isAdmin = ($user['role'] ?? null) === 'admin';
    $sites = $isAdmin
        ? site_list()
        : array_values(array_filter(site_list(), fn(array $s) => in_array((int) $s['id'], user_site_ids((int) $user['id']), true)));
    $siteCreated = isset($_GET['created']);
    $siteDeleted = isset($_GET['deleted']);
    require __DIR__ . '/../src/templates/sites.php';
}

function handle_create_site(): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        render_sites(__('Standortname ist erforderlich.'));
        return;
    }
    try {
        $newSiteId = site_create($name);
        audit_log('site', (string) $newSiteId, 'created', [['field' => 'name', 'old' => '–', 'new' => $name]]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            render_sites(__('Dieser Standort existiert bereits.'));
            return;
        }
        throw $e;
    }
    header('Location: /sites?created=1');
    exit;
}

function handle_rename_site(int $id): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $site = site_find($id);
    if ($name === '' || $site === null) {
        render_sites(__('Standortname ist erforderlich.'));
        return;
    }
    try {
        site_rename($id, $name);
        if ($name !== $site['name']) {
            audit_log('site', (string) $id, 'renamed', [['field' => 'name', 'old' => (string) $site['name'], 'new' => $name]]);
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            render_sites(__('Dieser Standortname wird bereits verwendet.'));
            return;
        }
        throw $e;
    }
    header('Location: /sites');
    exit;
}

function handle_delete_site(int $id): void
{
    $site = site_find($id);
    site_delete($id);
    if ($site !== null) {
        audit_log('site', (string) $id, 'deleted', [['field' => 'name', 'old' => (string) $site['name'], 'new' => '–']]);
    }
    header('Location: /sites?deleted=1');
    exit;
}

/** Bemerkung ändern - auch für 'user' auf eigenen Sites erlaubt (require_site_access() an der Route). */
function handle_set_site_notes(int $id): void
{
    if (site_find($id) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Standort nicht gefunden') . "\n";
        exit;
    }
    $site = site_find($id);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    site_set_notes($id, $notes);
    $changes = audit_diff(['notes' => (string) ($site['notes'] ?? '')], ['notes' => $notes]);
    if ($changes !== []) {
        audit_log('site', (string) $id, 'notes', $changes);
    }
    header('Location: /sites');
    exit;
}

// ============================================================
// Alerting je Site (siehe /sites/<id>/alerting, src/Alerting.php)
// ============================================================

/**
 * Zeitfenster-Wochentage/Stunden + Ein-/Aus-Schalter/Empfänger aus dem
 * Formular in src/templates/site_alerting.php lesen (gemeinsam von
 * handle_set_site_alerting() und handle_test_site_alerting() gebraucht -
 * Testen speichert NICHT automatisch, siehe dortiger Hinweis im Template).
 */
function site_alerting_from_post(): array
{
    $days = array_values(array_intersect(
        array_map('intval', is_array($_POST['schedule_days'] ?? null) ? $_POST['schedule_days'] : []),
        [1, 2, 3, 4, 5, 6, 7]
    ));
    return [
        'enabled' => !empty($_POST['enabled']),
        'offline_after_minutes' => max(1, (int) ($_POST['offline_after_minutes'] ?? 20)),
        'consecutive_test_failures' => max(2, (int) ($_POST['consecutive_test_failures'] ?? 3)),
        'repeat_after_minutes' => max(5, (int) ($_POST['repeat_after_minutes'] ?? 240)),
        'email_enabled' => !empty($_POST['email_enabled']),
        'email_to' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['email_to'] ?? ''))))),
        'telegram_enabled' => !empty($_POST['telegram_enabled']),
        'telegram_chat_id' => trim((string) ($_POST['telegram_chat_id'] ?? '')),
        'schedule_mode' => ($_POST['schedule_mode'] ?? 'always') === 'custom' ? 'custom' : 'always',
        'schedule_days' => implode(',', $days),
        'schedule_start_hour' => min(23, max(0, (int) ($_POST['schedule_start_hour'] ?? 8))),
        'schedule_end_hour' => min(24, max(1, (int) ($_POST['schedule_end_hour'] ?? 20))),
        'language' => isset(WLANMON_LANGS[$_POST['language'] ?? '']) ? $_POST['language'] : 'de',
        // Leer oder 0 = Regel aus bzw. keine Faerbung.
        'auth_slow_seconds' => post_seconds_threshold('auth_slow_seconds'),
        'assoc_slow_seconds' => post_seconds_threshold('assoc_slow_seconds'),
        'dhcp_slow_seconds' => post_seconds_threshold('dhcp_slow_seconds'),
    ];
}

/** Sekunden-Schwelle aus dem Formular (Komma oder Punkt), leer/0 = null, max. 999,9. */
function post_seconds_threshold(string $field): ?float
{
    $value = (float) str_replace(',', '.', (string) ($_POST[$field] ?? ''));
    return $value > 0 ? min(999.9, round($value, 1)) : null;
}

function render_site_alerting(int $siteId, ?array $testResult = null): void
{
    $site = site_find($siteId);
    if ($site === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Standort nicht gefunden') . "\n";
        exit;
    }
    $siteAlerting = site_alerting_get($siteId);
    if ($siteAlerting === null) {
        // Komfort fuer den Umstieg von der frueheren globalen Config:
        // beim allerersten Oeffnen mit den frueheren globalen Werten
        // vorbefuellen (nicht automatisch gespeichert), damit bereits
        // eingerichtete Schwellwerte/Empfaenger nicht neu eingetippt
        // werden muessen.
        $legacy = alerting_config();
        $siteAlerting = [
            'enabled' => false,
            'offline_after_minutes' => $legacy['offline_after_minutes'] ?? 20,
            'consecutive_test_failures' => $legacy['consecutive_test_failures'] ?? 3,
            'repeat_after_minutes' => $legacy['repeat_after_minutes'] ?? 240,
            'email_enabled' => $legacy['email']['enabled'] ?? false,
            'email_to' => json_encode($legacy['email']['to'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'telegram_enabled' => $legacy['telegram']['enabled'] ?? false,
            'telegram_chat_id' => $legacy['telegram']['chat_id'] ?? '',
            'schedule_mode' => 'always',
            'schedule_days' => '1,2,3,4,5',
            'schedule_start_hour' => 8,
            'schedule_end_hour' => 20,
            'language' => 'de',
            'auth_slow_seconds' => null,
            'assoc_slow_seconds' => null,
            'dhcp_slow_seconds' => null,
        ];
    }
    $settingsSaved = isset($_GET['saved']);
    require __DIR__ . '/../src/templates/site_alerting.php';
}

function handle_set_site_alerting(int $siteId): void
{
    if (site_find($siteId) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Standort nicht gefunden') . "\n";
        exit;
    }
    $old = site_alerting_get($siteId) ?? [];
    $new = site_alerting_from_post();
    site_alerting_set($siteId, $new);
    // Vergleich über die gespeicherte Form (email_to als JSON usw.).
    $saved = site_alerting_get($siteId) ?? [];
    unset($old['updated_at'], $saved['updated_at']);
    $changes = audit_diff($old, $saved);
    if ($changes !== []) {
        audit_log('site', (string) $siteId, 'alerting', $changes);
    }
    header('Location: /sites/' . $siteId . '/alerting?saved=1');
    exit;
}

/**
 * Sendet einen Testalarm mit den bereits gespeicherten Einstellungen
 * dieser Site (vorher speichern, dann testen - wie zuvor bei der
 * globalen Config). Aequivalent zu "php check_alerts.php --test <site>".
 */
function handle_test_site_alerting(int $siteId): void
{
    $siteAlerting = site_alerting_get($siteId) ?? [];
    // Nachricht in der Alert-Sprache des Standorts; Fehlertexte für die
    // Anzeige danach in der Sprache der Oberfläche.
    [$subject, $message] = with_lang($siteAlerting['language'] ?? null, fn(): array => [
        __('WLANMON: Test-Alarm'),
        __('Dies ist ein Testalarm aus dem Dashboard (/sites/%s/alerting), um die Zustellung zu prüfen. Keine echte Störung.', (string) $siteId),
    ]);
    $testResult = dispatch_alert(site_alerting_channels($siteAlerting), $subject, $message);
    render_site_alerting($siteId, $testResult);
}

// ============================================================
// Benutzer (nur Admin, siehe src/User.php)
// ============================================================

function render_users(?string $error = null): void
{
    $users = user_list();
    $sites = site_list();
    $userSaved = isset($_GET['saved']);
    $userDeleted = isset($_GET['deleted']);
    $userDisabled = isset($_GET['disabled']);
    $userEnabled = isset($_GET['enabled']);
    $disableSupported = users_disabled_supported();
    $me = current_user();
    require __DIR__ . '/../src/templates/users.php';
}

/**
 * Standortnamen zu IDs (fürs Änderungsprotokoll, lesbarer als Nummern).
 *
 * @param int[] $siteIds
 * @return string[]
 */
function audit_site_names(array $siteIds): array
{
    $names = [];
    foreach ($siteIds as $sid) {
        $site = site_find((int) $sid);
        $names[] = $site !== null ? (string) $site['name'] : '#' . (int) $sid;
    }
    sort($names, SORT_STRING);
    return $names;
}

/** @return int[] */
function parse_site_ids_from_post(): array
{
    $raw = $_POST['sites'] ?? [];
    return array_values(array_unique(array_map('intval', is_array($raw) ? $raw : [])));
}

function handle_create_user(): void
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = (string) ($_POST['role'] ?? 'viewer');
    $siteIds = parse_site_ids_from_post();

    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, USER_ROLES, true)) {
        render_users(__('Benutzername, gültige E-Mail-Adresse und Rolle sind erforderlich.'));
        return;
    }
    if (user_find_by_username($username) !== null) {
        render_users(__('Dieser Benutzername existiert bereits.'));
        return;
    }

    $id = user_create($username, $email, $role);
    if ($role !== 'admin' && $siteIds !== []) {
        user_set_sites($id, $siteIds);
    }
    audit_log('user', (string) $id, 'created', audit_diff([], ['username' => $username, 'email' => $email, 'role' => $role, 'sites' => $role === 'admin' ? [] : audit_site_names($siteIds)]));
    $user = user_find($id);
    $token = invite_create($id);
    $mailResult = invite_send_email($user, $token);
    $inviteUrl = invite_url($token);

    // Einladungslink IMMER anzeigen (nicht nur bei Mail-Fehlschlag) - er
    // ist der einzige Moment, an dem der Klartext-Token existiert (siehe
    // invite_create()), und E-Mail-Zustellung ist keine Garantie.
    require __DIR__ . '/../src/templates/user_created.php';
}

function render_user_edit(int $id): void
{
    $user = user_find($id);
    if ($user === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Benutzer nicht gefunden') . "\n";
        exit;
    }
    $sites = site_list();
    $assignedSiteIds = array_column(user_sites_for($id), 'id');
    require __DIR__ . '/../src/templates/user_edit.php';
}

function handle_user_edit(int $id): void
{
    if (user_find($id) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Benutzer nicht gefunden') . "\n";
        exit;
    }
    $role = (string) ($_POST['role'] ?? 'viewer');
    if (!in_array($role, USER_ROLES, true)) {
        $role = 'viewer';
    }
    $user = user_find($id);
    $old = ['role' => (string) $user['role'], 'sites' => array_column(user_sites_for($id), 'name')];
    $siteIds = $role === 'admin' ? [] : parse_site_ids_from_post();
    user_set_role($id, $role);
    user_set_sites($id, $siteIds);
    $changes = audit_diff($old, ['role' => $role, 'sites' => audit_site_names($siteIds)]);
    if ($changes !== []) {
        audit_log('user', (string) $id, 'changed', $changes);
    }
    header('Location: /users?saved=1');
    exit;
}

function handle_delete_user(int $id): void
{
    $me = current_user();
    if ($me !== null && (int) $me['id'] === $id) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo '400 - ' . __('Der eigene Account kann nicht gelöscht werden') . "\n";
        exit;
    }
    $user = user_find($id);
    user_delete($id);
    if ($user !== null) {
        audit_log('user', (string) $id, 'deleted', [['field' => 'username', 'old' => (string) $user['username'], 'new' => '–']]);
    }
    header('Location: /users?deleted=1');
    exit;
}

/**
 * Konto deaktivieren bzw. reaktivieren (siehe user_set_disabled()). Das
 * eigene Konto ist ausgenommen - so bleibt immer mindestens ein aktiver
 * Admin uebrig, der die Sperre wieder aufheben kann.
 */
function handle_set_user_disabled(int $id, bool $disabled): void
{
    $me = current_user();
    if ($me !== null && (int) $me['id'] === $id) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo '400 - ' . __('Der eigene Account kann nicht deaktiviert werden') . "\n";
        exit;
    }
    $user = user_find($id);
    if ($user === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Benutzer nicht gefunden') . "\n";
        exit;
    }
    if (!users_disabled_supported()) {
        render_users(__('Deaktivieren ist nicht verfügbar: die Datenbankspalte users.disabled_at fehlt und konnte nicht angelegt werden.'));
        return;
    }
    if ((bool) $user['disabled'] !== $disabled) {
        user_set_disabled($id, $disabled);
        audit_log('user', (string) $id, $disabled ? 'disabled' : 'enabled', [[
            'field' => 'status',
            'old' => $disabled ? 'active' : 'disabled',
            'new' => $disabled ? 'disabled' : 'active',
        ]]);
    }
    header('Location: /users?' . ($disabled ? 'disabled=1' : 'enabled=1'));
    exit;
}

function handle_resend_invite(int $id): void
{
    $user = user_find($id);
    if ($user === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 - ' . __('Benutzer nicht gefunden') . "\n";
        exit;
    }
    if (!empty($user['disabled'])) {
        render_users(__('Dieser Benutzer ist deaktiviert - erst reaktivieren, dann erneut einladen.'));
        return;
    }
    $token = invite_create($id);
    $mailResult = invite_send_email($user, $token);
    $inviteUrl = invite_url($token);
    require __DIR__ . '/../src/templates/user_created.php';
}
