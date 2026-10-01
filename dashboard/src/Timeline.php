<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Cirrus.php';
require_once __DIR__ . '/Response.php';

/*
 * Daten für den Tab "Verlauf" der Geräteseite (GET /devices/<id>/timeline,
 * gezeichnet von static/timeline.js). Beantwortet die Frage aus dem Alltag:
 * Wie ausgelastet waren die eigenen AP-Radios, wie war die Latenz - und
 * fielen Spitzen mit den eigenen iperf3-Tests zusammen?
 *
 * - Auslastung: BSS Load (QBSS) der Ziel-SSIDs aus den Scans, je AP-Radio
 *   (gleicher AP + Band; mehrere SSIDs eines Radios teilen sich den Wert),
 *   dazu der Cirrus-Verlauf desselben Radios (cirrus_radio_history).
 * - Latenz: Ping-RTT und HTTP-Antwortzeit des Portal-Checks je Ziel-SSID.
 * - iperf3: Zeiträume der eigenen Durchsatztests (ab Probe 1.0.1.13 exakt,
 *   bei älteren Tests aus Testende und Dauer geschätzt).
 *
 * Zeiten gehen als Millisekunden seit 1970 (UTC) raus, fertig für die Achse.
 */

/** Erlaubte Zeiträume in Stunden (Buttons im Tab). */
const TIMELINE_HOURS = [1, 3, 6, 24, 72];

/** Längster frei wählbarer Zeitraum (von/bis) in Tagen. */
const TIMELINE_MAX_SPAN_DAYS = 31;

/** Höchstens so viele Scans je Abruf auswerten - darüber jeden k-ten. */
const TIMELINE_MAX_SCANS = 1500;

