<?php
/** @var array $rows */
/** @var string|null $deletedDevice */
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Geräte') ?> – WLANMON</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/static/style.css">
    <link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
    <script src="/static/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<main>
<h1><?= te('Geräte') ?>
    <?php if ((current_user()['role'] ?? null) === 'admin'): ?>
        <a href="/devices/new" class="btn"><i class="fa-solid fa-plus"></i> <?= te('Neues Gerät') ?></a>
    <?php endif; ?>
</h1>

<?php $cirrusStatus = cirrus_sync_status(); ?>
<?php if ($cirrusStatus['configured']): ?>
    <?php $cirrusStale = cirrus_sync_is_stale($cirrusStatus['last_sync_at']); ?>
    <p class="muted">
        <i class="fa-solid fa-cloud"></i> OmniVista Cirrus:
        <span class="pill <?= $cirrusStale ? 'pill-warn' : 'pill-ok' ?>">
            <i class="fa-solid <?= $cirrusStale ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
            <?= $cirrusStale ? te('Sync veraltet') : te('verbunden') ?>
        </span>
        · <?= te('%d Gerät(e) bekannt', (int) $cirrusStatus['ap_count']) ?>
        · <?= te('letzter Sync:') ?>
        <?= $cirrusStatus['last_sync_at'] !== null ? e(format_local($cirrusStatus['last_sync_at'], 'd.m.Y H:i')) : te('noch nie') ?>
    </p>
<?php elseif ((current_user()['role'] ?? null) === 'admin'): ?>
    <p class="muted">
        <i class="fa-solid fa-cloud"></i> <?= te('OmniVista Cirrus: nicht konfiguriert (siehe README, Abschnitt „OmniVista Cirrus“).') ?>
    </p>
<?php endif; ?>

<?php if (!empty($deletedDevice)): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Gerät „%s“ wurde gelöscht.', $deletedDevice) ?></p>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <p class="empty"><?= te('Noch keine Geräte registriert. Anlegen per API:') ?>
    <code>POST /api/v1/admin/devices</code> <?= te('(siehe README).') ?></p>
