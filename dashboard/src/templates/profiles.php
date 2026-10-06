<?php
/** @var array $profiles siehe profile_list() */
/** @var bool $profileDeleted */

$user = current_user();
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Profile') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-layer-group"></i> <?= te('Profile') ?></h1>
<p class="muted">
    <?= teh('Ein Profil bündelt %s und Testeinstellungen (Intervalle, Ping, iperf3, LAN-Test). Geräte bekommen in ihrer Konfiguration ein Profil zugewiesen, statt alles einzeln zu pflegen.', '<a href="/ssids">' . te('SSIDs') . '</a>') ?>
</p>

<?php if ($profileDeleted): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Profil gelöscht.') ?></p>
<?php endif; ?>

<p><a href="/profiles/new" class="btn"><i class="fa-solid fa-plus"></i> <?= te('Neues Profil') ?></a></p>

<?php if ($profiles === []): ?>
    <p class="empty"><?= te('Noch keine Profile. Tipp: In der Konfiguration eines Geräts macht „Als Profil übernehmen“ aus dessen Einstellungen ein Profil.') ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr>
            <th><?= te('Name') ?></th><th><?= te('Standort') ?></th><th><?= te('SSIDs') ?></th><th><?= te('Geräte') ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($profiles as $p):
            $editable = scope_editable($user, scope_site_id($p['site_id'])); ?>
            <tr>
                <td><a href="/profiles/<?= (int) $p['id'] ?>"><strong><?= e($p['name']) ?></strong></a></td>
                <td><?= $p['site_name'] !== null ? e($p['site_name']) : '<span class="muted">' . te('global') . '</span>' ?></td>
                <td><?= (int) $p['ssid_count'] ?></td>
                <td><?= (int) $p['device_count'] ?></td>
                <td class="nowrap"><a href="/profiles/<?= (int) $p['id'] ?>" class="btn-secondary btn-small">
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