function timeline_ts_ms(?string $utc): ?int
{
    if ($utc === null || $utc === '') {
        return null;
    }
    try {
        return (int) ((new DateTime($utc, new DateTimeZone('UTC')))->format('U')) * 1000;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * "2.4GHz"/"5 GHz"/Frequenz in MHz -> "2.4" | "5" | "6" (ohne str_starts_with/
 * mixed - das Projekt zielt auf PHP >= 7.4, siehe _nav.php).
 *
 * @param string|int|float|null $bandOrFreq
 */
function timeline_band($bandOrFreq): ?string
{
    if (is_numeric($bandOrFreq)) {
        $f = (float) $bandOrFreq;
        return $f < 3000 ? '2.4' : ($f < 5925 ? '5' : '6');
    }
    $b = strtolower(str_replace([' ', ','], ['', '.'], (string) $bandOrFreq));
    foreach (['2.4', '5', '6'] as $x) {
        if (strpos($b, $x) === 0) {
            return $x;
        }
    }
    return null;
}

/**
 * Leere Auslastungsreihe für ein AP-Radio (Schlüssel "<5-Oktett-Präfix>|<Band>").
 * Name aus Cirrus, sonst vorläufig die BSSID - timeline_build() ersetzt das
 * dann durch die SSIDs des Radios (siehe timeline_label_unknown_ap()).
 *
 * @param int|string|null $channel
 * @return array<string, mixed>
 */
function timeline_radio_series(string $key, string $bssid, string $band, $channel): array
{
    $bandLabels = ['2.4' => __('2,4 GHz'), '5' => '5 GHz', '6' => '6 GHz'];
    $ap = cirrus_lookup_ap($bssid);
    return [
        'key' => $key,
        'prefix' => explode('|', $key)[0],
        'bssid' => $bssid,
        'band' => $band,
        'ap' => $ap['ap_name'] ?? null,
        'label' => ($ap['ap_name'] ?? ($bssid !== '' ? $bssid : '?')) . ' · ' . ($bandLabels[$band] ?? $band),
        'channel' => $channel,
        'points' => [],
        'cirrus' => [],
        'probe' => [],
    ];
}

/**
 * Beschriftung für ein Radio, dessen AP Cirrus nicht kennt (andere
 * Organisation, Fremdhersteller): SSIDs des Radios und die BSSID, z.B.
 * "Gast-WLAN · aa:bb:cc:dd:ee:01 · 2,4 GHz" - statt nur der BSSID, bei der
 * man raten müsste, welches Netz dahintersteckt. Mehr als zwei SSIDs werden
 * gekürzt ("A/B +2").
 *
 * @param array<int, string> $ssids
 */
function timeline_label_unknown_ap(array $ssids, string $bssid, string $band): string
{
    $bandLabels = ['2.4' => __('2,4 GHz'), '5' => '5 GHz', '6' => '6 GHz'];
    $ssids = array_values(array_filter(array_map('strval', $ssids), fn(string $s): bool => $s !== ''));
    sort($ssids, SORT_STRING);
    $name = implode('/', array_slice($ssids, 0, 2)) . (count($ssids) > 2 ? ' +' . (count($ssids) - 2) : '');
    $parts = array_filter([$name, $bssid !== '' ? $bssid : null, $bandLabels[$band] ?? $band]);
    return implode(' · ', $parts);
}

/**
 * Zeitraum aus der Anfrage: entweder ?hours=<Voreinstellung> (bis jetzt) oder
 * ?from=<ms>&to=<ms> (Millisekunden seit 1970, so wie der Browser die
 * Eingabe "von/bis" in seiner Ortszeit umrechnet - keine Zeitzonen-Raterei
 * auf dem Server). Ungültig -> Fehlermeldung statt Zeitraum.
 *
 * @param array<string, mixed> $query $_GET
 * @return array{0: ?DateTime, 1: ?DateTime, 2: ?int, 3: ?string} [von, bis, Stunden|null, Fehler|null]
 */
function timeline_range_from_query(array $query): array
{
    $utc = new DateTimeZone('UTC');
    if (isset($query['from'], $query['to'])) {
        $fromMs = filter_var($query['from'], FILTER_VALIDATE_INT);
        $toMs = filter_var($query['to'], FILTER_VALIDATE_INT);
        if ($fromMs === false || $toMs === false || $fromMs >= $toMs) {
            return [null, null, null, __('Ungültiger Zeitraum: „von“ muss vor „bis“ liegen.')];
        }
        if ($toMs - $fromMs > TIMELINE_MAX_SPAN_DAYS * 86400000) {
            return [null, null, null, __('Zeitraum zu lang (höchstens %d Tage).', TIMELINE_MAX_SPAN_DAYS)];
        }
        $from = (new DateTime('@' . intdiv($fromMs, 1000)))->setTimezone($utc);
        $to = (new DateTime('@' . intdiv($toMs, 1000)))->setTimezone($utc);
        return [$from, $to, null, null];
    }
    $hours = (int) ($query['hours'] ?? 24);
    $hours = in_array($hours, TIMELINE_HOURS, true) ? $hours : 24;
    $to = new DateTime('now', $utc);
    return [(clone $to)->modify("-{$hours} hours"), $to, $hours, null];
}

/**
 * @param int|null $hours Voreinstellung (1/3/6/24/72 h bis jetzt), null bei frei gewähltem Zeitraum
 * @return array<string, mixed> JSON-fähig, siehe Kopfkommentar
 */
function timeline_build(array $device, DateTime $from, DateTime $to, ?int $hours = null): array
{
    $deviceId = (string) $device['id'];
    $since = $from->format('Y-m-d H:i:s');
    $until = $to->format('Y-m-d H:i:s');
    $pdo = db();

    // Ziel-SSIDs: laut Gerätekonfiguration und alle, die im Zeitraum getestet wurden.
    $config = json_decode((string) ($device['config'] ?? ''), true);
    $targets = [];
    foreach ((array) ($config['connection_tests']['targets'] ?? []) as $t) {
        if (is_array($t) && ($t['ssid'] ?? '') !== '') {
            $targets[(string) $t['ssid']] = true;
        }
    }
    $iperfDuration = max(1, (int) ($config['connection_tests']['iperf3_duration_seconds'] ?? 5));

    $stmt = $pdo->prepare(
        "SELECT client_timestamp, received_at, data FROM measurements
         WHERE device_id = ? AND kind = 'connection_test' AND received_at >= ? AND received_at <= ?
         ORDER BY received_at"
    );
    $stmt->execute([$deviceId, $since, $until]);
    $tests = $stmt->fetchAll();
    foreach ($tests as $row) {
        $d = json_decode((string) $row['data'], true) ?: [];
        if (($d['ssid'] ?? '') !== '') {
            $targets[(string) $d['ssid']] = true;
        }
    }

    // --- Latenz, iperf3-Zeiträume und eigene Kanalmessung aus den Connection-Tests ---
    $latency = [];
    $iperf = [];
    $probeLoad = [];  // Radio-Schlüssel -> ['bssid' => .., 'points' => [[ts, busy_pct], ...]]
    $ssidsByRadio = [];  // Radio-Schlüssel -> [SSID => true], für die Beschriftung ohne Cirrus
    foreach ($tests as $row) {
        $d = json_decode((string) $row['data'], true) ?: [];
        $ssid = (string) ($d['ssid'] ?? '');
        $ts = timeline_ts_ms($row['client_timestamp'] ?? $row['received_at']);
        if ($ssid === '' || $ts === null) {
            continue;
        }
        // Grundlast des verbundenen Kanals laut Probe (survey dump zwischen DHCP
        // und Ping-Ende, Probe ab 1.0.1.16) - demselben AP-Radio zugeordnet wie
        // der BSS Load: 5-Oktett-Präfix der verbundenen BSSID + Band.
        $cl = $d['channel_load'] ?? null;
        $linkBssid = cirrus_normalize_mac((string) ($d['link']['bssid'] ?? ''));
        if (is_array($cl) && is_numeric($cl['baseline']['busy_pct'] ?? null) && $linkBssid !== '') {
            $band = timeline_band($cl['frequency_mhz'] ?? null);
            if ($band !== null) {
                $key = cirrus_mac_prefix5($linkBssid) . '|' . $band;
                $probeLoad[$key]['bssid'] = $linkBssid;
                $ssidsByRadio[$key][$ssid] = true;
                $probeLoad[$key]['channel'] = $cl['channel'] ?? null;
                $probeLoad[$key]['points'][] = [$ts, round((float) $cl['baseline']['busy_pct'], 1)];
            }
        }
        $latency[$ssid] ??= ['ssid' => $ssid, 'ping' => [], 'http' => []];
        if (isset($d['ping_rtt_avg_ms']) && is_numeric($d['ping_rtt_avg_ms'])) {
            $latency[$ssid]['ping'][] = [$ts, round((float) $d['ping_rtt_avg_ms'], 2)];
        }
        $http = $d['captive_portal']['response_ms'] ?? null;
        if (is_numeric($http)) {
            $latency[$ssid]['http'][] = [$ts, round((float) $http, 1)];
        }
        if (isset($d['iperf3_mbps']) || isset($d['iperf3_download_mbps']) || !empty($d['iperf3_started_at'])) {
            // ISO-8601 mit Offset (Probe: UTC) - DateTime übernimmt den Offset.
            $start = timeline_ts_ms(isset($d['iperf3_started_at']) ? (string) $d['iperf3_started_at'] : null);
            $end = timeline_ts_ms(isset($d['iperf3_ended_at']) ? (string) $d['iperf3_ended_at'] : null);
            $approx = $start === null || $end === null;
            if ($approx) {
                // Ältere Probe: Testende ~ Zeitstempel, Dauer aus der Konfiguration.
                $runs = isset($d['iperf3_download_mbps']) ? 2 : 1;
                $end = $ts;
                $start = $ts - ($runs * $iperfDuration + 3) * 1000;
            }
            $iperf[] = [
                'from' => $start,
                'to' => max($end, $start + 1000),
                'ssid' => $ssid,
                'up' => isset($d['iperf3_mbps']) ? round((float) $d['iperf3_mbps'], 1) : null,
                'down' => isset($d['iperf3_download_mbps']) ? round((float) $d['iperf3_download_mbps'], 1) : null,
                'bitrate' => isset($d['iperf3_bitrate_mbps']) ? (float) $d['iperf3_bitrate_mbps'] : null,
                'approx' => $approx,
            ];
        }
    }

    // --- BSS Load je eigenem AP-Radio aus den Scans (ggf. ausgedünnt) ---
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM measurements WHERE device_id = ? AND kind = 'scan' AND received_at >= ? AND received_at <= ?");
    $cnt->execute([$deviceId, $since, $until]);
    $every = max(1, (int) ceil(((int) $cnt->fetchColumn()) / TIMELINE_MAX_SCANS));
    $stmt = $pdo->prepare(
        "SELECT client_timestamp, received_at, data FROM measurements
         WHERE device_id = ? AND kind = 'scan' AND received_at >= ? AND received_at <= ? AND MOD(id, ?) = 0
         ORDER BY received_at"
    );
    $stmt->execute([$deviceId, $since, $until, $every]);

    $util = [];
    foreach ($stmt as $row) {
        $ts = timeline_ts_ms($row['client_timestamp'] ?? $row['received_at']);
        $d = json_decode((string) $row['data'], true) ?: [];
        if ($ts === null) {
            continue;
        }
        $perRadio = [];
        foreach ((array) ($d['networks'] ?? []) as $n) {
            if (!is_array($n) || !isset($targets[scan_ssid($n['ssid'] ?? null)])
                || !is_numeric($n['channel_utilization_pct'] ?? null)) {
                continue;
            }
            $band = timeline_band($n['frequency_mhz'] ?? null);
            $mac = cirrus_normalize_mac((string) ($n['bssid'] ?? ''));
            if ($band === null || $mac === '') {
                continue;
            }
            $prefix = cirrus_mac_prefix5($mac);
            $key = $prefix . '|' . $band;
            $ssidsByRadio[$key][scan_ssid($n['ssid'] ?? null)] = true;
            $value = (float) $n['channel_utilization_pct'];
            if (!isset($perRadio[$key]) || $value > $perRadio[$key]['value']) {
                $perRadio[$key] = ['value' => $value, 'n' => $n, 'band' => $band, 'prefix' => $prefix];
            }
        }
        foreach ($perRadio as $key => $r) {
            $util[$key] ??= timeline_radio_series($key, (string) ($r['n']['bssid'] ?? ''), $r['band'], $r['n']['channel'] ?? null);
            $util[$key]['channel'] = $r['n']['channel'] ?? $util[$key]['channel'];
            $util[$key]['points'][] = [$ts, round($r['value'], 1)];
        }
    }

    // Eigene Kanalmessung der Probe dazu - auch für Radios ohne BSS Load
    // (AP sendet kein QBSS), dann ist sie die einzige Quelle.
    foreach ($probeLoad as $key => $pl) {
        [, $band] = explode('|', $key);
        $util[$key] ??= timeline_radio_series($key, $pl['bssid'], $band, $pl['channel']);
        $util[$key]['probe'] = $pl['points'];
    }

    // --- Cirrus-Verlauf derselben Radios ---
    if ($util !== []) {
        try {
            $macByPrefix = [];
            foreach ($pdo->query('SELECT mac_address FROM cirrus_aps')->fetchAll() as $a) {
                $p = cirrus_mac_prefix5((string) $a['mac_address']);
                $macByPrefix[$p] = array_key_exists($p, $macByPrefix) ? null : $a['mac_address'];
            }
            $h = $pdo->prepare(
                'SELECT mac_address, band, channel_utilization, measured_at
                 FROM cirrus_radio_history WHERE measured_at >= ? AND measured_at <= ? ORDER BY measured_at'
            );
            $h->execute([$since, $until]);
            $prefixByMac = array_flip(array_filter($macByPrefix));
            foreach ($h as $row) {
                $prefix = $prefixByMac[$row['mac_address']] ?? null;
                $band = timeline_band($row['band']);
                $key = $prefix . '|' . $band;
                $ts = timeline_ts_ms($row['measured_at']);
                if ($prefix !== null && $band !== null && $ts !== null && isset($util[$key])) {
                    $util[$key]['cirrus'][] = [$ts, round((float) $row['channel_utilization'], 1)];
                }
            }
        } catch (PDOException $e) {
            // Tabelle fehlt noch (Cirrus nicht eingerichtet/noch nie synchronisiert) - dann ohne.
        }
    }

    foreach ($util as $key => $u) {
        if ($u['ap'] === null) {
            $util[$key]['label'] = timeline_label_unknown_ap(array_keys($ssidsByRadio[$key] ?? []), (string) $u['bssid'], (string) $u['band']);
        }
    }

    // Feste Reihenfolge = feste Farbe je Radio bzw. SSID (Farbe folgt dem Objekt, nicht dem Rang).
    uasort($util, fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
    ksort($latency, SORT_STRING);

    // Alphabetisch wie im Spektrum (device_detail.php), damit eine SSID in
    // beiden Ansichten dieselbe Farbe hat - unabhängig davon, welche SSIDs
    // im gewählten Zeitraum gerade Latenzwerte haben.
    $targetList = array_map('strval', array_keys($targets));
    sort($targetList, SORT_STRING);

    return [
        'targets' => $targetList,
        'from' => (int) $from->format('U') * 1000,
        'to' => (int) $to->format('U') * 1000,
        'hours' => $hours,
        'scan_sample_every' => $every,
        'util' => array_values(array_map(function (array $u): array {
            unset($u['prefix']);
            return $u;
        }, $util)),
        'latency' => array_values($latency),
        'iperf' => $iperf,
    ];
}
