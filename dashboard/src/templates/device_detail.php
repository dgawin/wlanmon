<?php
/** @var array $device */
/** @var array $scans */
/** @var array $tests */
/** @var array<string, array{pcap_size: int, has_events: bool}> $captureInfo */
/** @var array $testsBySsid */
/** @var array $latestPerSsid */
/** @var array $lanTests */
/** @var array $lanSeries */
/** @var array $localIps */
/** @var bool $online */
/** @var int $ssidOkCount */
/** @var int $ssidTotalCount */
/** @var int|null $lastScanCount */
/** @var int $scanLimit */
/** @var array $allowedScanLimits */

// Feste Farben je Phase im Zeitdiagramm. Ohne sie vergibt Chart.js die
// Farben nach der Reihenfolge der Linien, und DHCP wechselte je nach SSID
// (mit/ohne 802.1X) die Farbe.
$timingColors = ['assoc' => '#22c55e', 'scan' => '#8b5cf6', 'auth' => '#f59e0b', 'dhcp' => '#3b82f6'];

$deviceUrl = '/devices/' . rawurlencode($device['id']);
// Viewer ist rein lesend (siehe require_write_access() in Session.php) -
// Konfigurations-/Löschaktionen hier gar nicht erst anzeigen, statt sie
// klickbar zu lassen und dann mit 403 zu scheitern.
$canWrite = (current_user()['role'] ?? null) !== 'viewer';

/** @return array{0: string, 1: string} [Band-Kurzform fuers Filter-Attribut, Anzeige-Label]. */
$bandFromFrequency = function (?float $freq): array {
    if ($freq === null) {
        return ['', '–'];
    }
    if ($freq < 3000) {
        $band = '2.4';
    } elseif ($freq < 5925) {
        $band = '5';
    } else {
        $band = '6';
    }
    return [$band, ['2.4' => __('2,4 GHz'), '5' => '5 GHz', '6' => '6 GHz'][$band]];
};

/**
 * Kanalnummer aus der Frequenz - dieselbe Umrechnung wie _freq_to_channel()
 * im Probe-Client (wifi_ops.py). Nur fuer Faelle noetig, in denen (anders
 * als bei Scan-Netzen) kein expliziter "channel"-Wert vorliegt, z.B. der
 * Link-Info eines Connection-Tests (die kennt nur frequency_mhz).
 */
$channelFromFrequency = function (?float $freq): ?int {
    if ($freq === null) {
        return null;
    }
    $freq = (int) $freq;
    if ($freq >= 2412 && $freq <= 2472) {
        return intdiv($freq - 2412, 5) + 1;
    }
    if ($freq === 2484) {
        return 14;
    }
    if ($freq >= 5000 && $freq <= 5900) {
        return intdiv($freq - 5000, 5);
    }
    if ($freq >= 5955 && $freq <= 7115) {
        return intdiv($freq - 5950, 5);
    }
    return null;
};

// Connection-Tests je SSID gruppieren (bereits nach Zeit absteigend sortiert),
// für die Tabelle im jeweiligen SSID-Tab. Gleiche Schlüsselregel wie in
// render_device_detail() für $testsBySsid.
$testsForSsid = [];
foreach ($tests as $t) {
    $td = json_decode((string) $t['data'], true) ?: [];
    $testsForSsid[$td['ssid'] ?? '(unbekannt)'][] = $t;
}

