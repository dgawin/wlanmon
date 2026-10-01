<?php
/** @var array $sites */
/** @var bool $siteCreated */
/** @var bool $siteDeleted */
/** @var string|null $error */

$isAdmin = (current_user()['role'] ?? null) === 'admin';
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Standorte') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-map-location-dot"></i> <?= te('Standorte') ?></h1>
<p class="muted">
    <?php if ($isAdmin): ?>
        <?= teh('Kuratierte Liste bekannter Standorte - Geräte werden ihnen unter %s zugeordnet. Grundlage für die Zugriffsbeschränkung von Benutzern (siehe %s) und für die Alarmierungs-Einstellungen je Standort.', '<em>' . te('Gerät → Konfiguration') . '</em>', '<a href="/users">' . te('Benutzer') . '</a>') ?>
    <?php else: ?>
        <?= te('Deine zugewiesenen Standorte. Alarmierungs-Einstellungen kannst du hier selbst anpassen.') ?>
    <?php endif; ?>
</p>

<?php if ($siteCreated): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Standort angelegt.') ?></p>
<?php endif; ?>
<?php if ($siteDeleted): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Standort gelöscht.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>

<?php if (empty($sites)): ?>
    <p class="empty"><?= $isAdmin ? te('Noch keine Standorte angelegt.') : te('Dir ist noch kein Standort zugewiesen - wende dich an einen Admin.') ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr><th><?= te('Name') ?></th><th><?= te('Bemerkung') ?></th><?php if ($isAdmin): ?><th><?= te('Geräte') ?></th><?php endif; ?><th></th></tr></thead>
        <tbody>
        <?php foreach ($sites as $s): ?>
            <tr>
                <td>
                    <?php if ($isAdmin): ?>
                        <form method="post" action="/sites/<?= (int) $s['id'] ?>/rename" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="text" name="name" value="<?= e($s['name']) ?>" required style="width:auto">
                            <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-floppy-disk"></i></button>
                        </form>
                    <?php else: ?>
                        <?= e($s['name']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/sites/<?= (int) $s['id'] ?>/notes" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="text" name="notes" value="<?= e($s['notes'] ?? '') ?>" style="width:auto" placeholder="–">
                        <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-floppy-disk"></i></button>
                    </form>
                </td>
                <?php if ($isAdmin): ?>
                <td><?= (int) site_device_count((int) $s['id']) ?></td>
                <?php endif; ?>
                <td>
                    <a href="/sites/<?= (int) $s['id'] ?>/alerting" class="btn-secondary btn-small">
                        <i class="fa-solid fa-bell"></i> <?= te('Alarmierung') ?>
                    </a>
                    <?php if ($isAdmin): ?>
                    <form method="post" action="/sites/<?= (int) $s['id'] ?>/delete" class="inline-form"
                          onsubmit="return confirm(<?= tjs('Standort „%s“ wirklich löschen? Zugeordnete Geräte verlieren nur die Zuordnung, bleiben aber bestehen.', $s['name']) ?>);">
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
<?php endif; ?>

<?php if ($isAdmin): ?>
<h2><span><i class="fa-solid fa-plus"></i> <?= te('Neuer Standort') ?></span></h2>
<form method="post" action="/sites/new">
    <?= csrf_field() ?>
    <label>
        <?= te('Name') ?>
        <input type="text" name="name" required placeholder="standort-01">
    </label>
    <button type="submit"><i class="fa-solid fa-plus"></i> <?= te('Anlegen') ?></button>
</form>
<?php endif; ?>
</main>
</body>
</html>
