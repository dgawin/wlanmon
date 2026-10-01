<?php
declare(strict_types=1);

/**
 * Anbindung an die oeffentliche REST-API von OmniVista Cirrus (Alcatel-
 * Lucent Enterprise) - aktuell nur lesend, fuer genau einen Zweck: AP-Name
 * und Standort zu einer BSSID aufloesen (Roaming-Kandidaten-Tabelle in
 * device_detail.php, siehe README "OmniVista Cirrus"). Die eigentliche
 * Synchronisierung (API abfragen, Tabelle cirrus_aps aktuell halten)
 * macht der periodische Cronjob sync_cirrus_aps.php; hier stehen nur der
 * HTTP-Client dafuer und cirrus_lookup_ap() fuer den lesenden Zugriff aus
 * dem Web-Request heraus (kein Live-API-Aufruf pro Seitenaufruf).
 *
 * Cirrus liefert pro Geraet nur eine Basis-MAC, nicht die tatsaechlich
 * gesendete BSSID je Funkband/SSID (die weicht davon ab). Ein WIPS-
 * Endpunkt, der einzelne erkannte Radio-BSSIDs auflistet, wurde erprobt,
 * aber verworfen: er deckt live nachweislich nicht alle tatsaechlich
 * gesendeten BSSIDs ab (z.B. fehlte eine real per Klartext-SSID
 * gesendete BSSID, siehe iwconfig-Ausgabe des Geraets selbst) und sein
 * eigenes apName-Feld je Eintrag war ausserdem unzuverlaessig
 * (BSSIDs desselben physischen Radios wurden teils unterschiedlichen
 * APs zugeordnet). Stattdessen: nur Basis-MACs synchronisieren
 * (cirrus_fetch_device_names(), kleine, vollstaendige Liste ohne
 * Coverage-Luecken) und JEDE tatsaechlich im Probe-Scan gesehene BSSID
 * beim Lesen ueber das gemeinsame 5-Oktett-MAC-Praefix dagegen matchen
 * (cirrus_lookup_ap(), cirrus_mac_prefix5()) - deckt so auch BSSIDs ab,
 * die Cirrus' eigene WIPS-Erkennung nie gemeldet hat.
 */

/**
 * Verlauf der Kanalauslastung je AP-Radio (ein Eintrag je Sync und Radio),
 * für den Tab "Verlauf" der Geräteseite. Steht wortgleich in schema.sql;
 * sync_cirrus_aps.php legt die Tabelle damit bei Bedarf selbst an.
 * Eindeutig je (Radio-MAC, Band, Messzeitpunkt): liefert Cirrus zweimal
 * denselben Messzeitpunkt, bleibt es ein Eintrag (INSERT IGNORE).
 */
const CIRRUS_RADIO_HISTORY_DDL = 'CREATE TABLE IF NOT EXISTS cirrus_radio_history (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mac_address          VARCHAR(17) NOT NULL,
    band                 VARCHAR(16) NULL,
    channel              SMALLINT UNSIGNED NULL,
    channel_utilization  DECIMAL(5,2) NOT NULL,
    measured_at          DATETIME NOT NULL,
    UNIQUE KEY uq_radio_time (mac_address, band, measured_at),
    INDEX idx_measured_at (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

/**
 * Cirrus-Konfigurationsblock aus config.php, oder null wenn nicht bzw.
 * nicht vollstaendig konfiguriert (Integration ist rein optional - ohne
 * Konfiguration bleibt cirrus_ap_names() einfach leer).
 */
function cirrus_config(): ?array
{
    $cfg = wlanmon_config()['cirrus'] ?? null;
    if (!is_array($cfg)) {
        return null;
    }
    $required = ['base_url', 'org_id', 'app_id', 'app_secret', 'email', 'password'];
    foreach ($required as $key) {
        if (trim((string) ($cfg[$key] ?? '')) === '') {
            return null;
        }
    }
    $cfg['base_url'] = rtrim((string) $cfg['base_url'], '/');
    return $cfg;
}

/**
 * MAC-Adresse auf ein einheitliches Format normalisieren
 * (Kleinbuchstaben, mit Doppelpunkten, z.B. "aa:bb:cc:dd:ee:ff") - sowohl
 * Cirrus als auch die Probe-Scans liefern MACs nicht garantiert im
 * gleichen Format, ein direkter String-Vergleich waere bruechig.
 */
function cirrus_normalize_mac(string $mac): string
{
    $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac) ?? '');
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