/** Eine Tabellenzeile eines Connection-Tests (SSID-Tab). */
$cirrusAuthEnabled = cirrus_config() !== null;
$renderTestRow = function (array $t) use ($deviceUrl, $canWrite, $captureInfo, $cirrusAuthEnabled): void {
    $d = json_decode((string) $t['data'], true) ?: [];
    $lkRow = is_array($d['link'] ?? null) ? $d['link'] : null;
    $connectedRow = !empty($d['connected']);
    // Flache, bereits fertig formatierte Werte fuer CSV-Export/Kopieren
    // (siehe Auswahl-Aktionsleiste unten) - bewusst eigene, einfache
    // Feldnamen statt die verschachtelte HTML-Darstellung der Zelle
    // beim Export wieder auseinanderzupfluecken.
    $csvRow = [
        __('Zeitpunkt') => format_local($t['client_timestamp'] ?? $t['received_at'], 'd.m.Y H:i:s'),
        'IP' => $d['ip_address'] ?? '',
        __('Status') => $connectedRow ? __('verbunden') : __('fehlgeschlagen'),
        'Assoc (s)' => $d['assoc_seconds'] ?? '',
        'Scan (s)' => $d['scan_seconds'] ?? '',
        'DHCP (s)' => $d['dhcp_seconds'] ?? '',
        __('Ping-Ziel') => $d['ping_target'] ?? '',
        'Ping RTT (ms)' => $d['ping_rtt_avg_ms'] ?? '',
        __('Ping verloren') => isset($d['ping_sent']) ? (($d['ping_sent'] - ($d['ping_received'] ?? 0)) . '/' . $d['ping_sent']) : '',
        'iperf3 Up (Mbit/s)' => $d['iperf3_mbps'] ?? '',
        'iperf3 Down (Mbit/s)' => $d['iperf3_download_mbps'] ?? '',
        'Signal (dBm)' => $lkRow['signal_dbm'] ?? '',
        'AP-BSSID' => $lkRow['bssid'] ?? '',
        __('Fehler') => probe_error_text($d),
    ];
    ?>
    <tr data-status="<?= $connectedRow ? 'ok' : 'fail' ?>">
        <?php if ($canWrite): ?>
        <td>
            <input type="checkbox" class="row-select" value="<?= (int) $t['id'] ?>"
                   data-row="<?= e(json_encode($csvRow, JSON_UNESCAPED_UNICODE)) ?>">
        </td>
        <?php endif; ?>
        <td><?= e(format_local($t['client_timestamp'] ?? $t['received_at'], 'd.m. H:i:s')) ?></td>
        <td>
            <?php if (!empty($d['eap_method'])): ?><span class="muted">802.1X <?= e(strtoupper((string) $d['eap_method'])) ?></span><br><?php endif; ?>
            <?php if (!empty($d['ip_address'])): ?><span class="muted"><?= e($d['ip_address']) ?></span><?php else: ?>–<?php endif; ?>
            <?php if (!empty($d['mac_address'])): ?>
                <br><span class="muted" title="<?= !empty($d['mac_random']) ? te('zufällige MAC für diesen Test') : te('Hardware-MAC') ?>">
                    <?= e($d['mac_address']) ?><?= !empty($d['mac_random']) ? ' (' . te('zufällig') . ')' : '' ?>
                </span>
            <?php endif; ?>
        </td>
        <td>
            <span class="pill <?= !empty($d['connected']) ? 'pill-ok' : 'pill-fail' ?>">
                <i class="fa-solid <?= !empty($d['connected']) ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                <?= !empty($d['connected']) ? te('verbunden') : te('fehlgeschlagen') ?>
            </span>
        </td>
        <td>
            <?= isset($d['assoc_seconds']) ? e(number_format((float) $d['assoc_seconds'], 1)) . 's' : '–' ?>
            <?php if (isset($d['scan_seconds'])): ?>
                <br><span class="muted" title="<?= te('Davon Scan: bis der AP gefunden war und die Anmeldung begann') ?>"><?= te('Scan') ?> <?= e(number_format((float) $d['scan_seconds'], 1)) ?>s</span>
            <?php endif; ?>
            <?php if (isset($d['auth_seconds'])): ?>
                <br><span class="muted">802.1X <?= e(number_format((float) $d['auth_seconds'], 1)) ?>s</span>
            <?php endif; ?>
        </td>
        <td><?= isset($d['dhcp_seconds']) ? e(number_format((float) $d['dhcp_seconds'], 1)) . 's' : '–' ?></td>
        <td>
            <?php if (isset($d['ping_rtt_avg_ms']) && $d['ping_rtt_avg_ms'] !== null): ?>
                <?= e($d['ping_rtt_avg_ms']) ?> ms (<?= e($d['ping_received'] ?? 0) ?>/<?= e($d['ping_sent'] ?? 0) ?>)
            <?php elseif (!empty($d['ping_sent']) && empty($d['ping_received']) && ($d['ping_target_source'] ?? '') === 'portal_gateway'): ?>
                <?php // Viele Portale (z.B. Meraki) verwerfen vor dem Login auch ICMP zum Gateway - kein Netzfehler. ?>
                <span class="muted" title="<?= te('Hinter dem Captive Portal ohne Login - viele Portale verwerfen dann auch ICMP zum Gateway. Latenz siehe Spalte Portal.') ?>"><?= te('keine Antwort (Portal blockt ICMP)') ?></span>
            <?php elseif (!empty($d['ping_sent']) && empty($d['ping_received'])): ?>
                <span class="fail"><?= te('alle verloren') ?></span> <span class="muted">(0/<?= e($d['ping_sent']) ?>)</span>
            <?php elseif (($d['skipped'] ?? '') === 'captive_portal'): ?>
                <span class="muted"><?= te('übersprungen (Portal)') ?></span>
            <?php else: ?>–<?php endif; ?>
            <?php if (!empty($d['ping_target'])): ?>
                <?php $pingSourceLabel = ['gateway' => __('Gateway') . ' ', 'portal_gateway' => __('Gateway (hinter Portal)') . ' '][$d['ping_target_source'] ?? ''] ?? ''; ?>
                <br><span class="muted"><?= e($pingSourceLabel) ?><?= e($d['ping_target']) ?></span>
            <?php endif; ?>
        </td>
        <td>
            <?php $cp =is_array($d['captive_portal'] ?? null) ? $d['captive_portal'] : null; ?>
            <?php if ($cp === null): ?>–
            <?php elseif (($cp['detected'] ?? null) === true): ?>
                <?php $lg = is_array($cp['login'] ?? null) ? $cp['login'] : null; ?>
                <?php
                // Ohne eingerichteten Login ist ein Portal erwartbar (Gastnetz) - neutral;
                // gruen nach erfolgreichem, rot nach fehlgeschlagenem Login.
                [$portalPill, $portalIcon] = $lg === null
                    ? ['pill-muted', 'fa-circle-info']
                    : (!empty($lg['ok']) ? ['pill-ok', 'fa-circle-check'] : ['pill-fail', 'fa-triangle-exclamation']);
                ?>
                <span class="pill <?= $portalPill ?>"<?= $lg === null ? ' title="' . te('Captive Portal erkannt, kein automatischer Login eingerichtet') . '"' : '' ?>>
                    <i class="fa-solid <?= $portalIcon ?>"></i> <?= te('Portal erkannt') ?>
                </span>
                <span class="muted">(HTTP <?= e($cp['http_status'] ?? '?') ?>)</span>
                <?= format_portal_latency($cp) ?>
                <?php if (!empty($cp['redirect_url'])):
                    // Nur den Host anzeigen, die volle URL (mit MAC) steht im Tooltip.
                    $portalHost = parse_url((string) $cp['redirect_url'], PHP_URL_HOST) ?: (string) $cp['redirect_url']; ?>
                    <br><span class="muted" title="<?= e($cp['redirect_url']) ?>"><?= e($portalHost) ?></span>
                <?php endif; ?>
                <?php if ($lg !== null): ?>
                    <br>
                    <?php if (!empty($lg['ok'])): ?>
                        <span class="ok"><i class="fa-solid fa-check"></i> <?= te('Login ok') ?></span>
                        <?php if (isset($lg['seconds'])): ?><span class="muted">(<?= e($lg['seconds']) ?>s)</span><?php endif; ?>
                    <?php else: ?>
                        <span class="fail"><i class="fa-solid fa-xmark"></i> <?= te('Login fehlgeschlagen') ?></span>
                        <?php if (!empty($lg['error'])): ?><br><span class="muted"><?= e($lg['error']) ?></span><?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($lg['portal_type'])): ?>
                        <br><span class="muted"><?= te('Modul') ?> <?= e($lg['portal_type']) ?><?= !empty($lg['login_by']) ? ' / ' . e($lg['login_by']) : '' ?></span>
                    <?php endif; ?>
                    <?php $lo = is_array($cp['logoff'] ?? null) ? $cp['logoff'] : null; ?>
                    <?php if ($lo !== null && !empty($lo['attempted'])): ?>
                        <br>
                        <?php if (!empty($lo['ok'])): ?>
                            <span class="muted"><?= te('abgemeldet') ?></span>
                        <?php else: ?>
                            <span class="fail" title="<?= e($lo['error'] ?? '') ?>"><?= te('Abmeldung fehlgeschlagen') ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php elseif (($cp['detected'] ?? null) === false): ?>
                <span class="pill pill-ok"><i class="fa-solid fa-circle-check"></i> <?= te('kein Portal') ?></span>
                <?php if (isset($cp['response_ms']) || isset($cp['tcp_connect_ms'])): ?>
                    <?= format_portal_latency($cp) ?>
                <?php elseif (isset($cp['seconds'])): ?><span class="muted">(<?= e($cp['seconds']) ?>s)</span><?php endif; ?>
            <?php else: ?>
                <span class="pill pill-muted" title="<?= e($cp['error'] ?? '') ?>">
                    <i class="fa-solid fa-circle-question"></i> <?= te('nicht prüfbar') ?>
                </span>
                <?php if (!empty($cp['error'])): ?><br><span class="muted"><?= e($cp['error']) ?></span><?php endif; ?>
            <?php endif; ?>
        </td>
        <td>
            <?php if (isset($d['iperf3_mbps']) || isset($d['iperf3_download_mbps'])): ?>
                ↑ <?= isset($d['iperf3_mbps']) ? e($d['iperf3_mbps']) : '–' ?>
                <?php if (isset($d['iperf3_retransmits'])): ?><span class="muted">(<?= e($d['iperf3_retransmits']) ?> Retr)</span><?php endif; ?>
                <br>↓ <?= isset($d['iperf3_download_mbps']) ? e($d['iperf3_download_mbps']) : '–' ?>
                <?php if (isset($d['iperf3_download_retransmits'])): ?><span class="muted">(<?= e($d['iperf3_download_retransmits']) ?> Retr)</span><?php endif; ?>
                <span class="muted">Mbit/s</span>
                <?php if (!empty($d['iperf3_bitrate_mbps'])): ?>
                    <br><span class="muted"><?= te('begrenzt auf %s Mbit/s', $d['iperf3_bitrate_mbps']) ?></span>
                <?php endif; ?>
            <?php elseif (($d['skipped'] ?? '') === 'captive_portal'): ?>
                <span class="muted"><?= te('übersprungen (Portal)') ?></span>
            <?php elseif (!empty($d['iperf3_deferred'])): ?>
                <span class="muted" title="<?= te('Mindestabstand zwischen zwei iperf3-Messungen dieser SSID (Gerätekonfiguration)') ?>"><?= te('ausgelassen (Intervall)') ?></span>
            <?php else: ?>–<?php endif; ?>
        </td>
        <td class="link-cell">
            <?php $lk = is_array($d['link'] ?? null) ? $d['link'] : null; ?>
            <?php if ($lk !== null): ?>
                <?= isset($lk['signal_dbm']) ? e($lk['signal_dbm']) . ' dBm' : '' ?>
                <?php if (isset($lk['frequency_mhz'])): ?><span class="muted">(<?= e($lk['frequency_mhz']) ?> MHz)</span><?php endif; ?>
                <?php if (!empty($lk['bssid'])):
                    $lkApInfo = cirrus_lookup_ap((string) $lk['bssid']);
                ?>
                    <br><span class="muted">AP <?= e($lk['bssid']) ?><?php if (!empty($lkApInfo['ap_name'])): ?> (<?= e($lkApInfo['ap_name']) ?>)<?php endif; ?></span>
                <?php endif; ?>
                <br><span class="muted">Tx</span> <?= e(format_link_rate(is_array($lk['tx_rate'] ?? null) ? $lk['tx_rate'] : null)) ?>
                <br><span class="muted">Rx</span> <?= e(format_link_rate(is_array($lk['rx_rate'] ?? null) ? $lk['rx_rate'] : null)) ?>
                <?php if (isset($lk['tx_retries'])): ?>
                    <br><span class="muted">Retries <?= e($lk['tx_retries']) ?> · Failed <?= e($lk['tx_failed'] ?? 0) ?></span>
                <?php endif; ?>
            <?php else: ?>–<?php endif; ?>
            <?php if (is_array($d['counters'] ?? null)): ?>
                <br><span class="muted"><?= e(format_counters($d['counters'])) ?></span>
            <?php endif; ?>
            <?= format_channel_load(is_array($d['channel_load'] ?? null) ? $d['channel_load'] : null) ?>
        </td>
        <td class="muted">
            <?= e(probe_error_text($d)) ?>
            <?php $cap = $canWrite && is_string($d['capture_id'] ?? null) ? ($captureInfo[$d['capture_id']] ?? null) : null; ?>
            <?php if ($cap !== null): ?>
                <span class="capture-links">
                    <?php if ($cap['pcap_size'] > 0): ?>
                        <a href="<?= e($deviceUrl) ?>/captures/<?= e($d['capture_id']) ?>.pcap" title="<?= te('Mitschnitt des fehlgeschlagenen Verbindungsaufbaus (EAPOL, DHCP, ARP, DNS, ICMP) für Wireshark') ?>"><i class="fa-solid fa-file-arrow-down"></i> pcap (<?= e(format_bytes($cap['pcap_size'])) ?>)</a>
                    <?php endif; ?>
                    <?php if ($cap['has_events']): ?>
                        <a href="<?= e($deviceUrl) ?>/captures/<?= e($d['capture_id']) ?>.txt" title="<?= te('Kernel-Ereignisse des WLAN-Adapters: Authentifizierung, Assoziation, Deauth mit Status-/Reason-Code') ?>"><i class="fa-solid fa-file-lines"></i> <?= te('Ereignisse') ?></a>
                    <?php endif; ?>
                    <?php if (!empty($cap['has_wpa_log'])): ?>
                        <a href="<?= e($deviceUrl) ?>/captures/<?= e($d['capture_id']) ?>.log" title="<?= te('Log von wpa_supplicant: EAP-Methode, Server-Zertifikat, Abbruchgrund aus Sicht des Clients') ?>"><i class="fa-solid fa-file-code"></i> wpa_supplicant</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <?php
            // 802.1X fehlgeschlagen: Anmeldeversuche aus Cirrus nachladen (src/CirrusAuth.php).
            $isEap = !empty($d['eap_method']) || stripos((string) ($d['security'] ?? ''), 'eap') !== false;
            if ($cirrusAuthEnabled && $canWrite && !$connectedRow && $isEap): ?>
                <span class="capture-links">
                    <a href="<?= e($deviceUrl) ?>/measurements/<?= (int) $t['id'] ?>/cirrus-auth" class="cirrus-auth-link"
                       title="<?= te('Anmeldeversuche dieser MAC rund um den Test aus der Authentifizierungs-Historie von OmniVista Cirrus') ?>"><i class="fa-solid fa-cloud"></i> <?= te('Cirrus-Anmeldung') ?></a>
                </span>
            <?php endif; ?>
        </td>
        <td>
            <a href="<?= e($deviceUrl) ?>/measurements/<?= (int) $t['id'] ?>/raw" target="_blank"
               class="btn-secondary btn-small raw-link" title="<?= te('Rohdaten (JSON) in neuem Tab öffnen') ?>">
                <i class="fa-solid fa-code"></i>
            </a>
            <?php if ($canWrite): ?>
            <form method="post" action="<?= e($deviceUrl) ?>/measurements/<?= (int) $t['id'] ?>/delete"
                  onsubmit="return confirm(<?= tjs('Diese Messung löschen?') ?>);" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn-remove btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Löschen') ?></button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php
};

// Anzeigename einer SSID-Gruppe: "(unbekannt)" (Tests ohne SSID, siehe
// render_device_detail()) bleibt intern der Schlüssel - das Löschformular
// schickt ihn zurück -, angezeigt wird er übersetzt.
$ssidLabel = fn(string $ssid): string => $ssid === '(unbekannt)' ? __('(unbekannt)') : $ssid;

