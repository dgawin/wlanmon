<?php
/** @var array $ssids siehe ssid_list() */
/** @var bool $ssidSaved */
/** @var bool $ssidDeleted */

$user = current_user();
$securityLabels = ['open' => __('offen'), 'wpa2-psk' => 'WPA2-PSK', 'wpa3-psk' => 'WPA3-SAE', 'wpa2-wpa3-psk' => 'WPA2/WPA3', 'wpa2-eap' => '802.1X'];
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('SSIDs') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-wifi"></i> <?= te('SSIDs') ?></h1>
<p class="muted">
    <?= teh('Jede SSID einmal mit allem, was der Test braucht: Sicherheit, Passwort bzw. 802.1X, Captive Portal, iperf3. %s bündeln SSIDs und Testeinstellungen für mehrere Geräte - ändert sich ein Passwort, reicht es, die SSID hier anzupassen.', '<a href="/profiles">' . te('Profile') . '</a>') ?>
</p>

<?php if ($ssidDeleted): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('SSID gelöscht.') ?></p>
<?php endif; ?>

<p><a href="/ssids/new" class="btn"><i class="fa-solid fa-plus"></i> <?= te('Neue SSID') ?></a></p>

<?php if ($ssids === []): ?>
    <p class="empty"><?= te('Noch keine SSIDs angelegt. Tipp: In der Konfiguration eines Geräts macht „Als Profil übernehmen“ aus dessen SSIDs Einträge hier.') ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr>
            <th>SSID</th><th><?= te('Sicherheit') ?></th><th><?= te('Standort') ?></th>
            <th><?= te('Profile') ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($ssids as $s):
            $t = json_decode((string) $s['target'], true) ?: [];
            $sec = (string) ($t['security'] ?? '');
            $editable = scope_editable($user, scope_site_id($s['site_id'])); ?>
            <tr>
                <td><a href="/ssids/<?= (int) $s['id'] ?>"><strong><?= e($t['ssid'] ?? '?') ?></strong></a> <span class="muted">#<?= (int) $s['id'] ?></span>
                    <?php if (($s['label'] ?? '') !== ''): ?><span class="muted"><?= e($s['label']) ?></span><?php endif; ?></td>
                <td><?= e($securityLabels[$sec] ?? $sec) ?><?php if ($sec === 'wpa2-eap' && !empty($t['eap']['method'])): ?> <span class="muted"><?= e(strtoupper((string) $t['eap']['method'])) ?></span><?php endif; ?>
                    <?php if (!empty($t['captive_portal_check'])): ?><span class="muted">· <?= te('Portal') ?></span><?php endif; ?></td>
                <td><?= $s['site_name'] !== null ? e($s['site_name']) : '<span class="muted">' . te('global') . '</span>' ?></td>
                <td><?= (int) $s['profile_count'] ?></td>
                <td class="nowrap"><a href="/ssids/<?= (int) $s['id'] ?>" class="btn-secondary btn-small">
                    <i class="fa-solid <?= $editable ? 'fa-pen' : 'fa-eye' ?>"></i> <?= $editable ? te('Bearbeiten') : te('Ansehen') ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
