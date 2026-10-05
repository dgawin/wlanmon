<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Cirrus.php';

/*
 * Anmeldeversuche aus der Authentifizierungs-Historie von OmniVista Cirrus
 * zu einem Connection-Test der Probe (Fehlerspalte der Testtabelle, Link
 * "Cirrus"). Zuordnung ueber die MAC, mit der die Probe getestet hat
 * (mac_address im Testergebnis, ggf. zufaellig) und den Testzeitpunkt.
 *
 * Endpunkt wie in der Cirrus-Oberflaeche (Access -> Authentication History),
 * nicht oeffentlich dokumentiert - Pfad ohne "/ov/v1/" wie bei
 * cirrus_fetch_all_ap_radios(). Antwort: {"data": {"total": n, "list": [..]}},
 * sessionStart in Millisekunden seit 1970. Siehe tools/cirrus_auth_records.php.
 *
 * Abgefragt wird erst auf Klick, nicht bei jedem Seitenaufruf. Ergebnisse zu
 * Tests, die alt genug sind (CIRRUS_AUTH_FINAL_AFTER), werden in
 * cirrus_auth_lookups gespeichert und mit der Messung geloescht.
 */

/** Zeitfenster um den Teststart: davor (Uhrabweichung), danach (Scan, Assoziation, 802.1X). */
const CIRRUS_AUTH_WINDOW_BEFORE = 120;
const CIRRUS_AUTH_WINDOW_AFTER = 240;
/** Erst danach gilt die Historie zu einem Test als vollstaendig und wird gespeichert. */
const CIRRUS_AUTH_FINAL_AFTER = 900;