// Tabs in Anzeigereihenfolge: Verlauf, je SSID einer, dann LAN (falls Daten), dann Scans.
$tabs = [['id' => 'verlauf', 'label' => __('Verlauf'), 'dot' => null]];
foreach ($testsBySsid as $ssid => $series) {
    $tabs[] = [
        'id' => 'ssid-' . substr(md5((string) $ssid), 0, 8),
        'label' => $ssidLabel((string) $ssid),
        'dot' => array_key_exists($ssid, $latestPerSsid) ? ($latestPerSsid[$ssid] ? 'online' : 'offline') : null,
    ];
}
if (!empty($lanTests)) {
    $tabs[] = ['id' => 'lan', 'label' => __('LAN-Durchsatz'), 'dot' => null];
}
if ($health !== null || !empty($healthSeries['labels'])) {
    $tabs[] = ['id' => 'system', 'label' => __('System'), 'dot' => null];
}
$tabs[] = ['id' => 'scans', 'label' => __('Scans'), 'dot' => null];
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($device['id']) ?> – WLANMON</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/static/style.css">
    <link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
    <script src="/static/theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
    // Achsen-/Gitterfarben passend zum Hell/Dunkel-Schema, siehe static/theme.js.
    wlanmonApplyChartTheme();
    </script>
    <script>
    // Texte für static/spectrum.js und static/timeline.js in der aktuellen Sprache.
    window.wlanmonLang = <?= json_encode(effective_lang()) ?>;
    window.wlanmonI18n = <?= i18n_js([
        '%s Netze',
        '%s iperf3-Test(s) im Zeitraum',
        '(verborgen)',
        'Auslastung über 50 %: %s Messpunkt(e), davon %s während oder bis 3 min nach eigenen iperf3-Tests',
        'K',
        'Kanal',
        'Lade …',
        'Probe verbunden',
        'Spektrum',
        'Verlauf nicht ladbar:',
        'Zeitraum:',
        '„Von“ muss vor „Bis“ liegen.',
        'Zeitraum geschätzt',
        'alle Spitzen stammen von der eigenen Messung',
        'andere Netze',
        'begrenzt auf %s',
        'endete %s s vorher',
        'jeder %s. Scan ausgewertet (Ausdünnung bei vielen Scans)',
        'keine Auslastung über 50 %',
    ]) ?>;
    </script>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<main>
<p><a href="/" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Alle Geräte') ?></a></p>
<h1>
    <?= e($device['id']) ?>
    <?php if ($canWrite): ?>
    <a href="<?= e($deviceUrl) ?>/config" class="btn"><i class="fa-solid fa-gear"></i> <?= te('Konfiguration') ?></a>
    <?php endif; ?>
</h1>
<p class="muted">
    <?= te('Standort:') ?> <?= e($device['site_name'] ?? '–') ?> · <?= te('Zuletzt gesehen:') ?>
    <?= !empty($device['last_seen_at']) ? e(format_local($device['last_seen_at'], 'd.m.Y H:i')) : te('noch nie') ?>
    · <?= te('Probe-Version:') ?> <?= e($device['probe_version'] ?? '–') ?>
    <?php $autoUpdate = json_decode((string) ($device['auto_update'] ?? ''), true); ?>
    · <?= te('Auto-Update:') ?>
    <?php if (!is_array($autoUpdate)): ?>
        <span title="<?= te('Wird ab Probe-Version 1.0.1.2 gemeldet') ?>"><?= te('unbekannt') ?></span>
    <?php elseif (!empty($autoUpdate['enabled'])): ?>
        <span class="ok"><?= te('an') ?></span>
        (Branch <strong><?= e($autoUpdate['branch'] ?? '–') ?></strong>
        <?= te('aus Repo') ?> <span title="<?= e(($autoUpdate['repo_url'] ?? '') . (!empty($autoUpdate['repo_dir']) ? ' · Checkout: ' . $autoUpdate['repo_dir'] : '')) ?>"><?= e(format_repo_url($autoUpdate['repo_url'] ?? null)) ?></span><?php if (!empty($autoUpdate['commit'])): ?>, Commit <code><?= e($autoUpdate['commit']) ?></code><?php endif; ?>)
        <?php
        $auResult = $autoUpdate['last_result'] ?? null;
        $auLabels = ['current' => __('aktuell'), 'updated' => __('aktualisiert'), 'disabled' => __('nicht aktiv'), 'error' => __('fehlgeschlagen')];
        ?>
        <?php if ($auResult !== null && !empty($autoUpdate['last_run_at'])): ?>
            <?php
            // Einzelner Fehlschlag grau (nächster Lauf wiederholt), rot erst ab zwei in Folge.
            $auLevel = auto_update_failure_level($autoUpdate);
            $auClass = $auLevel === 'error' ? 'fail' : ($auLevel === 'hint' ? 'muted' : '');
            ?>
            · <?= te('letzter Lauf') ?> <?= e(format_local($autoUpdate['last_run_at'], 'd.m. H:i')) ?>:
            <span class="<?= $auClass ?>" title="<?= e($autoUpdate['last_message'] ?? '') ?>"><?= e($auLabels[$auResult] ?? $auResult) ?><?= isset($autoUpdate['fail_count']) && $autoUpdate['fail_count'] > 1 ? ' (' . te('%d× in Folge', (int) $autoUpdate['fail_count']) . ')' : '' ?></span>
            <?php if ($auLevel !== null && !empty($autoUpdate['last_message'])): ?>
                <br><span class="<?= $auClass ?>"><?= e($autoUpdate['last_message']) ?><?= $auLevel === 'hint' ? ' – ' . te('einmalig, der nächste Lauf wiederholt es') : '' ?></span>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <?= te('aus') ?>
    <?php endif; ?>
</p>
<?php if (!empty($device['notes'])): ?>
    <p class="muted"><i class="fa-solid fa-note-sticky"></i> <?= nl2br(e($device['notes'])) ?></p>
<?php endif; ?>

<div class="kpi-row">
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-signal"></i> <?= te('Status') ?></span>
        <span class="kpi-value">
            <span class="pill <?= $online ? 'pill-ok' : 'pill-fail' ?>">
                <span class="status-dot <?= $online ? 'status-online' : 'status-offline' ?>" style="margin-right:0"></span>
                <?= $online ? te('Online') : te('Offline') ?>
            </span>
        </span>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-wifi"></i> <?= te('Ziel-SSIDs verbunden') ?></span>
        <span class="kpi-value <?= $ssidTotalCount > 0 && $ssidOkCount < $ssidTotalCount ? 'fail' : '' ?>">
            <?= $ssidTotalCount > 0 ? e($ssidOkCount) . ' / ' . e($ssidTotalCount) : '–' ?>
        </span>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-magnifying-glass"></i> <?= te('Netze im letzten Scan') ?></span>
        <span class="kpi-value"><?= $lastScanCount !== null ? e($lastScanCount) : '–' ?></span>
    </div>
    <?php if (!empty($localIps)): ?>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-network-wired"></i> <?= te('Lokale IP') ?></span>
        <span class="kpi-value kpi-value-small">
            <?php foreach ($localIps as $ifName => $ip): ?>
                <span><?= e($ip) ?> <span class="muted"><?= e($ifName) ?></span></span><br>
            <?php endforeach; ?>
        </span>
    </div>
    <?php endif; ?>
    <?php if (!empty($lanTests)):
        $lastLanData = json_decode((string) $lanTests[0]['data'], true) ?: []; ?>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-gauge-high"></i> <?= te('LAN-Durchsatz (↑ / ↓)') ?></span>
        <span class="kpi-value">
            <?= isset($lastLanData['upload_mbps']) ? e(round((float) $lastLanData['upload_mbps'])) : '–' ?>
            / <?= isset($lastLanData['download_mbps']) ? e(round((float) $lastLanData['download_mbps'])) : '–' ?>
            <span class="muted">Mbit/s</span>
        </span>
    </div>
    <?php endif; ?>
</div>

<div class="tabs" role="tablist">
    <?php foreach ($tabs as $tab): ?>
        <button type="button" class="tab-btn" role="tab" data-tab="<?= e($tab['id']) ?>"
                aria-controls="tab-<?= e($tab['id']) ?>" aria-selected="false">
            <?php if ($tab['dot'] !== null): ?>
                <span class="status-dot status-<?= e($tab['dot']) ?>"></span>
            <?php else: ?>
                <i class="fa-solid <?= ['lan' => 'fa-ethernet', 'verlauf' => 'fa-chart-line', 'system' => 'fa-microchip'][$tab['id']] ?? 'fa-magnifying-glass' ?>"></i>
            <?php endif; ?>
            <?= e($tab['label']) ?>
        </button>
    <?php endforeach; ?>
</div>

<script>
// Die Diagramme eines Tabs werden erst beim ersten Öffnen erzeugt: Chart.js
// misst beim Erzeugen die Größe des Containers, und in einem ausgeblendeten
// Tab wäre die 0.
window.tabInit = {};
</script>

