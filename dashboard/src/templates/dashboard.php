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
    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th><?= te('Status') ?></th>
                <th><?= te('Standort') ?></th>
                <th><?= te('Gerät') ?></th>
                <th><?= te('Zuletzt gesehen') ?></th>
                <th><?= te('Version') ?></th>
                <th><?= te('Netze im letzten Scan') ?></th>
                <th><?= te('Letzter Connection-Test') ?></th>
                <th><?= te('LAN iperf3 (↑ / ↓)') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): $d = $row['device']; ?>
            <tr>
                <td>
                    <span class="pill <?= $row['online'] ? 'pill-ok' : 'pill-fail' ?>">
                        <span class="status-dot <?= $row['online'] ? 'status-online' : 'status-offline' ?>" style="margin-right:0"></span>
                        <?= $row['online'] ? te('Online') : te('Offline') ?>
                    </span>
                </td>
                <td><?= e($d['site_name'] ?? '–') ?></td>
                <td>
                    <a href="/devices/<?= e($d['id']) ?>"><?= e($d['id']) ?></a>
                    <?php
                    $rowScanData = $row['last_scan'] ? (json_decode((string) $row['last_scan']['data'], true) ?: []) : [];
                    $rowIps = is_array($rowScanData['local_ips'] ?? null) ? $rowScanData['local_ips'] : [];
                    foreach ($rowIps as $ifName => $ip): ?>
                        <br><span class="muted"><?= e($ip) ?> (<?= e($ifName) ?>)</span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if (!empty($d['last_seen_at'])): ?>
                        <?= e(format_local($d['last_seen_at'], 'd.m.Y H:i')) ?>
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
                <td>
                    <?php if ($row['last_test']):
                        $td = json_decode((string) $row['last_test']['data'], true) ?: []; ?>
                        <span class="<?= !empty($td['connected']) ? 'ok' : 'fail' ?>">
                            <?= e($td['ssid'] ?? '') ?> –
                            <?= !empty($td['connected']) ? te('verbunden') : te('fehlgeschlagen') ?>
                        </span>
                        <?php if (!empty($td['ping_rtt_avg_ms'])): ?>
                            <span class="muted">(<?= e($td['ping_rtt_avg_ms']) ?> ms)</span>
                        <?php elseif (!empty($td['connected']) && !empty($td['ping_sent']) && empty($td['ping_received'])
                            && ($td['ping_target_source'] ?? '') !== 'portal_gateway'): ?>
                            <span class="fail">(Ping 0/<?= e($td['ping_sent']) ?>)</span>
                        <?php elseif (isset($td['captive_portal']['response_ms'])): ?>
                            <?php // Kein Ping-Ergebnis (z.B. Portal blockt ICMP): HTTP-Antwortzeit als Latenz. ?>
                            <span class="muted" title="<?= te('HTTP-Antwortzeit der Portal-Prüfung (Ping ohne Antwort)') ?>">(HTTP <?= e(round((float) $td['captive_portal']['response_ms'])) ?> ms)</span>
                        <?php endif; ?>
                        <?php if (($td['captive_portal']['detected'] ?? null) === true): ?>
                            <?php if (!empty($td['captive_portal']['login']['ok'])): ?>
                                <span class="ok">· <?= te('Portal, Login ok') ?></span>
                            <?php else: ?>
                                <span class="fail">· <?= te('Portal erkannt') ?><?= !empty($td['captive_portal']['login']) ? ', ' . te('Login fehlgeschlagen') : '' ?></span>
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
                            <?= isset($ld['upload_mbps']) ? e(round((float) $ld['upload_mbps'])) : '–' ?>
                            / <?= isset($ld['download_mbps']) ? e(round((float) $ld['download_mbps'])) : '–' ?>
                            <span class="muted">Mbit/s</span>
                        <?php else: ?>
                            <span class="fail" title="<?= e($ld['error'] ?? '') ?>"><?= te('fehlgeschlagen') ?></span>
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
<?php endif; ?>
</main>
</body>
</html>