/**
 * Kleinster gemeinsamer HTTP-Helfer fuer die beiden Cirrus-Aufrufe unten -
 * curl mit file_get_contents-Fallback, gleiches Muster wie
 * send_alert_telegram() in Alerting.php. Gibt bei einem Transportfehler
 * (kein HTTP-Status vorhanden) null zurueck, sonst immer [status, body].
 */
function cirrus_http_request(string $url, string $method, array $headers, ?string $body, int $timeout): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || $response === false) {
            return null;
        }
        return [$status, (string) $response];
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [$status, $response];
}

/**
 * Meldet sich per Application-ID/-Secret + Cirrus-Login an und liefert
 * das Bearer-Access-Token (siehe README "OmniVista Cirrus" fuer den
 * Endpunkt) - oder null bei einem Fehler (geloggt per error_log()).
 */
function cirrus_authenticate(array $cfg): ?string
{
    $url = $cfg['base_url'] . '/api/ov/v1/applications/authenticate';
    $payload = json_encode([
        'email' => $cfg['email'],
        'password' => $cfg['password'],
        'appId' => $cfg['app_id'],
        'appSecret' => $cfg['app_secret'],
    ]);
    $result = cirrus_http_request($url, 'POST', ['Content-Type: application/json'], $payload, 15);
    if ($result === null) {
        error_log('cirrus_authenticate: HTTP-Anfrage fehlgeschlagen (Netzwerk/Timeout)');
        return null;
    }
    [$status, $body] = $result;
    $decoded = json_decode($body, true);
    $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
    if ($status !== 200 || !is_string($token) || $token === '') {
        error_log("cirrus_authenticate: Login fehlgeschlagen (HTTP $status): " . substr($body, 0, 300));
        return null;
    }
    return $token;
}

/**
 * Erste 5 Oktette einer normalisierten MAC ("aa:bb:cc:dd:ee:ff" ->
 * "aa:bb:cc:dd:ee") - in dieser Cirrus-Organisation leiten APs ihre
 * Radio-/SSID-BSSIDs offenbar durch reines Veraendern des letzten
 * Oktetts von ihrer Basis-MAC ab (empirisch bestaetigt per iwconfig auf
 * einem AP: alle seine BSSIDs ueber alle Funkbaender/SSIDs teilen die
 * ersten 5 Oktette mit seiner eigenen Basis-MAC). Dient als
 * Verknuepfungsschluessel in cirrus_lookup_ap().
 */
function cirrus_mac_prefix5(string $normalizedMac): string
{
    return substr($normalizedMac, 0, 14);
}

/**
 * Vollstaendiges Geraete-Inventar der Organisation (ein Aufruf statt
 * Site-fuer-Site) - liefert Basis-MAC => ['ap_name' => .., 'site_name' =>
 * ..] je Geraet. null bei einem Fehler.
 */