<?php foreach ($testsBySsid as $ssid => $series):
    $chartId = substr(md5((string) $ssid), 0, 8);
    $hasIperf3 = count(array_filter($series['iperf3'], fn($v) => $v !== null)) > 0;
    $hasAuth = count(array_filter($series['auth'], fn($v) => $v !== null)) > 0;
    $hasScan = count(array_filter($series['scan'] ?? [], fn($v) => $v !== null)) > 0;
    $hasSignal = count(array_filter($series['signal'], fn($v) => $v !== null)) > 0;
    $ssidTests = $testsForSsid[$ssid] ?? [];
    $ssidTestCount = count($ssidTests);
    $ssidTestOkCount = count(array_filter($ssidTests, function (array $t): bool {
        $d = json_decode((string) $t['data'], true) ?: [];
        return !empty($d['connected']);
    }));
    // Durchschnitt ueber alle geladenen Tests dieser SSID, in denen der
    // jeweilige Wert vorlag (null = z.B. iperf3 deaktiviert oder kein
    // 802.1X) - dieselben Zeitreihen wie fuer die Charts oben, nur
    // aggregiert statt als Verlauf.
    $avg = function (array $values): ?float {
        $filtered = array_filter($values, fn($v) => $v !== null);
        return $filtered === [] ? null : array_sum($filtered) / count($filtered);
    };
    $avgAssoc = $avg($series['assoc']);
    $avgScan = $avg($series['scan'] ?? []);
    $avgDhcp = $avg($series['dhcp']);
    $avgAuth = $avg($series['auth']);
    $avgIperf3Up = $avg($series['iperf3']);
    $avgIperf3Down = $avg($series['iperf3_down']);

    // Roaming-Kandidaten: andere BSSIDs derselben SSID aus dem NEUESTEN
    // Scan (reine Momentaufnahme - Scan und Connection-Test laufen
    // unabhaengig voneinander und koennen zeitversetzt sein), staerkstes
    // Signal zuerst.
    $latestScanNetworks = [];
    if (!empty($scans)) {
        $latestScanData = json_decode((string) $scans[0]['data'], true) ?: [];
        $latestScanNetworks = is_array($latestScanData['networks'] ?? null) ? $latestScanData['networks'] : [];
    }
    $roamingCandidates = array_values(array_filter(
        $latestScanNetworks,
        fn(array $n): bool => scan_ssid($n['ssid'] ?? null) === $ssid
    ));
    usort($roamingCandidates, fn(array $a, array $b) => ($b['signal_dbm'] ?? -999) <=> ($a['signal_dbm'] ?? -999));

    // Aktuell verbundener AP aus dem NEUESTEN Connection-Test dieser SSID
    // (Tests sind DESC sortiert, $ssidTests[0] ist also der aktuellste).
    $currentBssid = null;
    $currentSignal = null;
    if (!empty($ssidTests)) {
        $latestTestData = json_decode((string) $ssidTests[0]['data'], true) ?: [];
        $latestLink = is_array($latestTestData['link'] ?? null) ? $latestTestData['link'] : null;
        if (!empty($latestTestData['connected']) && $latestLink !== null) {
            $currentBssid = $latestLink['bssid'] ?? null;
            $currentSignal = $latestLink['signal_dbm'] ?? null;
        }
    }
    // Verbundenen AP in der Kandidatenliste markieren; war er im letzten
    // Scan nicht dabei (z.B. Scan vor dem letzten Roaming), als eigene
    // Zeile ergaenzen statt ihn zu unterschlagen.
    $foundCurrent = false;
    foreach ($roamingCandidates as &$rc) {
        $rc['is_current'] = $currentBssid !== null && strcasecmp((string) ($rc['bssid'] ?? ''), (string) $currentBssid) === 0;
        if ($rc['is_current']) {
            $foundCurrent = true;
        }
    }
    unset($rc);
    if ($currentBssid !== null && !$foundCurrent) {
        // Frequenz kommt aus der Link-Info des Connection-Tests (die liefert
        // kein "channel", nur frequency_mhz) - Kanal daraus selbst berechnen,
        // statt Band/Kanal fuer diese Zeile leer zu lassen.
        $currentFreq = $latestLink['frequency_mhz'] ?? null;
        $roamingCandidates[] = [
            'bssid' => $currentBssid,
            'signal_dbm' => $currentSignal,
            'frequency_mhz' => $currentFreq,
            'channel' => $channelFromFrequency($currentFreq),
            'is_current' => true,
            'not_in_scan' => true,
        ];
        usort($roamingCandidates, fn(array $a, array $b) => ($b['signal_dbm'] ?? -999) <=> ($a['signal_dbm'] ?? -999));
    }
    // Deutlich staerkerer Kandidat als der aktuell verbundene AP? 5 dB ist
    // ein gebraeuchlicher Schwellwert, ab dem ein "Sticky Client" (haengt
    // am schwaecheren AP statt zu roamen) als relevant gilt.
    $betterCandidateBssid = null;
    if ($currentSignal !== null) {
        foreach ($roamingCandidates as $rc) {
            if (!empty($rc['is_current'])) {
                continue;
            }
            if (($rc['signal_dbm'] ?? -999) >= $currentSignal + 5) {
                $betterCandidateBssid = $rc['bssid'] ?? null;
                break; // sortiert -> erster Treffer ist der staerkste
            }
        }
    }

    // AP-Name zur BSSID aus dem periodisch synchronisierten OmniVista-
    // Cirrus-Inventar (siehe sync_cirrus_aps.php, src/Cirrus.php) - rein
    // optional: ohne konfigurierte Cirrus-Anbindung bzw. fuer eine dort
    // unbekannte BSSID bleibt 'ap_name' einfach null.
    foreach ($roamingCandidates as &$rc) {
        $apInfo = cirrus_lookup_ap((string) ($rc['bssid'] ?? ''));
        $rc['ap_name'] = $apInfo['ap_name'] ?? null;
    }
    unset($rc);
