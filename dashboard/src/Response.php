<?php
declare(strict_types=1);

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(int $status, string $message): void
{
    json_response(['detail' => $message], $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        json_error(400, 'Ungültiger oder fehlender JSON-Body');
    }
    return $data;
}

/** HTML-Escaping-Kurzform für die Templates. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * SSID aus einem Scan in Klartext. Ältere Probes schicken die SSID so, wie
 * `iw` sie ausgibt: nicht druckbare und Nicht-ASCII-Bytes maskiert als
 * "\xNN" ("Büro" -> "B\xc3\xbcro"). Neuere Probes dekodieren selbst, dann
 * ändert sich hier nichts. Liefert '' für verborgene Netze, auch für die,
 * die statt einer leeren SSID lauter Null-Bytes senden.
 */
function scan_ssid(?string $raw): string
{
    $raw = (string) $raw;
    $decoded = preg_replace_callback(
        '/\\\\x([0-9a-fA-F]{2})/',
        fn(array $m): string => chr((int) hexdec($m[1])),
        $raw
    );
    if (trim($decoded, "\0") === '') {
        return '';
    }
    // Kein gültiges UTF-8 (z.B. Latin-1-SSID): maskierte Form behalten,
    // sonst würde e() daraus einen leeren String machen.
    return preg_match('//u', $decoded) === 1 ? $decoded : $raw;
}

/**
 * Zeitzone für die Anzeige im Dashboard. Alle Zeitstempel werden intern
 * explizit als UTC gespeichert (siehe Measurement.php/Device.php) -
 * unabhängig davon, wie der Root-Server selbst konfiguriert ist. Für
 * die Anzeige hier zentral in die gewünschte lokale Zeitzone umwandeln.
 */
const DISPLAY_TIMEZONE = 'Europe/Berlin';

/** Wandelt einen als UTC gespeicherten DATETIME-String in die Anzeige-Zeitzone um. */
function format_local(?string $utcDatetime, string $format): string
{
    if (empty($utcDatetime)) {
        return '';
    }
    try {
        $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(DISPLAY_TIMEZONE));
        // Deutsche Muster (d.m.Y ...) auf Englisch als Y-m-d bzw. m/d, siehe I18n.php.
        return $dt->format(function_exists('lang_date_format') ? lang_date_format($format) : $format);
    } catch (Exception $e) {
        return '';
    }
}