function cirrus_auth_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS cirrus_auth_lookups (
            measurement_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            fetched_at      DATETIME NOT NULL,
            records         MEDIUMTEXT NOT NULL,
            CONSTRAINT fk_cirrus_auth_measurement FOREIGN KEY (measurement_id)
                REFERENCES measurements(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/**
 * Alle Eintraege der Authentifizierungs-Historie in [start, end], neueste
 * zuerst. null bei einem Fehler (geloggt per error_log()).
 *
 * @return array<int, array<string, mixed>>|null
 */
function cirrus_fetch_auth_records(array $cfg, string $token, DateTimeImmutable $start, DateTimeImmutable $end): ?array
{
    $base = $cfg['base_url'] . '/api/organizations/' . rawurlencode((string) $cfg['org_id'])
        . '/am/access-records/authentication-history-records?';
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $limit = 100;
    $out = [];
    for ($offset = 0; $offset < 2000; $offset += $limit) {
        $query = http_build_query([
            'limit' => $limit,
            'offset' => $offset,
            'startDate' => $start->format(DATE_ATOM),
            'endDate' => $end->format(DATE_ATOM),
            'sort' => '[{"sessionStart":"DESC"}]',
            'filters' => '{}',
        ], '', '&', PHP_QUERY_RFC3986);
        $result = cirrus_http_request($base . $query, 'GET', $headers, null, 20);
        if ($result === null) {
            error_log('cirrus_fetch_auth_records: HTTP-Anfrage fehlgeschlagen (Netzwerk/Timeout)');
            return null;
        }
        [$status, $body] = $result;
        $decoded = json_decode($body, true);
        $rows = is_array($decoded) ? ($decoded['data']['list'] ?? null) : null;
        if ($status !== 200 || !is_array($rows)) {
            error_log("cirrus_fetch_auth_records: Abruf fehlgeschlagen (HTTP $status): " . substr($body, 0, 300));
            return null;
        }
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        $total = (int) ($decoded['data']['total'] ?? 0);
        if (count($rows) < $limit || $offset + $limit >= $total) {
            break;
        }
    }
    return $out;
}

/** Ein Cirrus-Eintrag auf die Felder reduziert, die das Dashboard zeigt. */
function cirrus_auth_record_clean(array $r): array
{
    $str = fn(string $k): ?string => isset($r[$k]) && is_scalar($r[$k]) && (string) $r[$k] !== '' ? (string) $r[$k] : null;
    $ms = isset($r['sessionStart']) && is_numeric($r['sessionStart']) ? (int) $r['sessionStart'] : null;
    $apMac = $str('nasDeviceMac');
    $ap = $apMac !== null ? cirrus_lookup_ap($apMac) : null;
    return [
        'result' => $str('authResult'),
        'reject_reason' => $str('rejectReason'),
        'terminate_reason' => $str('terminateReason'),
        'session_start' => $ms !== null ? gmdate('Y-m-d H:i:s', intdiv($ms, 1000)) : null,
        'session_time' => $str('sessionTime'),
        'auth_type' => $str('authType'),
        'auth_method' => $str('authMethod'),
        'auth_resource' => $str('authResource'),
        'username' => $str('username'),
        'ssid' => $str('ssid'),
        'ap_mac' => $apMac,
        'ap_name' => $ap['ap_name'] ?? null,
        'access_policy' => $str('accessPolicy'),
        'role' => $str('finalAccessRoleProfile'),
        'vlan' => $str('finalDynamicVlanId'),
        'device_ip' => $str('deviceIpv4'),
    ];
}

/**
 * Anmeldeversuche zu einem Connection-Test. status: "ok" (records gefunden),
 * "none" (Cirrus kennt im Zeitfenster keinen Versuch dieser MAC), "no_mac"
 * (Test ohne mac_address, aeltere Probe), "disabled" (Cirrus nicht
 * eingerichtet), "error" (Cirrus nicht erreichbar/Anmeldung fehlgeschlagen).
 *
 * @return array{status: string, records: array<int, array<string, mixed>>, cached: bool, window: array{0: string, 1: string}|null}
 */
function cirrus_auth_for_test(array $measurement): array
{
    $empty = fn(string $status): array => ['status' => $status, 'records' => [], 'cached' => false, 'window' => null];
    $cfg = cirrus_config();
    if ($cfg === null) {
        return $empty('disabled');
    }
    $data = json_decode((string) $measurement['data'], true) ?: [];
    $mac = cirrus_normalize_mac((string) ($data['mac_address'] ?? ''));
    if ($mac === '') {
        return $empty('no_mac');
    }

    cirrus_auth_ensure_table();
    $stmt = db()->prepare('SELECT records FROM cirrus_auth_lookups WHERE measurement_id = ?');
    $stmt->execute([(int) $measurement['id']]);
    $cached = $stmt->fetchColumn();
    if (is_string($cached)) {
        $records = json_decode($cached, true) ?: [];
        return ['status' => $records ? 'ok' : 'none', 'records' => $records, 'cached' => true, 'window' => null];
    }

    $utc = new DateTimeZone('UTC');
    $testAt = new DateTimeImmutable((string) ($measurement['client_timestamp'] ?? $measurement['received_at']), $utc);
    $start = $testAt->modify('-' . CIRRUS_AUTH_WINDOW_BEFORE . ' seconds');
    $end = $testAt->modify('+' . CIRRUS_AUTH_WINDOW_AFTER . ' seconds');

    $token = cirrus_authenticate($cfg);
    $rows = $token !== null ? cirrus_fetch_auth_records($cfg, $token, $start, $end) : null;
    if ($rows === null) {
        return $empty('error');
    }
    $records = [];
    foreach ($rows as $r) {
        if (cirrus_normalize_mac((string) ($r['deviceMac'] ?? '')) === $mac) {
            $records[] = cirrus_auth_record_clean($r);
        }
    }
    // Aelteste zuerst - der Ablauf eines Tests liest sich dann von oben nach unten.
    usort($records, fn(array $a, array $b): int => strcmp((string) $a['session_start'], (string) $b['session_start']));

    if (time() - $testAt->getTimestamp() >= CIRRUS_AUTH_FINAL_AFTER) {
        db()->prepare('INSERT INTO cirrus_auth_lookups (measurement_id, fetched_at, records) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), records = VALUES(records)')
            ->execute([(int) $measurement['id'], gmdate('Y-m-d H:i:s'), json_encode($records, JSON_UNESCAPED_UNICODE)]);
    }
    return [
        'status' => $records ? 'ok' : 'none',
        'records' => $records,
        'cached' => false,
        'window' => [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')],
    ];
}