?>
<section class="tab-panel" id="tab-ssid-<?= $chartId ?>" role="tabpanel" hidden>
    <div class="kpi-row">
        <div class="kpi-tile">
            <span class="kpi-label"><i class="fa-solid fa-list-check"></i> <?= te('Tests / erfolgreich') ?></span>
            <span class="kpi-value <?= $ssidTestCount > 0 && $ssidTestOkCount < $ssidTestCount ? 'fail' : '' ?>">
                <?= $ssidTestCount > 0 ? e($ssidTestCount) . ' / ' . e($ssidTestOkCount) : '–' ?>
            </span>
        </div>
        <div class="kpi-tile">
            <span class="kpi-label"><i class="fa-solid fa-plug-circle-check"></i> <?= te('Ø Assoziation') ?><?= $hasAuth ? ' / 802.1X' : '' ?> / DHCP</span>
            <span class="kpi-value kpi-value-small">
                <?= $avgAssoc !== null ? e(number_format($avgAssoc, 1)) . 's' : '–' ?><?php if ($avgScan !== null): ?>
                <span class="muted">(<?= te('Scan') ?> <?= e(number_format($avgScan, 1)) ?>s)</span><?php endif; ?><?php if ($hasAuth): ?>
                / <?= $avgAuth !== null ? e(number_format($avgAuth, 1)) . 's' : '–' ?>
                <?php endif; ?>
                / <?= $avgDhcp !== null ? e(number_format($avgDhcp, 1)) . 's' : '–' ?>
            </span>
        </div>
        <?php if ($hasIperf3): ?>
        <div class="kpi-tile">
            <span class="kpi-label"><i class="fa-solid fa-gauge-high"></i> <?= te('Ø iperf3-Durchsatz (↑ / ↓)') ?></span>
            <span class="kpi-value">
                <?= $avgIperf3Up !== null ? e(round($avgIperf3Up)) : '–' ?>
                / <?= $avgIperf3Down !== null ? e(round($avgIperf3Down)) : '–' ?>
                <span class="muted">Mbit/s</span>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <h2><span><i class="fa-solid fa-tower-cell"></i> <?= te('Roaming-Kandidaten (letzter Scan)') ?></span></h2>
    <?php if ($betterCandidateBssid !== null): ?>
        <p class="fail">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= te('Möglicher Sticky-Client: %s ist im letzten Scan mindestens 5 dB stärker als der aktuell verbundene AP.', $betterCandidateBssid) ?>
        </p>
    <?php endif; ?>
    <?php if (empty($roamingCandidates)): ?>
        <p class="muted"><?= te('Keine APs für diese SSID im letzten Scan gefunden.') ?></p>
    <?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr><th>BSSID</th><th><?= te('AP-Name') ?></th><th>Signal</th><th>Band</th><th><?= te('Kanal') ?></th><th><?= te('Status') ?></th></tr></thead>
        <tbody>
        <?php foreach ($roamingCandidates as $rc):
            [, $rcBandLabel] = $bandFromFrequency($rc['frequency_mhz'] ?? null);
        ?>
            <tr>
                <td><?= e($rc['bssid'] ?? '–') ?></td>
                <td><?= e($rc['ap_name'] ?? '–') ?></td>
                <td><?= isset($rc['signal_dbm']) ? e($rc['signal_dbm']) . ' dBm' : '–' ?></td>
                <td><?= e($rcBandLabel) ?></td>
                <td><?= e($rc['channel'] ?? '–') ?></td>
                <td>
                    <?php if (!empty($rc['is_current'])): ?>
                        <span class="pill pill-ok"><i class="fa-solid fa-circle-check"></i> <?= te('aktuell verbunden') ?></span>
                        <?php if (!empty($rc['not_in_scan'])): ?>
                            <br><span class="muted"><?= te('Signal/Frequenz aus dem letzten Connection-Test - dieser AP war im letzten WLAN-Scan selbst nicht sichtbar (z. B. weil der Scan vor dem Verbindungsaufbau lief).') ?></span>
                        <?php endif; ?>
                    <?php elseif ($betterCandidateBssid !== null && strcasecmp((string) ($rc['bssid'] ?? ''), (string) $betterCandidateBssid) === 0): ?>
                        <span class="pill pill-warn"><i class="fa-solid fa-triangle-exclamation"></i> <?= te('stärker verfügbar') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <div class="chart-row">
        <div class="chart-col chart-col-wide">
            <p class="muted chart-label"><?= te('Assoziation') ?><?= $hasScan ? ' (' . te('davon Scan') . ')' : '' ?> / <?= $hasAuth ? '802.1X / ' : '' ?>DHCP (s)</p>
            <canvas id="timingChart<?= $chartId ?>" height="60"></canvas>
        </div>
    </div>
    <?php if ($hasSignal): ?>
    <div class="chart-row">
        <div class="chart-col chart-col-wide">
            <p class="muted chart-label"><?= te('Signalstärke (dBm) – höher (näher an 0) ist besser') ?></p>
            <canvas id="signalChart<?= $chartId ?>" height="60"></canvas>
        </div>
    </div>
    <?php endif; ?>
    <div class="chart-row">
        <div class="chart-col chart-col-wide">
            <p class="muted chart-label">Ping RTT (ms)</p>
            <canvas id="rttChart<?= $chartId ?>" height="60"></canvas>
        </div>
    </div>
    <?php if ($hasIperf3): ?>
    <div class="chart-row">
        <div class="chart-col chart-col-wide">
            <p class="muted chart-label"><?= te('iperf3-Durchsatz Upload / Download (Mbit/s)') ?></p>
            <canvas id="iperf3Chart<?= $chartId ?>" height="60"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <h2>
        <span><i class="fa-solid fa-table-list"></i> <?= te('Letzte Tests') ?></span>
        <?php if ($canWrite): ?>
        <span class="h2-actions">
            <form method="post" action="<?= e($deviceUrl) ?>/measurements/delete-for-ssid"
                  onsubmit="return confirm(<?= tjs('Alle Connection-Test-Messungen für SSID „%s“ löschen?', $ssidLabel((string) $ssid)) ?>);" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="ssid" value="<?= e((string) $ssid) ?>">
                <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Nur „%s“ löschen', $ssidLabel((string) $ssid)) ?></button>
            </form>
            <form method="post" action="<?= e($deviceUrl) ?>/measurements/delete-all"
                  onsubmit="return confirm(<?= tjs('Alle Connection-Test-Messungen dieses Geräts (alle SSIDs) löschen?') ?>);" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="connection_test">
                <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Alle Connection-Tests löschen (alle SSIDs)') ?></button>
            </form>
        </span>
        <?php endif; ?>
    </h2>
    <?php if ($canWrite): ?>
    <div class="test-bulk-toolbar">
        <label class="test-filter-label">
            <input type="checkbox" class="test-filter-failed" data-chart-id="<?= $chartId ?>">
            <?= te('Nur fehlgeschlagene anzeigen') ?>
        </label>
        <span class="test-bulk-actions" id="testBulkActions<?= $chartId ?>" hidden>
            <span class="muted test-bulk-count" id="testBulkCount<?= $chartId ?>"><?= te('%d ausgewählt', 0) ?></span>
            <button type="button" class="btn-remove btn-small test-bulk-delete" data-chart-id="<?= $chartId ?>"><i class="fa-solid fa-trash-can"></i> <?= te('Löschen') ?></button>
            <button type="button" class="btn-secondary btn-small test-bulk-csv" data-chart-id="<?= $chartId ?>"><i class="fa-solid fa-file-csv"></i> <?= te('Als CSV exportieren') ?></button>
            <button type="button" class="btn-secondary btn-small test-bulk-copy" data-chart-id="<?= $chartId ?>"><i class="fa-solid fa-copy"></i> <?= te('Kopieren') ?></button>
        </span>
    </div>
    <!-- Eigenstaendiges, per JS befuelltes Formular fuer den Bulk-Loeschen-
         Button oben - kann die <table> unten NICHT selbst umschliessen, weil
         jede Zeile schon ihr eigenes <form> fuer den Einzel-Loeschen-Button
         hat und <form> nicht verschachtelt werden darf. -->
    <form method="post" action="<?= e($deviceUrl) ?>/measurements/delete-many"
          id="testBulkDeleteForm<?= $chartId ?>" class="test-bulk-delete-form">
        <?= csrf_field() ?>
    </form>
    <?php endif; ?>
    <div class="table-scroll">
    <table id="testTable<?= $chartId ?>">
        <thead>
            <tr>
                <?php if ($canWrite): ?>
                <th><input type="checkbox" class="test-select-all" data-chart-id="<?= $chartId ?>" title="<?= te('Alle sichtbaren auswählen') ?>"></th>
                <?php endif; ?>
                <th><?= te('Zeitpunkt') ?></th><th>IP</th><th><?= te('Status') ?></th><th>Assoc</th>
                <th>DHCP</th><th><?= te('Ping (RTT / Verlust)') ?></th><th>Portal</th><th>iperf3</th><th>Link</th><th><?= te('Fehler') ?></th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ssidTests as $t) { $renderTestRow($t); } ?>
        </tbody>
    </table>
    </div>

    <script>
    tabInit['ssid-<?= $chartId ?>'] = function () {
        <?php if ($hasSignal): ?>
        new Chart(document.getElementById('signalChart<?= $chartId ?>'), {
            type: 'line',
            data: {
                labels: <?= json_encode($series['labels'], JSON_HEX_TAG) ?>,
                datasets: [{
                    label: 'Signal (dBm)',
                    data: <?= json_encode($series['signal'], JSON_HEX_TAG) ?>,
                    borderWidth: 2,
                    spanGaps: true,
                    borderColor: '#a855f7',
                    backgroundColor: '#a855f7',
                }]
            },
            // Kein beginAtZero: dBm sind negativ. Feste Mindestspanne, damit
            // ein konstanter Wert nicht auf eine Linie ohne Achse schrumpft.
            options: { scales: { y: { suggestedMin: -90, suggestedMax: -30 } } }
        });
        <?php endif; ?>
        new Chart(document.getElementById('rttChart<?= $chartId ?>'), {
            type: 'line',
            data: {
                labels: <?= json_encode($series['labels'], JSON_HEX_TAG) ?>,
                datasets: [{
                    label: 'Ping RTT (ms)',
                    data: <?= json_encode($series['rtt'], JSON_HEX_TAG) ?>,
                    borderWidth: 2,
                    spanGaps: true,
                }]
            },
            options: { scales: { y: { beginAtZero: true } } }
        });
        new Chart(document.getElementById('timingChart<?= $chartId ?>'), {
            type: 'line',
            data: {
                labels: <?= json_encode($series['labels'], JSON_HEX_TAG) ?>,
                datasets: [
                    {
                        label: <?= tjson('Assoziation (s)') ?>,
                        data: <?= json_encode($series['assoc'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                        borderColor: '<?= $timingColors['assoc'] ?>',
                        backgroundColor: '<?= $timingColors['assoc'] ?>',
                    },
                    <?php if ($hasScan): ?>
                    {
                        // Teil der Assoziationszeit, daher gestrichelt.
                        label: <?= tjson('davon Scan (s)') ?>,
                        data: <?= json_encode($series['scan'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        borderDash: [5, 4],
                        spanGaps: true,
                        borderColor: '<?= $timingColors['scan'] ?>',
                        backgroundColor: '<?= $timingColors['scan'] ?>',
                    },
                    <?php endif; ?>
                    <?php if ($hasAuth): ?>
                    {
                        label: '802.1X (s)',
                        data: <?= json_encode($series['auth'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                        borderColor: '<?= $timingColors['auth'] ?>',
                        backgroundColor: '<?= $timingColors['auth'] ?>',
                    },
                    <?php endif; ?>
                    {
                        label: 'DHCP (s)',
                        data: <?= json_encode($series['dhcp'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                        borderColor: '<?= $timingColors['dhcp'] ?>',
                        backgroundColor: '<?= $timingColors['dhcp'] ?>',
                    }
                ]
            },
            options: { scales: { y: { beginAtZero: true } } }
        });
        <?php if ($hasIperf3): ?>
        new Chart(document.getElementById('iperf3Chart<?= $chartId ?>'), {
            type: 'line',
            data: {
                labels: <?= json_encode($series['labels'], JSON_HEX_TAG) ?>,
                datasets: [
                    {
                        label: 'Upload (Mbit/s)',
                        data: <?= json_encode($series['iperf3'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                    },
                    {
                        label: 'Download (Mbit/s)',
                        data: <?= json_encode($series['iperf3_down'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                    }
                ]
            },
            options: { scales: { y: { beginAtZero: true } } }
        });
        <?php endif; ?>
    };
    </script>
</section>
<?php endforeach; ?>

<?php if ($canWrite): ?>
<script>
// Auswahl/Filter/Bulk-Aktionen fuer die "Letzte Tests"-Tabellen (eine pro
// SSID-Tab, per data-chart-id/#testTable<id> unterschieden) - einmalig per
// Event-Delegation statt pro Tab neu registriert, da alle Tabellen
// gleich aufgebaut sind.
(function () {
    function csvEscape(value) {
        value = String(value === null || value === undefined ? '' : value);
        if (/[",\n]/.test(value)) {
            return '"' + value.replace(/"/g, '""') + '"';
        }
        return value;
    }

    function selectedRows(chartId) {
        var table = document.getElementById('testTable' + chartId);
        return table ? Array.prototype.slice.call(table.querySelectorAll('.row-select:checked')) : [];
    }

    function updateBulkUi(chartId) {
        var count = selectedRows(chartId).length;
        var actions = document.getElementById('testBulkActions' + chartId);
        var countEl = document.getElementById('testBulkCount' + chartId);
        if (!actions || !countEl) { return; }
        actions.hidden = count === 0;
        countEl.textContent = <?= tjson('%d ausgewählt') ?>.replace('%d', count);
    }

    document.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('row-select')) {
            var table = ev.target.closest('table');
            updateBulkUi(table.id.replace('testTable', ''));
            return;
        }
        if (ev.target.classList.contains('test-select-all')) {
            var chartId = ev.target.dataset.chartId;
            var table = document.getElementById('testTable' + chartId);
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                if (tr.style.display === 'none') { return; }
                var cb = tr.querySelector('.row-select');
                if (cb) { cb.checked = ev.target.checked; }
            });
            updateBulkUi(chartId);
            return;
        }
        if (ev.target.classList.contains('test-filter-failed')) {
            var chartId = ev.target.dataset.chartId;
            var table = document.getElementById('testTable' + chartId);
            var onlyFailed = ev.target.checked;
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                var show = !onlyFailed || tr.dataset.status === 'fail';
                tr.style.display = show ? '' : 'none';
                if (!show) {
                    var cb = tr.querySelector('.row-select');
                    if (cb) { cb.checked = false; }
                }
            });
            var selectAll = document.querySelector('.test-select-all[data-chart-id="' + chartId + '"]');
            if (selectAll) { selectAll.checked = false; }
            updateBulkUi(chartId);
        }
    });

    document.addEventListener('click', function (ev) {
        var delBtn = ev.target.closest('.test-bulk-delete');
        if (delBtn) {
            var chartId = delBtn.dataset.chartId;
            var ids = selectedRows(chartId).map(function (cb) { return cb.value; });
            if (ids.length === 0) { return; }
            if (!confirm(<?= tjson('%d Connection-Test(s) löschen?') ?>.replace('%d', ids.length))) { return; }
            var form = document.getElementById('testBulkDeleteForm' + chartId);
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
            form.submit();
            return;
        }

        var csvBtn = ev.target.closest('.test-bulk-csv');
        if (csvBtn) {
            var chartId = csvBtn.dataset.chartId;
            var rows = selectedRows(chartId).map(function (cb) { return JSON.parse(cb.dataset.row); });
            if (rows.length === 0) { return; }
            var headers = Object.keys(rows[0]);
            var lines = [headers.join(',')];
            rows.forEach(function (row) {
                lines.push(headers.map(function (h) { return csvEscape(row[h]); }).join(','));
            });
            var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'connection-tests-' + chartId + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            return;
        }

        var copyBtn = ev.target.closest('.test-bulk-copy');
        if (copyBtn) {
            var chartId = copyBtn.dataset.chartId;
            var rows = selectedRows(chartId).map(function (cb) { return JSON.parse(cb.dataset.row); });
            if (rows.length === 0) { return; }
            var headers = Object.keys(rows[0]);
            var lines = [headers.join('\t')];
            rows.forEach(function (row) {
                lines.push(headers.map(function (h) { return String(row[h] === null || row[h] === undefined ? '' : row[h]); }).join('\t'));
            });
            var text = lines.join('\n');
            var originalLabel = copyBtn.innerHTML;
            navigator.clipboard.writeText(text).then(function () {
                copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> ' + <?= tjson('Kopiert') ?>;
                setTimeout(function () { copyBtn.innerHTML = originalLabel; }, 1500);
            }).catch(function () {
                alert(<?= tjson('Kopieren fehlgeschlagen - Zwischenablage nicht verfügbar.') ?>);
            });
        }
    });
})();
</script>
<?php endif; ?>

<?php if (!empty($lanTests)): ?>
<section class="tab-panel" id="tab-lan" role="tabpanel" hidden>
    <div class="chart-row">
        <div class="chart-col chart-col-wide">
            <p class="muted chart-label">Upload / Download (Mbit/s)</p>
            <canvas id="lanChart" height="60"></canvas>
        </div>
    </div>

    <h2>
        <span><i class="fa-solid fa-table-list"></i> <?= te('Letzte Messungen') ?></span>
        <?php if ($canWrite): ?>
        <form method="post" action="<?= e($deviceUrl) ?>/measurements/delete-all"
              onsubmit="return confirm(<?= tjs('Alle LAN-Durchsatz-Messungen dieses Geräts löschen?') ?>);" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="lan_test">
            <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Alle LAN-Tests löschen') ?></button>
        </form>
        <?php endif; ?>
    </h2>
    <div class="table-scroll">
    <table>
        <thead>
            <tr><th><?= te('Zeitpunkt') ?></th><th>Interface / IP</th><th><?= te('Ziel') ?></th><th>Upload</th><th>Download</th><th><?= te('Zähler') ?></th><th><?= te('Fehler') ?></th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($lanTests, 0, 20) as $t): $d = json_decode((string) $t['data'], true) ?: []; ?>
            <tr>
                <td><?= e(format_local($t['client_timestamp'] ?? $t['received_at'], 'd.m. H:i:s')) ?></td>
                <td><?= e($d['interface'] ?? '') ?><?= !empty($d['ip_address']) ? ' · ' . e($d['ip_address']) : '' ?></td>
                <td><?= e($d['server'] ?? '') ?></td>
                <td>
                    <?= isset($d['upload_mbps']) ? e($d['upload_mbps']) . ' Mbit/s' : '–' ?>
                    <?php if (isset($d['upload_retransmits'])): ?>
                        <br><span class="muted"><?= e($d['upload_retransmits']) ?> Retr</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= isset($d['download_mbps']) ? e($d['download_mbps']) . ' Mbit/s' : '–' ?>
                    <?php if (isset($d['download_retransmits'])): ?>
                        <br><span class="muted"><?= e($d['download_retransmits']) ?> Retr</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e(format_counters(is_array($d['counters'] ?? null) ? $d['counters'] : null)) ?></td>
                <td class="muted"><?= e(probe_error_text($d)) ?></td>
                <td>
                    <a href="<?= e($deviceUrl) ?>/measurements/<?= (int) $t['id'] ?>/raw" target="_blank"
                       class="btn-secondary btn-small raw-link" title="<?= te('Rohdaten (JSON) in neuem Tab öffnen') ?>">
                        <i class="fa-solid fa-code"></i>
                    </a>
                    <?php if ($canWrite): ?>
                    <form method="post" action="<?= e($deviceUrl) ?>/measurements/<?= (int) $t['id'] ?>/delete"
                          onsubmit="return confirm(<?= tjs('Diese Messung löschen?') ?>);" class="inline-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-remove btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Löschen') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <script>
    tabInit['lan'] = function () {
        new Chart(document.getElementById('lanChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($lanSeries['labels'], JSON_HEX_TAG) ?>,
                datasets: [
                    {
                        label: 'Upload (Mbit/s)',
                        data: <?= json_encode($lanSeries['upload'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                    },
                    {
                        label: 'Download (Mbit/s)',
                        data: <?= json_encode($lanSeries['download'], JSON_HEX_TAG) ?>,
                        borderWidth: 2,
                        spanGaps: true,
                    }
                ]
            },
            options: { scales: { y: { beginAtZero: true } } }
        });
    };
    </script>
</section>
<?php endif; ?>

<?php if ($health !== null || !empty($healthSeries['labels'])): ?>
<section class="tab-panel" id="tab-system" role="tabpanel" hidden>
<h2><span><i class="fa-solid fa-microchip"></i> <?= te('Systemwerte') ?></span></h2>
<?php
// Warnstufe eines Werts: orange ab $warn, rot ab $fail (null = keine Stufe).
$healthLevel = static fn(?float $v, float $warn, ?float $fail = null): string
    => $v === null ? '' : (($fail !== null && $v >= $fail) ? 'fail' : ($v >= $warn ? 'warn' : ''));
$hbAge = !empty($device['heartbeat_at']) ? max(0, time() - (int) strtotime($device['heartbeat_at'] . ' UTC')) : null;
$fmtAge = static fn(int $s): string => $s < 120 ? __('vor %d s', $s) : ($s < 7200 ? __('vor %d min', intdiv($s, 60)) : __('vor %d h', intdiv($s, 3600)));
$fmtUptime = static function (float $sec): string {
    $sec = (int) $sec;
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    return $d > 0 ? __('%d T %d h', $d, $h) : ($h > 0 ? __('%d h %d min', $h, $m) : __('%d min', $m));
};
$h = $health ?? [];
?>
<p class="muted">
    <?= te('Letzter Heartbeat:') ?>
    <?php if ($hbAge !== null): ?>
        <span class="<?= $hbAge > 600 ? 'warn' : '' ?>" title="<?= e(format_local($device['heartbeat_at'], 'd.m.Y H:i:s')) ?>"><?= e($fmtAge($hbAge)) ?></span>
    <?php else: ?>
        <?= te('noch keiner') ?>
    <?php endif; ?>
    · <?= te('Verlauf: höchstens ein Punkt alle 5 Minuten') ?>
</p>
<?php if ($health !== null): ?>
<div class="kpi-row">
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-temperature-half"></i> <?= te('Temperatur') ?></span>
        <span class="kpi-value <?= $healthLevel($h['temperature_c'] ?? null, 70, 80) ?>">
            <?= isset($h['temperature_c']) ? e(number_format((float) $h['temperature_c'], 1)) . ' °C' : '–' ?>
        </span>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-microchip"></i> <?= te('CPU') ?></span>
        <span class="kpi-value <?= $healthLevel($h['cpu_percent'] ?? null, 90) ?>">
            <?= isset($h['cpu_percent']) ? e(round((float) $h['cpu_percent'])) . ' %' : '–' ?>
        </span>
        <?php if (isset($h['load'])): ?>
            <span class="muted" title="<?= te('Durchschnittliche Last über 1, 5 und 15 Minuten') ?>"><?= te('Last') ?> <?= e(implode(' / ', array_map(fn($v) => number_format((float) $v, 2), $h['load']))) ?><?= isset($h['cpu_count']) ? ' · ' . te('%d Kerne', (int) $h['cpu_count']) : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-memory"></i> <?= te('Arbeitsspeicher') ?></span>
        <span class="kpi-value <?= $healthLevel($h['mem_used_percent'] ?? null, 90, 95) ?>">
            <?= isset($h['mem_used_percent']) ? e(round((float) $h['mem_used_percent'])) . ' %' : '–' ?>
        </span>
        <?php if (isset($h['mem_total_mb'], $h['mem_available_mb'])): ?>
            <span class="muted"><?= te('%s von %s MB frei', e(round((float) $h['mem_available_mb'])), e(round((float) $h['mem_total_mb']))) ?></span>
        <?php endif; ?>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-hard-drive"></i> <?= te('Speicherplatz') ?></span>
        <span class="kpi-value <?= $healthLevel($h['disk_used_percent'] ?? null, 85, 95) ?>">
            <?= isset($h['disk_used_percent']) ? e(round((float) $h['disk_used_percent'])) . ' %' : '–' ?>
        </span>
        <?php if (isset($h['disk_free_mb'], $h['disk_total_mb'])): ?>
            <span class="muted"><?= te('%s von %s GB frei', e(number_format((float) $h['disk_free_mb'] / 1024, 1)), e(number_format((float) $h['disk_total_mb'] / 1024, 1))) ?></span>
        <?php endif; ?>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-clock"></i> <?= te('Laufzeit') ?></span>
        <span class="kpi-value"><?= isset($h['uptime_seconds']) ? e($fmtUptime((float) $h['uptime_seconds'])) : '–' ?></span>
    </div>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-inbox"></i> <?= te('Nicht übertragen') ?></span>
        <span class="kpi-value <?= $healthLevel($h['queue_unsent'] ?? null, 100) ?>" title="<?= te('Messungen in der Warteschlange der Probe, die das Dashboard noch nicht erreicht haben') ?>">
            <?= isset($h['queue_unsent']) ? e((int) $h['queue_unsent']) : '–' ?>
        </span>
    </div>
    <?php if (isset($h['throttled']) || isset($h['power_source']) || isset($h['hat']) || isset($h['ext5v_volts'])):
        // Raspberry Pi: Bitmaske von "vcgencmd get_throttled" - Bits 0-3 jetzt, 16-19 seit dem Start.
        $thr = (int) ($h['throttled'] ?? 0);
        $thrNames = [0 => __('Unterspannung'), 1 => __('Takt begrenzt'), 2 => __('gedrosselt'), 3 => __('Temperaturgrenze')];
        $thrNow = array_values(array_filter($thrNames, fn($b) => ($thr >> $b) & 1, ARRAY_FILTER_USE_KEY));
        $thrPast = array_values(array_filter($thrNames, fn($b) => ($thr >> ($b + 16)) & 1, ARRAY_FILTER_USE_KEY));
        ?>
    <div class="kpi-tile">
        <span class="kpi-label"><i class="fa-solid fa-bolt"></i> <?= te('Stromversorgung') ?></span>
        <?php if (($h['power_source'] ?? null) === 'poe_hat'): ?>
            <span class="kpi-value kpi-value-small"><i class="fa-solid fa-ethernet"></i> <?= te('PoE (HAT)') ?></span>
        <?php endif; ?>
        <?php if (isset($h['throttled'])): ?>
        <span class="kpi-value kpi-value-small <?= $thrNow ? 'fail' : ($thrPast ? 'warn' : 'ok') ?>">
            <?= $thrNow ? e(implode(', ', $thrNow)) : ($thrPast ? te('seit dem Start: %s', implode(', ', $thrPast)) : te('in Ordnung')) ?>
        </span>
        <?php endif; ?>
        <?php if (isset($h['hat'])): ?>
            <span class="muted" title="<?= te('Aufgesteckte Erweiterungsplatine laut ihrem ID-Speicher') ?>"><?= e($h['hat']) ?></span>
        <?php endif; ?>
        <?php if (isset($h['ext5v_volts']) || isset($h['psu_max_current_ma'])): ?>
            <span class="muted">
                <?php if (isset($h['ext5v_volts'])): ?>
                    <span class="<?= (float) $h['ext5v_volts'] < 4.85 ? 'warn' : '' ?>" title="<?= te('Spannung am 5-V-Eingang des Raspberry Pi 5') ?>"><?= e(number_format((float) $h['ext5v_volts'], 2)) ?> V</span>
                <?php endif; ?>
                <?php if (isset($h['psu_max_current_ma'])): ?>
                    · <span title="<?= te('Strom, den die Firmware der Quelle zutraut (USB-PD-Aushandlung, sonst 3 A)') ?>"><?= te('Quelle max. %s A', e(number_format((int) $h['psu_max_current_ma'] / 1000, 1))) ?></span>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        <?php if (($h['usb_max_current_enable'] ?? null) === false && ($h['wifi_usb'] ?? null) === true
                  && (int) ($h['psu_max_current_ma'] ?? 3000) <= 3000): ?>
            <span class="warn"><?= te('USB-Ports auf 600 mA begrenzt – der USB-WLAN-Adapter bekommt unter Last evtl. zu wenig Strom. Liefert das Netzteil bzw. der PoE-HAT 5 A: „sudo rpi-eeprom-config --edit“, dort PSU_MAX_CURRENT=5000, neu starten.') ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($healthSeries['labels'])): ?>
<div class="chart-row">
    <div class="chart-col chart-col-wide">
        <p class="muted chart-label"><?= te('Verlauf der letzten 24 Stunden') ?></p>
        <canvas id="healthChart" height="70"></canvas>
    </div>
</div>
<script>
tabInit['system'] = function () {
    new Chart(document.getElementById('healthChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($healthSeries['labels'], JSON_HEX_TAG) ?>,
            datasets: [
                { label: <?= tjson('Temperatur (°C)') ?>, data: <?= json_encode($healthSeries['temperature'], JSON_HEX_TAG) ?>, yAxisID: 'temp', borderWidth: 2, spanGaps: true },
                { label: <?= tjson('CPU (%)') ?>, data: <?= json_encode($healthSeries['cpu'], JSON_HEX_TAG) ?>, yAxisID: 'pct', borderWidth: 1.5, spanGaps: true },
                { label: <?= tjson('Arbeitsspeicher (%)') ?>, data: <?= json_encode($healthSeries['mem'], JSON_HEX_TAG) ?>, yAxisID: 'pct', borderWidth: 1.5, spanGaps: true },
                { label: <?= tjson('Speicherplatz (%)') ?>, data: <?= json_encode($healthSeries['disk'], JSON_HEX_TAG) ?>, yAxisID: 'pct', borderWidth: 1.5, spanGaps: true }
            ]
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            scales: {
                pct: { position: 'left', min: 0, max: 100, title: { display: true, text: '%' } },
                temp: { position: 'right', suggestedMin: 30, suggestedMax: 80, grid: { drawOnChartArea: false }, title: { display: true, text: '°C' } }
            }
        }
    });
};
</script>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="tab-panel" id="tab-verlauf" role="tabpanel" hidden>
<h2>
    <i class="fa-solid fa-chart-line"></i> <?= te('Verlauf') ?>
    <span class="h2-actions timeline-ranges">
        <?php foreach (TIMELINE_HOURS as $h): ?>
            <button type="button" class="btn-secondary btn-small timeline-range" data-hours="<?= (int) $h ?>"><?= (int) $h ?> h</button>
        <?php endforeach; ?>
    </span>
</h2>
<form class="timeline-custom" id="timelineCustom">
    <label><?= te('Von') ?> <input type="datetime-local" id="timelineFrom" required></label>
    <label><?= te('Bis') ?> <input type="datetime-local" id="timelineTo" required></label>
    <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-magnifying-glass"></i> <?= te('Anzeigen') ?></button>
    <span class="muted"><?= te('frei wählbar, höchstens %d Tage', TIMELINE_MAX_SPAN_DAYS) ?></span>
</form>
<p class="muted chart-label">
    <?= te('Grau hinterlegt: Zeiträume, in denen die Probe selbst iperf3 gefahren hat - eine Auslastungsspitze dort ist die eigene Messung, nicht der Alltag. Weil die Probe während iperf3 nicht scannen kann, zeigt sich die Last erst im ersten Scan danach (bis 3 min, die Auswertung unten berücksichtigt das).') ?>
    <?= te('Auslastung: Dreiecke = Grundlast, die die Probe beim Verbinden selbst auf dem Kanal misst (ohne eigenen Verkehr; nur bei Chips, die das melden); Linie = BSS Load laut Beacon (Probe-Scan), Kreise = OmniVista Cirrus (stündlicher Sync). Werte beim Überfahren; Einzelwerte in den SSID- und Scan-Tabs.') ?>
</p>
<div class="timeline-card">
    <div class="timeline-title"><?= te('Kanalauslastung der eigenen AP-Radios') ?> <span class="muted">(%)</span></div>
    <div class="timeline-canvas"><canvas id="timelineUtil" role="img" aria-label="<?= te('Kanalauslastung im Verlauf') ?>"></canvas></div>
    <p class="muted timeline-empty" id="timelineUtilEmpty" hidden><?= te('Keine BSS-Load-Werte der Ziel-SSIDs im Zeitraum (sendet der AP kein BSS-Load-Element oder ist die Probe älter als 1.0.1.1?).') ?></p>
</div>
<div class="timeline-card">
    <div class="timeline-title"><?= te('Latenz je Ziel-SSID') ?> <span class="muted"><?= te('(ms; durchgezogen = Ping, gestrichelt = HTTP-Antwort)') ?></span></div>
    <div class="timeline-canvas"><canvas id="timelineLatency" role="img" aria-label="<?= te('Latenz im Verlauf') ?>"></canvas></div>
    <p class="muted timeline-empty" id="timelineLatencyEmpty" hidden><?= te('Keine Latenzwerte im Zeitraum.') ?></p>
</div>
<p class="muted chart-label" id="timelineStatus"></p>
<script>
// "Cirrus-Anmeldung" in der Fehlerspalte: Ergebnis einmal nachladen und unter dem Link einsetzen.
document.addEventListener('click', function (ev) {
    var link = ev.target.closest('a.cirrus-auth-link');
    if (!link) { return; }
    ev.preventDefault();
    var box = link.parentNode.nextElementSibling;
    if (box && box.classList.contains('cirrus-auth-box')) { box.hidden = !box.hidden; return; }
    box = document.createElement('div');
    box.className = 'cirrus-auth-box';
    box.textContent = <?= tjson('Frage Cirrus ab …') ?>;
    link.parentNode.after(box);
    fetch(link.href, {credentials: 'same-origin'})
        .then(function (r) { return r.text(); })
        .then(function (html) { box.innerHTML = html; })
        .catch(function () { box.textContent = <?= tjson('Abfrage fehlgeschlagen.') ?>; });
});
</script>
<script src="/static/timeline.js"></script>
<script>
// Erst beim Öffnen des Tabs laden und zeichnen (JSON von /devices/<id>/timeline).
tabInit['verlauf'] = function () { window.wlanmonTimeline.init(<?= json_encode($deviceUrl . '/timeline', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>); };
</script>
</section>

<section class="tab-panel" id="tab-scans" role="tabpanel" hidden>
<?php if (!empty($scans)): ?>
    <?php
    // Daten für die Spektrumansicht (static/spectrum.js): alle angezeigten
    // Scans, je Netz nur die nötigen Felder. Ziel-SSIDs alphabetisch, damit
    // eine SSID unabhängig von Band/Scan immer dieselbe Farbe bekommt.
    $spectrumTargets = array_map('strval', array_keys($testsBySsid));
    sort($spectrumTargets, SORT_STRING);
    $spectrumScans = [];
    foreach ($scans as $sp) {
        $spd = json_decode((string) $sp['data'], true) ?: [];
        $spNets = [];
        foreach (is_array($spd['networks'] ?? null) ? $spd['networks'] : [] as $n) {
            if (!isset($n['frequency_mhz'], $n['signal_dbm'])) {
                continue;
            }
            $spAp = cirrus_lookup_ap((string) ($n['bssid'] ?? ''));
            $spNets[] = [
                'ssid' => scan_ssid($n['ssid'] ?? null),
                'bssid' => (string) ($n['bssid'] ?? ''),
                'ap' => $spAp['ap_name'] ?? null,
                'signal' => (float) $n['signal_dbm'],
                'freq' => (int) $n['frequency_mhz'],
                // Ältere Probes ohne Mitte/Breite: Hauptkanal, 20 MHz.
                'center' => (int) ($n['center_freq_mhz'] ?? $n['frequency_mhz']),
                'width' => (int) ($n['channel_width_mhz'] ?? 20),
                'channel' => $n['channel'] ?? null,
            ];
        }
        $spectrumScans[] = [
            'label' => format_local($sp['client_timestamp'] ?? $sp['received_at'], 'd.m. H:i:s'),
            'nets' => $spNets,
        ];
    }
    ?>
    <div class="spectrum-card">
        <div class="spectrum-head">
            <strong><?= te('Spektrum') ?></strong>
            <span class="muted">Scan <span id="spectrumScanLabel"></span></span>
            <span class="spectrum-bands">
                <button type="button" class="btn-secondary btn-small spectrum-band" data-band="2.4"><?= te('2,4 GHz') ?></button>
                <button type="button" class="btn-secondary btn-small spectrum-band" data-band="5">5 GHz</button>
                <button type="button" class="btn-secondary btn-small spectrum-band" data-band="6">6 GHz</button>
            </span>
        </div>
        <div id="spectrumLegend" class="spectrum-legend"></div>
        <div class="spectrum-wrap">
            <svg id="spectrumSvg" role="img" aria-label="<?= te('Spektrum') ?>"></svg>
            <div id="spectrumTip" class="spectrum-tip" hidden></div>
        </div>
        <p class="muted chart-label">
            <?= te('Je Netz ein Trapez über die belegte Kanalbreite, Höhe = Signal. Farbig und beschriftet: eigene Ziel-SSIDs; grau: andere Netze (Details beim Überfahren). Anderen Scan über „Im Spektrum zeigen“ im jeweiligen Scan-Block wählen.') ?>
        </p>
    </div>
    <script type="application/json" id="spectrumData"><?= json_encode(['targets' => $spectrumTargets, 'scans' => $spectrumScans], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="/static/spectrum.js"></script>
    <script>
    // Erst beim Öffnen des Tabs zeichnen - im versteckten Tab hat das SVG keine Breite.
    tabInit['scans'] = function () { window.wlanmonSpectrum.init(); };
    </script>
<?php endif; ?>

<h2>
    <i class="fa-solid fa-magnifying-glass"></i> <?= te('Letzte Scans') ?>
    <span class="h2-actions">
        <form method="get" action="<?= e($deviceUrl) ?>" class="inline-form">
            <label class="inline-label">
                <?= te('Anzahl') ?>
                <select name="scan_limit" onchange="this.form.submit()">
                    <?php foreach ($allowedScanLimits as $limit): ?>
                        <option value="<?= (int) $limit ?>" <?= $scanLimit === $limit ? 'selected' : '' ?>><?= (int) $limit ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if (!empty($scans) && $canWrite): ?>
        <form method="post" action="<?= e($deviceUrl) ?>/measurements/delete-all"
              onsubmit="return confirm(<?= tjs('Alle Scan-Messungen dieses Geräts löschen?') ?>);" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="scan">
            <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Alle Scans löschen') ?></button>
        </form>
        <?php endif; ?>
    </span>
</h2>
<?php if (empty($scans)): ?>
    <p class="empty"><?= te('Noch keine Scans empfangen.') ?></p>
<?php else: ?>

    <div class="scan-filters">
        <label>
            SSID
            <input type="text" id="scanFilterSsid" placeholder="<?= te('z.B. WLAN') ?>">
        </label>
        <label>
            BSSID
            <input type="text" id="scanFilterBssid" placeholder="<?= te('z.B. aa:bb:cc') ?>">
        </label>
        <label>
            Band
            <select id="scanFilterBand">
                <option value=""><?= te('Alle') ?></option>
                <option value="2.4"><?= te('2,4 GHz') ?></option>
                <option value="5">5 GHz</option>
                <option value="6">6 GHz</option>
            </select>
        </label>
        <label>
            <?= te('Kanal') ?>
            <input type="text" id="scanFilterChannel" placeholder="<?= te('z.B. 36') ?>">
        </label>
    </div>
    <p class="muted chart-label"><?= te('Spaltenköpfe klicken zum Sortieren (pro Scan-Block einzeln).') ?></p>

    <?php $cirrusEnabled = cirrus_config() !== null; ?>
    <?php foreach ($scans as $scanIndex => $s): $sd = json_decode((string) $s['data'], true) ?: []; $networks = is_array($sd['networks'] ?? null) ? $sd['networks'] : [];
        // Standardsortierung: stärkstes Signal zuerst (Netze ohne Signalwert ans Ende),
        // passend zum Pfeil am Spaltenkopf; per Klick weiter umsortierbar.
        usort($networks, fn(array $a, array $b): int => ($b['signal_dbm'] ?? -999) <=> ($a['signal_dbm'] ?? -999)); ?>
        <div class="scan-block">
        <details>
            <summary>
                <?= e(format_local($s['client_timestamp'] ?? $s['received_at'], 'd.m. H:i:s')) ?>
                – <?= te('%d Netze', count($networks)) ?>
            </summary>
            <a href="<?= e($deviceUrl) ?>/measurements/<?= (int) $s['id'] ?>/raw" target="_blank"
               class="btn-secondary btn-small inline-form" title="<?= te('Rohdaten (JSON) in neuem Tab öffnen') ?>">
                <i class="fa-solid fa-code"></i> <?= te('Rohdaten') ?>
            </a>
            <button type="button" class="btn-secondary btn-small inline-form spectrum-show" data-scan-index="<?= (int) $scanIndex ?>">
                <i class="fa-solid fa-chart-area"></i> <?= te('Im Spektrum zeigen') ?>
            </button>
            <?php if ($canWrite): ?>
            <form method="post" action="<?= e($deviceUrl) ?>/measurements/<?= (int) $s['id'] ?>/delete"
                  onsubmit="return confirm(<?= tjs('Diesen Scan löschen?') ?>);" class="inline-form scan-delete-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn-remove btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Diesen Scan löschen') ?></button>
            </form>
            <?php endif; ?>
            <div class="scan-lazy" data-url="<?= e($deviceUrl) ?>/measurements/<?= (int) $s['id'] ?>/scan-table">
                <p class="muted"><i class="fa-solid fa-spinner fa-spin"></i> <?= te('Lade Netze …') ?></p>
            </div>
        </details>
        </div>
    <?php endforeach; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var ssidInput = document.getElementById('scanFilterSsid');
        var bssidInput = document.getElementById('scanFilterBssid');
        var bandSelect = document.getElementById('scanFilterBand');
        var channelInput = document.getElementById('scanFilterChannel');

        // Spaltenköpfe einer (nachgeladenen) Scan-Tabelle sortierbar machen.
        function bindSort(table) {
            var headers = table.querySelectorAll('th[data-sort]');
            headers.forEach(function (th) {
                th.addEventListener('click', function () {
                    var tbody = table.querySelector('tbody');
                    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    var key = th.dataset.sort;
                    var numeric = ['signal', 'channel', 'stations', 'util', 'cirrus', 'gen'].indexOf(key) !== -1;
                    var dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
                    headers.forEach(function (h) { delete h.dataset.dir; });
                    th.dataset.dir = dir;

                    rows.sort(function (a, b) {
                        var va = a.dataset[key] || '';
                        var vb = b.dataset[key] || '';
                        if (numeric) {
                            va = parseFloat(va); if (isNaN(va)) { va = -9999; }
                            vb = parseFloat(vb); if (isNaN(vb)) { vb = -9999; }
                            return dir === 'asc' ? va - vb : vb - va;
                        }
                        return dir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
                    });
                    rows.forEach(function (r) { tbody.appendChild(r); });
                });
            });
        }

        // Tabelle eines Scans einmal nachladen (beim Aufklappen oder vor dem Filtern).
        function loadBlock(block) {
            var slot = block.querySelector('.scan-lazy');
            if (!slot) { return Promise.resolve(); }
            if (slot._loading) { return slot._loading; }
            slot._loading = fetch(slot.dataset.url, {credentials: 'same-origin'})
                .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
                .then(function (html) {
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html;
                    slot.replaceWith.apply(slot, Array.prototype.slice.call(wrap.childNodes));
                    block.querySelectorAll('table.scan-table').forEach(bindSort);
                })
                .catch(function () {
                    slot._loading = null;
                    slot.innerHTML = '<p class="fail">' + <?= tjson('Netze konnten nicht geladen werden.') ?> + '</p>';
                });
            return slot._loading;
        }

        document.querySelectorAll('.scan-block details').forEach(function (details) {
            details.addEventListener('toggle', function () {
                if (details.open) { loadBlock(details.closest('.scan-block')); }
            });
        });

        function applyFilter() {
            var ssidQuery = ssidInput.value.trim().toLowerCase();
            var bssidQuery = bssidInput.value.trim().toLowerCase();
            var band = bandSelect.value;
            var channelQuery = channelInput.value.trim();
            var active = ssidQuery !== '' || bssidQuery !== '' || band !== '' || channelQuery !== '';
            var blocks = Array.prototype.slice.call(document.querySelectorAll('.scan-block'));

            // Zum Filtern müssen die Netze aller Scans da sein.
            Promise.all(active ? blocks.map(loadBlock) : []).then(function () {
                blocks.forEach(function (block) {
                    var visibleCount = 0;
                    block.querySelectorAll('table.scan-table tbody tr').forEach(function (row) {
                        var rowSsid = (row.dataset.ssid || '').toLowerCase();
                        var rowBssid = (row.dataset.bssid || '').toLowerCase();
                        var rowBand = row.dataset.band || '';
                        var rowChannel = row.dataset.channel || '';
                        var matches =
                            (ssidQuery === '' || rowSsid.indexOf(ssidQuery) !== -1) &&
                            (bssidQuery === '' || rowBssid.indexOf(bssidQuery) !== -1) &&
                            (band === '' || rowBand === band) &&
                            (channelQuery === '' || rowChannel === channelQuery);
                        row.style.display = matches ? '' : 'none';
                        if (matches) { visibleCount++; }
                    });
                    block.style.display = (active && visibleCount === 0) ? 'none' : '';
                    if (active && visibleCount > 0) {
                        block.querySelector('details').open = true;
                    }
                });
            });
        }

        [ssidInput, bssidInput, bandSelect, channelInput].forEach(function (el) {
            el.addEventListener('input', applyFilter);
            el.addEventListener('change', applyFilter);
        });
    });
    </script>
<?php endif; ?>
</section>

<script>
(function () {
    var storageKey = 'wlanmon-tab-' + <?= json_encode((string) $device['id'], JSON_HEX_TAG) ?>;
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.tab-btn'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.tab-panel'));

    function activate(id) {
        var known = buttons.some(function (b) { return b.dataset.tab === id; });
        if (!known) { id = buttons[0].dataset.tab; }

        buttons.forEach(function (b) {
            var on = b.dataset.tab === id;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(function (p) { p.hidden = p.id !== 'tab-' + id; });

        // Diagramme dieses Tabs beim ersten Öffnen erzeugen (Container ist jetzt sichtbar).
        if (window.tabInit[id]) {
            window.tabInit[id]();
            delete window.tabInit[id];
        }
        try { sessionStorage.setItem(storageKey, id); } catch (e) { /* Storage evtl. gesperrt */ }
        if (history.replaceState) { history.replaceState(null, '', '#' + id); }
    }

    buttons.forEach(function (b) {
        b.addEventListener('click', function () { activate(b.dataset.tab); });
    });

    // Start-Tab: URL-Anker > zuletzt geöffneter Tab dieses Geräts > erster Tab.
    // Der gemerkte Tab überdauert das Neuladen nach Lösch-/Filteraktionen.
    var initial = location.hash.replace('#', '');
    if (!initial) {
        try { initial = sessionStorage.getItem(storageKey) || ''; } catch (e) { initial = ''; }
    }
    activate(initial);
})();
</script>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