/** Aktueller Zeitpunkt als UTC-DATETIME-String, für konsistente Speicherung. */
function utc_now(): string
{
    return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/**
 * Bitrate eines WLAN-Links als Text, z.B. "866 Mbit/s · VHT-MCS 9 · 2 SS · 80 MHz".
 * $rate ist der geparste tx_rate/rx_rate-Block aus dem Client-Payload.
 */
function format_link_rate(?array $rate): string
{
    if ($rate === null || !isset($rate['mbps'])) {
        return '–';
    }
    $parts = [number_format((float) $rate['mbps'], 0, ',', '') . ' Mbit/s'];
    if (isset($rate['mcs'])) {
        $standard = (string) ($rate['standard'] ?? '');
        $parts[] = ($standard !== '' ? $standard . '-' : '') . 'MCS ' . (int) $rate['mcs'];
    } elseif (($rate['standard'] ?? '') === 'legacy') {
        $parts[] = 'legacy';
    }
    if (isset($rate['nss'])) {
        $parts[] = (int) $rate['nss'] . ' SS';
    }
    if (isset($rate['width_mhz'])) {
        $parts[] = (int) $rate['width_mhz'] . ' MHz';
    }
    return implode(' · ', $parts);
}

/**
 * HTTP-Latenz des Captive-Portal-Checks als HTML-Zeile, z.B.
 * "<br>Antwort 12 ms · TCP 8 ms" - misst die WLAN-Strecke auch dort, wo ein
 * Portal ICMP sperrt (Probe ab 1.0.1.8). Leer, wenn die Werte fehlen.
 */
function format_portal_latency(array $cp): string
{
    $parts = [];
    if (isset($cp['response_ms'])) {
        $parts[] = __('Antwort') . ' ' . number_format((float) $cp['response_ms'], 0, ',', '.') . ' ms';
    }
    if (isset($cp['tcp_connect_ms'])) {
        $parts[] = 'TCP ' . number_format((float) $cp['tcp_connect_ms'], 0, ',', '.') . ' ms';
    }
    if ($parts === []) {
        return '';
    }
    $title = __('HTTP-Anfrage an die Detection-URL über das WLAN: TCP-Handshake bzw. Anfrage bis Antwort (ohne DNS)');
    return '<br><span class="muted" title="' . e($title) . '">' . e(implode(' · ', $parts)) . '</span>';
}

/*
 * AP-Fähigkeiten aus einem Scan-Eintrag (Probe ab 1.0.1.9, siehe
 * _parse_capabilities() in wifi_ops.py). Ältere Probes senden die Felder
 * nicht - erkennbar am fehlenden Schlüssel "security" -, dann "–".
 */

/**
 * Wi-Fi-Generation eines Scan-Eintrags. VHT auf 2,4 GHz ist kein Wi-Fi 5
 * (802.11ac gibt es nur auf 5 GHz), sondern die herstellerspezifische
 * 256-QAM-Erweiterung - Probe 1.0.1.9 hat das noch als 5 gemeldet, ab
 * 1.0.1.10 korrigiert sie es selbst; hier auch für gespeicherte Scans.
 */
function scan_wifi_generation(array $n): ?int
{
    $gen = isset($n['wifi_generation']) ? (int) $n['wifi_generation'] : null;
    if ($gen === 5 && isset($n['frequency_mhz']) && (int) $n['frequency_mhz'] < 3000) {
        return 4;
    }
    return $gen;
}

/** Sortierwert der Standard-Spalte: Generation*1000 + Kanalbreite, leer bei älteren Probes. */
function scan_caps_sort_gen(array $n): string
{
    if (!array_key_exists('security', $n)) {
        return '';
    }
    return (string) (((int) scan_wifi_generation($n)) * 1000 + (int) ($n['channel_width_mhz'] ?? 0));
}

/** z.B. "Wi-Fi 6 · 80 MHz"; "a/b/g" ohne HT. */
function format_scan_standard(array $n): string
{
    if (!array_key_exists('security', $n)) {
        return '–';
    }
    $gen = scan_wifi_generation($n);
    $names = [4 => '802.11n', 5 => '802.11ac', 6 => '802.11ax', 7 => '802.11be'];
    $label = $gen !== null ? 'Wi-Fi ' . $gen : 'a/b/g';
    $title = $gen !== null ? ($names[$gen] ?? '') : __('nur Legacy-Raten (kein HT)');
    if ($gen === 4 && ($n['wifi_generation'] ?? null) === 5) {
        $title .= ' (' . __('AP kündigt auf 2,4 GHz zusätzlich VHT an: herstellerspezifisches 256-QAM') . ')';
    }
    $out = '<span title="' . e($title) . '">' . e($label) . '</span>';
    if (!empty($n['channel_width_mhz'])) {
        $out .= ' <span class="muted">· ' . e((int) $n['channel_width_mhz']) . ' MHz</span>';
    }
    return $out;
}

/** z.B. "WPA2/WPA3-Personal · PMF opt."; WEP/WPA (unsicher) rot. */
function format_scan_security(array $n): string
{
    if (!array_key_exists('security', $n)) {
        return '–';
    }
    $sec = (string) ($n['security'] ?? '');
    $insecure = in_array($sec, ['WEP', 'WPA'], true);
    $akm = is_array($n['akm'] ?? null) ? implode(' ', $n['akm']) : '';
    $out = '<span class="' . ($insecure ? 'fail' : '') . '" title="' . e($akm !== '' ? 'AKM: ' . $akm : '') . '">'
        . e($sec !== '' ? $sec : '–') . '</span>';
    $pmf = $n['pmf'] ?? null;
    if ($pmf === 'required') {
        $out .= ' <span class="muted" title="' . e(__('Protected Management Frames erforderlich')) . '">· PMF</span>';
    } elseif ($pmf === 'optional') {
        $out .= ' <span class="muted" title="' . e(__('Protected Management Frames optional')) . '">· PMF opt.</span>';
    }
    return $out;
}

/** Aktive Roaming-Features als kleine Marken ("11k NR 11v 11r"), "–" wenn keins. */
function format_scan_roaming(array $n): string
{
    if (!array_key_exists('security', $n)) {
        return '–';
    }
    $tags = [];
    if (!empty($n['rrm_11k'])) {
        $tags[] = ['11k', '802.11k Radio Measurement'];
    }
    if (!empty($n['neighbor_report'])) {
        $tags[] = ['NR', '802.11k Neighbor Report'];
    }
    if (!empty($n['btm_11v'])) {
        $tags[] = ['11v', '802.11v BSS Transition Management'];
    }
    if (!empty($n['ft_11r'])) {
        $tags[] = ['11r', '802.11r Fast Transition'];
    }
    if ($tags === []) {
        return '<span class="muted">–</span>';
    }
    return implode(' ', array_map(
        fn(array $t): string => '<span class="cap-tag" title="' . e($t[1]) . '">' . e($t[0]) . '</span>',
        $tags
    ));
}

/**
 * Kanalbelegung des verbundenen Kanals laut Probe (survey dump, Probe ab
 * 1.0.1.16) als HTML-Zeile, z.B. "<br>Kanal belegt: 4 % Ruhe · 60 % iperf3".
 * Tooltip mit eigenem Senden/Empfangen und fremdem Anteil. Leer ohne Daten.
 */
function format_channel_load(?array $cl): string
{
    if ($cl === null) {
        return '';
    }
    $parts = [];
    $tips = [];
    foreach (['baseline' => __('Ruhe'), 'iperf3' => 'iperf3'] as $key => $label) {
        $w = is_array($cl[$key] ?? null) ? $cl[$key] : null;
        if ($w === null || !isset($w['busy_pct'])) {
            continue;
        }
        $parts[] = number_format((float) $w['busy_pct'], 0) . ' % ' . $label;
        $tips[] = $label . ' (' . ($w['seconds'] ?? '?') . ' s): ' . __(
            'belegt %s %%, davon eigenes Senden %s %%, Empfang %s %%, sonstiges (Nachbarn/Störungen) %s %%',
            $w['busy_pct'] ?? '–',
            $w['tx_pct'] ?? '–',
            $w['rx_pct'] ?? '–',
            $w['other_pct'] ?? '–'
        );
    }
    if ($parts === []) {
        return '';
    }
    return '<br><span class="muted" title="' . e(implode("\n", $tips)) . '">' . e(__('Kanal belegt:')) . ' ' . e(implode(' · ', $parts)) . '</span>';
}

/** Interface-Zähler-Differenz als Text, z.B. "Rx 1.234 Pkt / 0 Err / 0 Drop · Tx ...". */
function format_counters(?array $c): string
{
    if ($c === null) {
        return '–';
    }
    $n = fn($k) => number_format((int) ($c[$k] ?? 0), 0, ',', '.');
    return 'Rx ' . $n('rx_packets') . ' Pkt / ' . $n('rx_errors') . ' Err / ' . $n('rx_dropped') . ' Drop'
        . ' · Tx ' . $n('tx_packets') . ' Pkt / ' . $n('tx_errors') . ' Err / ' . $n('tx_dropped') . ' Drop';
}

/**
 * Ein Gerät gilt als "online", wenn es sich innerhalb der letzten
 * $thresholdSeconds gemeldet hat. Der Schwellwert ist bewusst grosszügig
 * (30 Minuten) - Scan-/Test-Intervalle sind pro Gerät konfigurierbar,
 * ohne genaues Wissen über das jeweilige Intervall lässt sich "zu lange
 * her" nur grob schätzen. Rein fürs Dashboard, keine belastbare
 * Monitoring-Aussage (dafür siehe Zabbix-Integration im Node-Repo).
 */
function device_is_online(?string $lastSeenAtUtc, int $thresholdSeconds = 1800): bool
{
    if (empty($lastSeenAtUtc)) {
        return false;
    }
    try {
        $lastSeen = new DateTime($lastSeenAtUtc, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return false;
    }
    $now = new DateTime('now', new DateTimeZone('UTC'));
    return ($now->getTimestamp() - $lastSeen->getTimestamp()) <= $thresholdSeconds;
}

/**
 * Version des Dashboards selbst (Datei VERSION im Projekt-Root, manuell
 * hochgezählt - kein automatischer Bump, siehe README, Abschnitt
 * "Version"). Für die Probe-Version je Gerät siehe devices.probe_version
 * (vom Client im Messwert-Batch mitgeschickt).
 */
function dashboard_version(): string
{
    static $version = null;
    if ($version === null) {
        $path = __DIR__ . '/../VERSION';
        $version = file_exists($path) ? trim((string) file_get_contents($path)) : (function_exists('__') ? __('unbekannt') : 'unbekannt');
    }
    return $version;
}