<?php else: ?>
    <?php
    // Standorte fuer den Filter - nur die, die in der (bereits auf die
    // erlaubten Sites gefilterten) Liste tatsaechlich vorkommen.
    $siteNames = array_values(array_unique(array_filter(array_map(
        fn(array $r): string => (string) ($r['device']['site_name'] ?? ''), $rows
    ), fn(string $n): bool => $n !== '')));
    sort($siteNames, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
    <div class="scan-filters" id="deviceFilters">
        <label>
            <?= te('Suche') ?>
            <input type="search" id="deviceFilterText" placeholder="<?= te('Gerät oder IP') ?>">
        </label>
        <?php if (count($siteNames) > 1): ?>
        <label>
            <?= te('Standort') ?>
            <select id="deviceFilterSite">
                <option value=""><?= te('Alle') ?></option>
                <?php foreach ($siteNames as $siteName): ?>
                    <option value="<?= e($siteName) ?>"><?= e($siteName) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>
            <?= te('Status') ?>
            <select id="deviceFilterStatus">
                <option value=""><?= te('Alle') ?></option>
                <option value="online"><?= te('Online') ?></option>
                <option value="offline"><?= te('Offline') ?></option>
            </select>
        </label>
        <label class="filter-check" title="<?= te('Offline, nicht alle Ziel-SSIDs verbunden, Update-Fehler oder LAN-Test fehlgeschlagen') ?>">
            <span><input type="checkbox" id="deviceFilterProblems"> <?= te('Nur Geräte mit Problemen') ?></span>
        </label>
        <button type="button" class="btn-secondary btn-small" id="deviceFilterReset"><?= te('Filter zurücksetzen') ?></button>
        <span class="muted" id="deviceFilterCount"></span>
        <?php // Rechts ueber der Tabelle: Countdown und Intervall der automatischen Aktualisierung. ?>
        <div class="refresh-control">
            <span class="muted" id="deviceRefreshInfo"></span>
            <label title="<?= te('Lädt die Gerätezeilen im Hintergrund neu, ohne Filter, Sortierung oder Scrollposition zu verlieren (pausiert, solange der Tab nicht sichtbar ist)') ?>">
                <?= te('Automatisch aktualisieren') ?>
                <select id="deviceRefreshInterval">
                    <option value="0"><?= te('Aus') ?></option>
                    <option value="5"><?= te('5 Sekunden') ?></option>
                    <option value="10"><?= te('10 Sekunden') ?></option>
                    <option value="15"><?= te('15 Sekunden') ?></option>
                    <option value="30"><?= te('30 Sekunden') ?></option>
                    <option value="60"><?= te('1 Minute') ?></option>
                    <option value="300"><?= te('5 Minuten') ?></option>
                </select>
            </label>
        </div>
    </div>
    <div class="table-scroll">
    <table class="sortable" id="deviceTable">
        <thead>
            <tr>
                <th data-sort="online" data-type="num"><?= te('Status') ?></th>
                <th data-sort="site"><?= te('Standort') ?></th>
                <th data-sort="device"><?= te('Gerät') ?></th>
                <th data-sort="seen" data-type="num"><?= te('Zuletzt gesehen') ?></th>
                <th data-sort="version" data-type="version"><?= te('Version') ?></th>
                <th data-sort="networks" data-type="num"><?= te('Netze im letzten Scan') ?></th>
                <th data-sort="ssids" data-type="num" title="<?= te('Neuester Test je SSID') ?>"><?= te('Ziel-SSIDs verbunden') ?></th>
                <th data-sort="rate" data-type="num" title="<?= te('Über die letzten 100 Connection-Tests') ?>"><?= te('Erfolgsquote') ?></th>
                <th data-sort="assoc" data-type="num" title="<?= te('Über die letzten 100 Connection-Tests; sortiert nach der Assoziationszeit') ?>"><?= te('Ø Assoziation') ?> / 802.1X / DHCP</th>
                <th data-sort="lasttest" data-type="num" title="<?= te('Sortiert nach dem Zeitpunkt des Tests') ?>"><?= te('Letzter Connection-Test') ?></th>
                <th data-sort="lan" data-type="num" title="<?= te('LAN-Durchsatz per iperf3 (Upload / Download); sortiert nach dem Download') ?>">LAN ↑ / ↓</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): $d = $row['device'];
            // Werte fuer Filter und Sortierung (Skript unten). Leer = kein
            // Wert, landet beim Sortieren immer unten.
            // DB-Zeit (UTC ohne Zone) und Probe-Zeit (ISO mit Offset) gleichermassen.
            $utcTs = static function (?string $v): string {
                if (empty($v)) {
                    return '';
                }
                try {
                    return (string) (new DateTime($v, new DateTimeZone('UTC')))->getTimestamp();
                } catch (Exception $e) {
                    return '';
                }
            };
            $sortTs = $row['test_summary'];
            $sortScan = $row['last_scan'] ? (json_decode((string) $row['last_scan']['data'], true) ?: []) : [];
            $sortIps = is_array($sortScan['local_ips'] ?? null) ? implode(' ', $sortScan['local_ips']) : '';
            $sortTest = $row['last_test'];
            $sortLan = $row['last_lan'] ? (json_decode((string) $row['last_lan']['data'], true) ?: []) : null;
            $sortAu = json_decode((string) ($d['auto_update'] ?? ''), true);
            $problem = !$row['online']
                || ($sortTs['ssid_total'] > 0 && $sortTs['ssid_ok'] < $sortTs['ssid_total'])
                || (is_array($sortAu) && !empty($sortAu['enabled']) && auto_update_failure_level($sortAu) === 'error')
                || ($sortLan !== null && !isset($sortLan['upload_mbps']) && !isset($sortLan['download_mbps']));
            ?>
            <tr data-online="<?= $row['online'] ? 1 : 0 ?>"
                data-site="<?= e($d['site_name'] ?? '') ?>"
                data-device="<?= e($d['id']) ?>"
                data-search="<?= e(strtolower($d['id'] . ' ' . $sortIps)) ?>"
                data-seen="<?= e($utcTs($d['last_seen_at'] ?? null)) ?>"
                data-version="<?= e($d['probe_version'] ?? '') ?>"
                data-networks="<?= e($row['network_count'] ?? '') ?>"
                data-ssids="<?= $sortTs['ssid_total'] > 0 ? e($sortTs['ssid_ok'] / $sortTs['ssid_total']) : '' ?>"
                data-rate="<?= $sortTs['test_count'] > 0 ? e(100 * $sortTs['test_ok'] / $sortTs['test_count']) : '' ?>"
                data-assoc="<?= e($sortTs['avg_assoc'] ?? '') ?>"
                data-lasttest="<?= e($sortTest ? $utcTs($sortTest['client_timestamp'] ?? $sortTest['received_at'] ?? null) : '') ?>"
                data-lan="<?= e($sortLan['download_mbps'] ?? '') ?>"
                data-problem="<?= $problem ? 1 : 0 ?>">
                <td>
                    <span class="pill <?= $row['online'] ? 'pill-ok' : 'pill-fail' ?>">
                        <span class="status-dot <?= $row['online'] ? 'status-online' : 'status-offline' ?>" style="margin-right:0"></span>
                        <?= $row['online'] ? te('Online') : te('Offline') ?>
                    </span>
                </td>
                <td><?= e($d['site_name'] ?? '–') ?></td>
                <td>
                    <a href="/devices/<?= e($d['id']) ?>" class="nowrap"><?= e($d['id']) ?></a>
                    <?php
                    $rowScanData = $row['last_scan'] ? (json_decode((string) $row['last_scan']['data'], true) ?: []) : [];
                    $rowIps = is_array($rowScanData['local_ips'] ?? null) ? $rowScanData['local_ips'] : [];
                    foreach ($rowIps as $ifName => $ip): ?>
                        <br><span class="muted"><?= e($ip) ?> (<?= e($ifName) ?>)</span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if (!empty($d['last_seen_at'])): ?>
                        <span class="nowrap" title="<?= e(format_local($d['last_seen_at'], 'd.m.Y H:i:s')) ?>"><?= e(format_local_compact($d['last_seen_at'])) ?></span>
                    <?php else: ?>
                        <span class="muted"><?= te('noch nie') ?></span>
                    <?php endif; ?>
                </td>
                <td class="muted">
                    <?= e($d['probe_version'] ?? '–') ?>
                    <?php
                    // Branch, aus dem das Gerät seine Updates zieht (laut Probe,
                    // siehe devices.auto_update). Rot, wenn der letzte
                    // Update-Lauf fehlgeschlagen ist (Fehlertext im Tooltip).
                    $rowAu = json_decode((string) ($d['auto_update'] ?? ''), true);
                    ?>
                    <?php if (is_array($rowAu)): ?>
                        <?php if (empty($rowAu['enabled'])): ?>
                            <span title="<?= te('Auto-Update ausgeschaltet') ?>">(<?= te('manuell') ?>)</span>
                        <?php elseif (auto_update_failure_level($rowAu) === 'error'): ?>
                            <span class="fail" title="<?= te('Update-Läufe fehlgeschlagen') ?><?= isset($rowAu['fail_count']) ? ' (' . te('%d× in Folge', (int) $rowAu['fail_count']) . ')' : '' ?>: <?= e($rowAu['last_message'] ?? '') ?>">(<?= e($rowAu['branch'] ?? '?') ?>, <?= te('Update-Fehler') ?>)</span>
                        <?php elseif (auto_update_failure_level($rowAu) === 'hint'): ?>
                            <?php // Einzelner Aussetzer - erst ab zwei in Folge rot. ?>
                            <span title="<?= te('Letzter Update-Lauf fehlgeschlagen (einmalig, nächster Lauf wiederholt)') ?>: <?= e($rowAu['last_message'] ?? '') ?>">(<?= e($rowAu['branch'] ?? '?') ?>, <?= te('Update-Hinweis') ?>)</span>
                        <?php else: ?>
                            <span title="<?= te('Auto-Update aus Branch %s', $rowAu['branch'] ?? '?') ?>">(<?= e($rowAu['branch'] ?? '?') ?>)</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['network_count'] !== null): ?>
                        <?= e($row['network_count']) ?>
                        <?php $scanAt = $row['last_scan']['client_timestamp'] ?? $row['last_scan']['received_at'] ?? null; ?>
                        <?php if (!empty($scanAt)): ?>
                            <?php $scanToday = format_local($scanAt, 'Y-m-d') === format_local(utc_now(), 'Y-m-d'); ?>
                            <span class="muted">(<?= e(format_local($scanAt, $scanToday ? 'H:i' : 'd.m. H:i')) ?>)</span>
                        <?php endif; ?>
                    <?php else: ?>–<?php endif; ?>
                </td>
                <?php
                $ts = $row['test_summary'];
                $fmtSec = fn(?float $v): string => $v !== null ? e(number_format($v, 1)) . 's' : '–';
                ?>
                <?php // Alle Ziel-SSIDs verbunden = gruen; eine fehlende ist ein echtes Problem = rot. ?>
                <td class="<?= $ts['ssid_total'] > 0 ? ($ts['ssid_ok'] < $ts['ssid_total'] ? 'fail' : 'ok') : '' ?>">
                    <?= $ts['ssid_total'] > 0 ? e($ts['ssid_ok']) . ' / ' . e($ts['ssid_total']) : '–' ?>
                </td>
                <?php
                // Abgerundet: ein einzelner Fehlschlag soll nie als 100 % erscheinen.
                // 100 % gruen, ab 50 % orange, darunter rot.
                $ratePct = $ts['test_count'] > 0 ? (int) floor(100 * $ts['test_ok'] / $ts['test_count']) : null;
                $rateClass = $ratePct === null ? '' : ($ratePct >= 100 ? 'ok' : ($ratePct >= 50 ? 'warn' : 'fail'));
                ?>
                <td class="<?= $rateClass ?>"
                    <?php if ($ts['test_count'] > 0): ?>title="<?= te('%d von %d Tests erfolgreich', $ts['test_ok'], $ts['test_count']) ?>"<?php endif; ?>>
                    <?= $ratePct !== null ? e($ratePct) . ' %' : '–' ?>
                </td>
                <?php
                // Orange, wenn der Schnitt ueber der Schwelle des Standorts liegt
                // (Alarmierungsseite; leer = neutral). Bei 802.1X ist das zugleich
                // die Schwelle der Alarmregel "802.1X langsam".
                $slowCell = function (string $kind, ?float $value) use ($row, $fmtSec): string {
                    $limit = $row['slow_seconds'][$kind] ?? null;
                    if ($limit === null || $value === null || $value <= $limit) {
                        return $fmtSec($value);
                    }
                    return '<span class="warn" title="' . te('Langsamer als die Schwelle des Standorts (%s s, Einstellung in der Alarmierung des Standorts)', number_format($limit, 1)) . '">'
                        . $fmtSec($value) . '</span>';
                };
                ?>
                <td style="white-space:nowrap">
                    <?= $slowCell('assoc', $ts['avg_assoc']) ?> / <?= $slowCell('auth', $ts['avg_auth']) ?> / <?= $slowCell('dhcp', $ts['avg_dhcp']) ?>
                </td>
                <td>
                    <?php if ($row['last_test']):
                        $td = json_decode((string) $row['last_test']['data'], true) ?: []; ?>
                        <span class="<?= !empty($td['connected']) ? 'ok' : 'fail' ?>">
                            <?= e($td['ssid'] ?? '') ?> –
                            <?= !empty($td['connected']) ? te('verbunden') : te('fehlgeschlagen') ?>
                        </span>
                        <?php if (!empty($td['ping_rtt_avg_ms'])): ?>
                            <span class="muted">(<?= e(number_format((float) $td['ping_rtt_avg_ms'], 1)) ?> ms)</span>
                        <?php elseif (!empty($td['connected']) && !empty($td['ping_sent']) && empty($td['ping_received'])
                            && ($td['ping_target_source'] ?? '') !== 'portal_gateway'): ?>
                            <span class="fail">(Ping 0/<?= e($td['ping_sent']) ?>)</span>
                        <?php elseif (isset($td['captive_portal']['response_ms'])): ?>
                            <?php // Kein Ping-Ergebnis (z.B. Portal blockt ICMP): HTTP-Antwortzeit als Latenz. ?>
                            <span class="muted" title="<?= te('HTTP-Antwortzeit der Portal-Prüfung (Ping ohne Antwort)') ?>">(HTTP <?= e(round((float) $td['captive_portal']['response_ms'])) ?> ms)</span>
                        <?php endif; ?>
                        <?php if (($td['captive_portal']['detected'] ?? null) === true): ?>
                            <?php // Portal ohne eingerichteten Login ist erwartbar (Gastnetz) - neutral, nur ein fehlgeschlagener Login ist rot. ?>
                            <?php if (!empty($td['captive_portal']['login']['ok'])): ?>
                                <span class="ok">· <?= te('Portal, Login ok') ?></span>
                            <?php elseif (!empty($td['captive_portal']['login'])): ?>
                                <span class="fail">· <?= te('Portal erkannt') ?>, <?= te('Login fehlgeschlagen') ?></span>
                            <?php else: ?>
                                <span class="muted" title="<?= te('Captive Portal erkannt, kein automatischer Login eingerichtet') ?>">· <?= te('Portal erkannt') ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($td['iperf3_mbps']) || !empty($td['iperf3_download_mbps'])): ?>
                            <span class="muted">· ↑ <?= isset($td['iperf3_mbps']) ? e(round((float) $td['iperf3_mbps'])) : '–' ?>
                            / ↓ <?= isset($td['iperf3_download_mbps']) ? e(round((float) $td['iperf3_download_mbps'])) : '–' ?> Mbit/s</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted"><?= te('noch keiner') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['last_lan']):
                        $ld = json_decode((string) $row['last_lan']['data'], true) ?: []; ?>
                        <?php if (isset($ld['upload_mbps']) || isset($ld['download_mbps'])): ?>
                            <span class="nowrap"><?= isset($ld['upload_mbps']) ? e(round((float) $ld['upload_mbps'])) : '–' ?>
                            / <?= isset($ld['download_mbps']) ? e(round((float) $ld['download_mbps'])) : '–' ?></span>
                            <span class="muted">Mbit/s</span>
                        <?php else: ?>
                            <span class="fail" title="<?= e(probe_error_text($ld)) ?>"><?= te('fehlgeschlagen') ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">–</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="empty" id="deviceFilterEmpty" hidden><?= te('Keine Geräte passen zum Filter.') ?></p>

    <script>
    (function () {
        var table = document.getElementById('deviceTable');
        var tbody = table.querySelector('tbody');
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var headers = Array.prototype.slice.call(table.querySelectorAll('th[data-sort]'));
        var text = document.getElementById('deviceFilterText');
        var site = document.getElementById('deviceFilterSite');   // fehlt bei nur einem Standort
        var status = document.getElementById('deviceFilterStatus');
        var problems = document.getElementById('deviceFilterProblems');
        var count = document.getElementById('deviceFilterCount');
        var empty = document.getElementById('deviceFilterEmpty');
        var countText = <?= tjson('%d von %d Geräten') ?>;
        var refreshSelect = document.getElementById('deviceRefreshInterval');
        var refreshInfo = document.getElementById('deviceRefreshInfo');
        // Filter/Sortierung pro Browser merken - reine Bequemlichkeit, die
        // Seite funktioniert genauso ohne (privates Fenster, Storage gesperrt).
        var storageKey = 'wlanmon-dashboard-view';

        function save(state) {
            try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (e) { /* egal */ }
        }
        function load() {
            try { return JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (e) { return {}; }
        }
        function currentSort() {
            return headers.filter(function (h) { return h.dataset.dir; })[0];
        }
        function currentState() {
            var sorted = currentSort();
            return {
                text: text.value, site: site ? site.value : '', status: status.value,
                problems: problems.checked, refreshSeconds: parseInt(refreshSelect.value, 10) || 0,
                sort: sorted ? sorted.dataset.sort : '', dir: sorted ? sorted.dataset.dir : ''
            };
        }

        function applyFilter() {
            var q = text.value.trim().toLowerCase();
            var s = site ? site.value : '';
            var st = status.value;
            var visible = 0;
            rows.forEach(function (row) {
                var show = (q === '' || row.dataset.search.indexOf(q) !== -1 || row.dataset.site.toLowerCase().indexOf(q) !== -1)
                    && (s === '' || row.dataset.site === s)
                    && (st === '' || (st === 'online') === (row.dataset.online === '1'))
                    && (!problems.checked || row.dataset.problem === '1');
                row.hidden = !show;
                if (show) { visible++; }
            });
            count.textContent = countText.replace('%d', visible).replace('%d', rows.length);
            empty.hidden = visible > 0;
            table.parentNode.hidden = visible === 0;
            save(currentState());
        }

        function compare(a, b, key, type) {
            var va = a.dataset[key] || '', vb = b.dataset[key] || '';
            // Leere Werte immer ans Ende, unabhaengig von der Richtung.
            if (va === '' || vb === '') { return va === vb ? 0 : (va === '' ? 1 : -1); }
            if (type === 'num') { return parseFloat(va) - parseFloat(vb); }
            // Versionen wie 1.0.1.9 < 1.0.1.10: localeCompare mit numeric.
            return va.localeCompare(vb, undefined, { numeric: type === 'version', sensitivity: 'base' });
        }

        function sortBy(th, dir) {
            var key = th.dataset.sort, type = th.dataset.type || 'text';
            headers.forEach(function (h) { delete h.dataset.dir; });
            th.dataset.dir = dir;
            rows.sort(function (a, b) {
                var va = a.dataset[key] || '', vb = b.dataset[key] || '';
                if (va === '' || vb === '') { return compare(a, b, key, type); }
                var c = compare(a, b, key, type);
                return dir === 'asc' ? c : -c;
            });
            rows.forEach(function (r) { tbody.appendChild(r); });
        }

        headers.forEach(function (th) {
            th.title = (th.title ? th.title + ' – ' : '') + <?= tjson('Klicken zum Sortieren') ?>;
            th.addEventListener('click', function () {
                // Zahlen/Zeiten zuerst absteigend (neueste, hoechste oben), Text aufsteigend.
                var first = (th.dataset.type === 'num' || th.dataset.type === 'version') ? 'desc' : 'asc';
                sortBy(th, th.dataset.dir ? (th.dataset.dir === 'asc' ? 'desc' : 'asc') : first);
                save(currentState());
            });
        });

        [text, site, status, problems].forEach(function (el) {
            if (!el) { return; }
            el.addEventListener('input', applyFilter);
            el.addEventListener('change', applyFilter);
        });
        document.getElementById('deviceFilterReset').addEventListener('click', function () {
            text.value = ''; status.value = ''; problems.checked = false;
            if (site) { site.value = ''; }
            applyFilter();
        });

        var saved = load();
        text.value = saved.text || '';
        status.value = saved.status || '';
        problems.checked = !!saved.problems;
        if (site) {
            site.value = saved.site || '';
            if (site.value !== (saved.site || '')) { site.value = ''; }  // Standort gibt es nicht mehr
        }
        var savedTh = headers.filter(function (h) { return h.dataset.sort === saved.sort; })[0];
        if (savedTh && (saved.dir === 'asc' || saved.dir === 'desc')) { sortBy(savedTh, saved.dir); }

        // --- Automatische Aktualisierung --------------------------------
        // Holt die Seite im Hintergrund neu und tauscht nur die Tabellen-
        // zeilen: kein Flackern, Scrollposition und ein halb getipptes
        // Suchwort bleiben, Filter und Sortierung gelten sofort wieder.
        // Ein Sekundentakt treibt Countdown und Abruf; im Hintergrund-Tab
        // wird nicht abgerufen, beim Zurueckkommen sofort, falls faellig.
        var lastRefresh = Date.now();   // letzter erfolgreicher Stand
        var lastAttempt = Date.now();   // letzter Versuch (auch fehlgeschlagen)
        var refreshing = false;
        var stopped = false;            // Sitzung abgelaufen
        var failed = false;
        var timeFormat = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
        var standText = <?= tjson('Stand %s') ?>;
        var inText = <?= tjson('Aktualisierung in %s') ?>;

        function intervalMs() { return (parseInt(refreshSelect.value, 10) || 0) * 1000; }
        function clock(ts) { return new Date(ts).toLocaleTimeString(document.documentElement.lang || undefined, timeFormat); }
        function duration(sec) {
            return sec >= 60 ? Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0') + ' min' : sec + ' s';
        }
        function render() {
            if (stopped) { return; }   // Hinweis "Sitzung abgelaufen" stehen lassen
            var stand = standText.replace('%s', clock(lastRefresh));
            refreshInfo.title = stand;
            var ms = intervalMs();
            if (!ms) {
                refreshInfo.textContent = stand;
            } else if (refreshing) {
                refreshInfo.textContent = <?= tjson('Aktualisiere …') ?>;
            } else {
                var left = Math.max(0, Math.ceil((lastAttempt + ms - Date.now()) / 1000));
                refreshInfo.textContent = (failed ? <?= tjson('Aktualisierung fehlgeschlagen') ?> + ' · ' : '')
                    + inText.replace('%s', duration(left));
            }
        }
        function siteOptions(select) {
            return select ? Array.prototype.map.call(select.options, function (o) { return o.value; }).join('\n') : '';
        }
        function refresh() {
            if (refreshing || stopped) { return; }
            refreshing = true;
            lastAttempt = Date.now();
            render();
            fetch(location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) {
                    // Abgelaufene Sitzung: require_login() leitet auf /login um.
                    if (r.redirected && new URL(r.url).pathname === '/login') { throw new Error('login'); }
                    if (!r.ok) { throw new Error('http'); }
                    return r.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var newBody = doc.querySelector('#deviceTable tbody');
                    // Kein Geraet mehr oder andere Standorte (Filterliste veraltet):
                    // dann lieber die ganze Seite - Filter/Sortierung sind gemerkt.
                    if (!newBody || siteOptions(doc.getElementById('deviceFilterSite')) !== siteOptions(site)) {
                        location.reload();
                        return;
                    }
                    tbody.innerHTML = newBody.innerHTML;
                    rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    var sorted = currentSort();
                    if (sorted) { sortBy(sorted, sorted.dataset.dir); }
                    applyFilter();
                    lastRefresh = Date.now();
                    failed = false;
                })
                .catch(function (err) {
                    if (err.message === 'login') {
                        stopped = true;
                        refreshInfo.textContent = <?= tjson('Sitzung abgelaufen – Seite neu laden') ?>;
                    } else {
                        failed = true;   // naechster Versuch nach einem Intervall
                    }
                })
                .then(function () { refreshing = false; render(); });
        }
        function tick() {
            var ms = intervalMs();
            if (ms && !document.hidden && !refreshing && !stopped && Date.now() - lastAttempt >= ms) {
                refresh();
            }
            render();
        }
        document.addEventListener('visibilitychange', tick);
        refreshSelect.addEventListener('change', function () {
            lastAttempt = Date.now();   // Countdown mit dem neuen Intervall neu beginnen
            save(currentState());
            render();
        });

        // Gemerktes Intervall; aeltere Speicherstaende kannten nur an/aus.
        var savedSeconds = [0, 5, 10, 15, 30, 60, 300].indexOf(saved.refreshSeconds) !== -1
            ? saved.refreshSeconds : (saved.autoRefresh === false ? 0 : 60);
        refreshSelect.value = String(savedSeconds);
        setInterval(tick, 1000);
        render();
        applyFilter();
    })();
    </script>
<?php endif; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