function cirrus_fetch_device_names(array $cfg, string $token): ?array
{
    $url = $cfg['base_url'] . '/api/ov/v1/organizations/' . rawurlencode((string) $cfg['org_id']) . '/sites/devices';
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    $result = cirrus_http_request($url, 'GET', $headers, null, 30);
    if ($result === null) {
        error_log('cirrus_fetch_device_names: HTTP-Anfrage fehlgeschlagen (Netzwerk/Timeout)');
        return null;
    }
    [$status, $body] = $result;
    $decoded = json_decode($body, true);
    if ($status !== 200 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
        error_log("cirrus_fetch_device_names: Abruf fehlgeschlagen (HTTP $status): " . substr($body, 0, 300));
        return null;
    }

    $devices = [];
    foreach ($decoded['data'] as $d) {
        if (!is_array($d)) {
            continue;
        }
        // calculatedMacAddress als Fallback - manche Geraete liefern
        // macAddress leer, aber calculatedMacAddress gesetzt (siehe
        // Device-Modell des Referenz-SDK).
        $mac = cirrus_normalize_mac((string) ($d['macAddress'] ?? $d['calculatedMacAddress'] ?? ''));
        $name = $d['apName'] ?? $d['friendlyName'] ?? $d['name'] ?? null;
        if ($mac === '' || !is_string($name) || $name === '') {
            continue;
        }
        $siteName = is_array($d['site'] ?? null) ? ($d['site']['name'] ?? null) : null;
        $devices[$mac] = [
            'ap_name' => $name,
            'site_name' => is_string($siteName) && $siteName !== '' ? $siteName : null,
        ];
    }
    return $devices;
}

/**
 * Aktueller Funkzustand ALLER APs auf einmal (Kanal, Kanalauslastung,
 * Rauschpegel, Sendeleistung je Funkband).
 *
 * WICHTIG: Das im oeffentlichen OpenAPI-Dokument (/apidoc/swagger.json)
 * beschriebene "GET .../access-points/rf-details/{apMac}" (ein AP pro
 * Aufruf) liefert live nachweislich immer "radios": [] zurueck, obwohl
 * Cirrus selbst (Monitor -> Network -> Analytics -> RF Details in der
 * Web-UI) die Daten hat - vermutlich ein Bug/eine Einschraenkung dieses
 * einen dokumentierten Endpunkts. Per Browser-DevTools (Network-Tab der
 * Cirrus-UI) wurde stattdessen dieser Endpunkt gefunden, den die UI
 * selbst benutzt - funktioniert nachweislich auch mit unserem
 * Application-Bearer-Token, ist aber NICHT in der oeffentlichen
 * API-Doku aufgefuehrt (auch der Pfad weicht ab: kein "/ov/v1/" vor
 * "organizations"). Als undokumentiert kann er sich jederzeit ohne
 * Ankuendigung aendern - kein Grund zur Sorge, falls diese Funktion
 * irgendwann ploetzlich Fehler wirft: dann hat Cirrus ihn geaendert,
 * sync_cirrus_aps.php bricht in dem Fall kontrolliert mit Exit 1 ab statt
 * die Tabelle mit Muell zu befuellen.
 *
 * Paginiert wie cirrus_fetch_friendly_bssids() frueher (limit/offset,
 * "total" in der Antwort). Liefert eine Liste von ['mac'=>normalisierte
 * Basis-MAC, 'radio'=>.., 'band'=>.., 'channel'=>..,
 * 'channel_utilization'=>.., 'noise_floor_dbm'=>.., 'tx_power'=>..,
 * 'measured_at'=>..]. null bei einem Fehler.
 *
 * @return array<int, array{mac: string, radio: ?string, band: ?string, channel: ?int, channel_utilization: ?float, noise_floor_dbm: ?int, tx_power: ?float, measured_at: ?string}>|null
 */
