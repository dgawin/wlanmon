<?php
/** @var array $status Aus cirrus_sync_status() - 'configured', 'ap_count', 'last_sync_at'. */
/** @var array $aps Aus cirrus_ap_overview() - je Eintrag 'mac_address', 'ap_name', 'site_name', 'radios'. */

/**
 * Kanalauslastung farblich einordnen - dieselben Schwellwerte wie
 * uebliche WLAN-Planungsfaustregeln (< 50 % unauffaellig, 50-80 %
 * spuerbar, > 80 % stark ausgelastet).
 *
 * @return array{0: string, 1: string} [Pill-CSS-Klasse, Icon-Klasse]
 */
$utilizationPill = function (?float $pct): array {
    if ($pct === null) {
        return ['pill-muted', 'fa-circle-question'];
    }
    if ($pct >= 80) {
        return ['pill-fail', 'fa-triangle-exclamation'];
    }
    if ($pct >= 50) {
        return ['pill-warn', 'fa-triangle-exclamation'];
    }
    return ['pill-ok', 'fa-circle-check'];
};
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Access Points') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-wifi"></i> <?= te('Access Points') ?></h1>
<p class="muted">
    <?= teh('Funkzustand der über %s bekannten Access Points (Kanal, Kanalauslastung, Rauschpegel, Sendeleistung) - hilft einzuordnen, ob ein fehlgeschlagener oder langsamer Connection-Test an der Funklage am jeweiligen AP liegen könnte.', '<a href="/about">OmniVista Cirrus</a>') ?>
</p>

<?php if (!$status['configured']): ?>
    <p class="muted">
        <i class="fa-solid fa-cloud"></i> <?= te('OmniVista Cirrus ist nicht konfiguriert (siehe README, Abschnitt „OmniVista Cirrus“).') ?>
    </p>
<?php elseif (empty($aps)): ?>
    <p class="empty">
        <?= teh('Noch keine Access Points bekannt - wurde %s schon mindestens einmal erfolgreich ausgeführt?', '<code>sync_cirrus_aps.php</code>') ?>
    </p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th><?= te('Access Point') ?></th><th><?= te('Standort') ?></th><th><?= te('Band') ?></th><th><?= te('Kanal') ?></th>
                <th><?= te('Auslastung') ?></th><th><?= te('Rauschen') ?></th><th><?= te('Sendeleistung') ?></th><th><?= te('Gemessen') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($aps as $ap): ?>
            <?php foreach ($ap['radios'] as $i => $r): ?>
                <?php [$utilClass, $utilIcon] = $utilizationPill($r['channel_utilization'] !== null ? (float) $r['channel_utilization'] : null); ?>
                <tr>
                    <?php if ($i === 0): ?>
                        <td rowspan="<?= count($ap['radios']) ?>"><?= e($ap['ap_name'] ?? $ap['mac_address']) ?></td>
                        <td rowspan="<?= count($ap['radios']) ?>"><?= e($ap['site_name'] ?? '–') ?></td>
                    <?php endif; ?>
                    <td><?= e($r['band'] ?? '–') ?></td>
                    <td><?= $r['channel'] !== null ? e($r['channel']) : '–' ?></td>
                    <td>
                        <?php if ($r['channel_utilization'] !== null): ?>
                            <span class="pill <?= $utilClass ?>">
                                <i class="fa-solid <?= $utilIcon ?>"></i>
                                <?= e(number_format((float) $r['channel_utilization'], 0)) ?>%
                            </span>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td><?= $r['noise_floor_dbm'] !== null ? e($r['noise_floor_dbm']) . ' dBm' : '–' ?></td>
                    <td><?= $r['tx_power'] !== null ? e(number_format((float) $r['tx_power'], 0)) . ' dBm' : '–' ?></td>
                    <td class="muted"><?= !empty($r['measured_at']) ? e(format_local($r['measured_at'], 'd.m. H:i')) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted">
        <?= te('%d AP(s) bekannt', count($aps)) ?> · <?= te('letzter Sync:') ?>
        <?= $status['last_sync_at'] !== null ? e(format_local($status['last_sync_at'], 'd.m.Y H:i')) : te('noch nie') ?>
    </p>
<?php endif; ?>
</main>
</body>
</html>