function cirrus_fetch_all_ap_radios(array $cfg, string $token): ?array
{
    $orgId = (string) $cfg['org_id'];
    $url = $cfg['base_url'] . '/api/organizations/' . rawurlencode($orgId)
        . '/wlan-analytics/access-points/rf-details/pagination';
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    $limit = 200;
    $offset = 0;
    $total = null;
    $out = [];

    for ($page = 0; $page < 50; $page++) {
        $payload = json_encode([
            'fields' => null,
            'filters' => '{}',
            'limit' => $limit,
            'offset' => $offset,
            'organizationId' => $orgId,
            'scope' => 'org',
            'scopeId' => [$orgId],
            'search' => '',
            'searchFields' => '',
            'sort' => '[{"apMac":"DESC"}]',
        ]);
        $result = cirrus_http_request($url, 'POST', $headers, $payload, 20);
        if ($result === null) {
            error_log('cirrus_fetch_all_ap_radios: HTTP-Anfrage fehlgeschlagen (Netzwerk/Timeout)');
            return null;
        }
        [$status, $body] = $result;
        $decoded = json_decode($body, true);
        $rows = is_array($decoded) ? ($decoded['data']['result'] ?? null) : null;
        if ($status !== 200 || !is_array($rows)) {
            error_log("cirrus_fetch_all_ap_radios: Abruf fehlgeschlagen (HTTP $status): " . substr($body, 0, 300));
            return null;
        }

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $mac = cirrus_normalize_mac((string) ($r['apMac'] ?? ''));
            if ($mac === '') {
                continue;
            }
            $out[] = [
                'mac' => $mac,
                'radio' => isset($r['radio']) ? (string) $r['radio'] : null,
                'band' => isset($r['band']) ? (string) $r['band'] : null,
                'channel' => isset($r['channel']) ? (int) $r['channel'] : null,
                'channel_utilization' => isset($r['channelUtilization']) ? (float) $r['channelUtilization'] : null,
                'noise_floor_dbm' => isset($r['noiseFloor']) ? (int) $r['noiseFloor'] : null,
                'tx_power' => isset($r['txPower']) ? (float) $r['txPower'] : null,
                'measured_at' => isset($r['timestamp']) ? cirrus_iso_to_utc((string) $r['timestamp']) : null,
            ];
        }

        $total ??= (int) ($decoded['data']['total'] ?? count($rows));
        $offset += $limit;
        if ($rows === [] || $offset >= $total) {
            break;
        }
    }
    return $out;
}

/**
 * Cirrus liefert Zeitstempel als ISO-8601 mit Offset (z.B.
 * "2026-09-23T16:00:00+02:00") - fuer die Speicherung als MySQL DATETIME
 * (naiv, immer UTC, wie der Rest des Projekts) auf UTC normalisieren.
 * null bei einem nicht parsbaren Wert statt einer Exception.
 */
function cirrus_iso_to_utc(string $iso): ?string
{
    try {
        $dt = new DateTime($iso);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Baut aus Basis-MAC => ['ap_name'=>..,'site_name'=>..]
 * (cirrus_fetch_device_names() bzw. der Tabelle cirrus_aps) eine
 * Nachschlagetabelle 5-Oktett-Praefix => selbes Array (siehe
 * cirrus_mac_prefix5()) fuer cirrus_lookup_ap(). Bei mehreren Geraeten
 * mit demselben Praefix (Basis-MACs, die sich nur im letzten Oktett
 * unterscheiden) ist die Zuordnung nicht eindeutig - der Praefix wird
 * dann bewusst weggelassen statt zu raten.
 *
 * @param array<string, array{ap_name: ?string, site_name: ?string}> $devicesByMac
 * @return array<string, array{ap_name: ?string, site_name: ?string}>
 */
function cirrus_build_prefix_map(array $devicesByMac): array
{
    $byPrefix = [];
    $ambiguous = [];
    foreach ($devicesByMac as $mac => $info) {
        $prefix = cirrus_mac_prefix5((string) $mac);
        if (isset($byPrefix[$prefix]) && $byPrefix[$prefix] !== $info) {
            $ambiguous[$prefix] = true;
            continue;
        }
        $byPrefix[$prefix] = $info;
    }
    foreach (array_keys($ambiguous) as $prefix) {
        unset($byPrefix[$prefix]);
    }
    return $byPrefix;
}

/**
 * AP-Name/Standort zu einer beliebigen, tatsaechlich gescannten BSSID
 * aufloesen - liest das lokal zwischengespeicherte Basis-MAC-Inventar
 * (Tabelle cirrus_aps, von sync_cirrus_aps.php aktuell gehalten) und
 * matcht per 5-Oktett-Praefix (siehe cirrus_mac_prefix5()), statt eine
 * exakte BSSID-Uebereinstimmung zu verlangen - deckt so auch BSSIDs ab,
 * die Cirrus nie einzeln gemeldet hat. Kein Live-API-Aufruf, damit ein
 * Seitenaufruf im Dashboard nie auf Cirrus warten muss. null, wenn die
 * Integration nicht konfiguriert ist, noch nie synchronisiert wurde,
 * oder das Praefix zu keinem (eindeutigen) Geraet passt.
 *
 * @return array{ap_name: ?string, site_name: ?string}|null
 */
function cirrus_lookup_ap(string $bssid): ?array
{
    static $prefixMap = null;
    if ($prefixMap === null) {
        $rows = db()->query('SELECT mac_address, ap_name, site_name FROM cirrus_aps')->fetchAll();
        $devicesByMac = [];
        foreach ($rows as $row) {
            $devicesByMac[$row['mac_address']] = ['ap_name' => $row['ap_name'], 'site_name' => $row['site_name']];
        }
        $prefixMap = cirrus_build_prefix_map($devicesByMac);
    }
    return $prefixMap[cirrus_mac_prefix5(cirrus_normalize_mac($bssid))] ?? null;
}

/**
 * Cirrus-Funkdaten (cirrus_ap_radios) zu einer gescannten BSSID: gleicher
 * AP (5-Oktett-Praefix wie in cirrus_lookup_ap()) UND gleicher Kanal. Der
 * Kanal dient als Band-Zuordnung - hat der AP seit dem letzten Sync den
 * Kanal gewechselt, gibt es bewusst keinen Treffer statt eines Werts vom
 * falschen Radio. null, wenn nichts passt oder die Integration nicht
 * konfiguriert/synchronisiert ist. Fuer den Vergleich mit dem BSS-Load-
 * Wert aus dem Probe-Scan (Scan-Tabelle in device_detail.php).
 *
 * @return array<string, mixed>|null eine Zeile aus cirrus_ap_radios
 */
function cirrus_lookup_radio(string $bssid, ?int $channel): ?array
{
    static $radiosByPrefix = null;
    if ($radiosByPrefix === null) {
        $radiosByPrefix = [];
        $macByPrefix = [];
        foreach (db()->query('SELECT mac_address FROM cirrus_aps')->fetchAll() as $row) {
            $prefix = cirrus_mac_prefix5((string) $row['mac_address']);
            // Mehrere Geraete mit demselben Praefix: nicht eindeutig, weglassen
            // (gleiche Regel wie cirrus_build_prefix_map()).
            $macByPrefix[$prefix] = array_key_exists($prefix, $macByPrefix) ? null : $row['mac_address'];
        }
        $prefixByMac = array_flip(array_filter($macByPrefix));
        foreach (db()->query('SELECT * FROM cirrus_ap_radios')->fetchAll() as $radio) {
            $prefix = $prefixByMac[$radio['mac_address']] ?? null;
            if ($prefix !== null) {
                $radiosByPrefix[$prefix][] = $radio;
            }
        }
    }
    if ($channel === null) {
        return null;
    }
    foreach ($radiosByPrefix[cirrus_mac_prefix5(cirrus_normalize_mac($bssid))] ?? [] as $radio) {
        if ((int) $radio['channel'] === $channel) {
            return $radio;
        }
    }
    return null;
}

/**
 * Status der Cirrus-Anbindung fuers Dashboard (siehe "OmniVista Cirrus"-
 * Anzeige in der Geraeteliste): ob ueberhaupt konfiguriert, wie viele
 * Basis-MACs zuletzt synchronisiert wurden und wann. sync_cirrus_aps.php
 * schreibt cirrus_aps nur bei einem ERFOLGREICHEN Lauf neu (DELETE+INSERT
 * in einer Transaktion, siehe dort) - ein fehlschlagender Lauf laesst die
 * Tabelle unveraendert, wodurch "last_sync_at" von selbst veraltet und
 * damit schon fuer sich genommen ein Warnsignal ist, ohne dass hier
 * zusaetzlich der Erfolg/Fehlschlag einzelner Läufe protokolliert werden
 * muesste.
 *
 * @return array{configured: bool, ap_count: int, last_sync_at: ?string}
 */
function cirrus_sync_status(): array
{
    if (cirrus_config() === null) {
        return ['configured' => false, 'ap_count' => 0, 'last_sync_at' => null];
    }
    $row = db()->query('SELECT COUNT(*) AS cnt, MAX(updated_at) AS last_sync FROM cirrus_aps')->fetch();
    return [
        'configured' => true,
        'ap_count' => (int) ($row['cnt'] ?? 0),
        'last_sync_at' => $row['last_sync'] ?? null,
    ];
}

/**
 * Ab wann ein zurueckliegender Cirrus-Sync als "veraltet" gilt (Warn-
 * Anzeige) - deutlich grosszuegiger als das uebliche Stundenintervall
 * aus der README-Beispiel-Cronzeile, damit ein einzelner verpasster oder
 * verspaeteter Lauf nicht sofort als Stoerung erscheint.
 */
function cirrus_sync_is_stale(?string $lastSyncAtUtc, int $thresholdSeconds = 10800): bool
{
    if (empty($lastSyncAtUtc)) {
        return true;
    }
    try {
        $lastSync = new DateTime($lastSyncAtUtc, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return true;
    }
    $now = new DateTime('now', new DateTimeZone('UTC'));
    return ($now->getTimestamp() - $lastSync->getTimestamp()) > $thresholdSeconds;
}

/**
 * Fuer die "Access Points"-Seite: alle bekannten APs (aus cirrus_aps)
 * mit ihren zuletzt synchronisierten Radios (cirrus_ap_radios), nach
 * AP-Name sortiert. Ein AP ohne (noch) synchronisierte Radios liefert
 * eine leere 'radios'-Liste statt ganz zu fehlen, damit er trotzdem in
 * der Uebersicht auftaucht.
 *
 * @return array<int, array{mac_address: string, ap_name: ?string, site_name: ?string, radios: array<int, array<string, mixed>>}>
 */
function cirrus_ap_overview(): array
{
    $aps = db()->query('SELECT mac_address, ap_name, site_name FROM cirrus_aps ORDER BY ap_name')->fetchAll();
    $radiosByMac = [];
    $radioRows = db()->query(
        'SELECT * FROM cirrus_ap_radios ORDER BY mac_address, radio'
    )->fetchAll();
    foreach ($radioRows as $row) {
        $radiosByMac[$row['mac_address']][] = $row;
    }

    $out = [];
    foreach ($aps as $ap) {
        $radios = $radiosByMac[$ap['mac_address']] ?? [];
        if ($radios === []) {
            // cirrus_aps enthaelt das komplette Cirrus-Geraeteinventar,
            // nicht nur APs (z.B. auch Switches - die "Access Points"-
            // Seite soll aber nur echte APs zeigen). Ein Geraet ohne
            // Funk-Radios in cirrus_ap_radios ist strukturell kein AP
            // (Switches senden nie WLAN-Radiodaten) - einfacher und
            // robuster als ein Geraetetyp-Feld aus dem Inventar
            // auszuwerten, das je nach Cirrus-Organisation uneinheitlich
            // befuellt sein kann.
            continue;
        }
        $out[] = [
            'mac_address' => $ap['mac_address'],
            'ap_name' => $ap['ap_name'],
            'site_name' => $ap['site_name'],
            'radios' => $radios,
        ];
    }
    return $out;
}
