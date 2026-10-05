<?php
/** @var array $users */
/** @var array $sites */
/** @var bool $userSaved */
/** @var bool $userDeleted */
/** @var bool $userDisabled */
/** @var bool $userEnabled */
/** @var bool $disableSupported */
/** @var array|null $me */
/** @var string|null $error */

$roleLabels = ['admin' => 'Admin', 'user' => 'User', 'viewer' => 'Viewer'];
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Benutzer') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-users"></i> <?= te('Benutzer') ?></h1>

<?php if ($userSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Gespeichert.') ?></p>
<?php endif; ?>
<?php if ($userDeleted): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Benutzer gelöscht.') ?></p>
<?php endif; ?>
<?php if ($userDisabled): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Benutzer deaktiviert.') ?></p>
<?php endif; ?>
<?php if ($userEnabled): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Benutzer reaktiviert.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>

<?php if (empty($users)): ?>
    <p class="empty"><?= te('Noch keine Benutzer angelegt.') ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table>
        <thead><tr><th><?= te('Benutzername') ?></th><th><?= te('E-Mail') ?></th><th><?= te('Rolle') ?></th><th><?= te('Standorte') ?></th><th><?= te('Status') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><span class="pill pill-muted"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span></td>
                <td>
                    <?php if ($u['role'] === 'admin'): ?>
                        <span class="muted"><?= te('alle') ?></span>
                    <?php else: ?>
                        <?php $userSites = user_sites_for((int) $u['id']); ?>
                        <?= $userSites === [] ? '<span class="muted">' . te('keine') . '</span>' : e(implode(', ', array_column($userSites, 'name'))) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($u['disabled']): ?>
                        <span class="pill pill-muted"><i class="fa-solid fa-ban"></i> <?= te('deaktiviert') ?></span>
                    <?php elseif ($u['active']): ?>
                        <span class="pill pill-ok"><i class="fa-solid fa-circle-check"></i> <?= te('aktiv') ?></span>
                    <?php else: ?>
                        <span class="pill pill-warn"><i class="fa-solid fa-clock"></i> <?= te('eingeladen') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/users/<?= (int) $u['id'] ?>/edit" class="btn-secondary btn-small">
                        <i class="fa-solid fa-pen"></i> <?= te('Bearbeiten') ?>
                    </a>
                    <?php // Das eigene Konto nicht deaktivierbar, siehe handle_set_user_disabled(). ?>
                    <?php if ($disableSupported && (int) $u['id'] !== (int) ($me['id'] ?? 0)): ?>
                        <?php if ($u['disabled']): ?>
                            <form method="post" action="/users/<?= (int) $u['id'] ?>/enable" class="inline-form">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-user-check"></i> <?= te('Reaktivieren') ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/users/<?= (int) $u['id'] ?>/disable" class="inline-form"
                                  onsubmit="return confirm(<?= tjs('Benutzer „%s“ deaktivieren? Er wird sofort abgemeldet und kann sich nicht mehr anmelden, bis er reaktiviert wird.', $u['username']) ?>);">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-user-slash"></i> <?= te('Deaktivieren') ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$u['active'] && !$u['disabled']): ?>
                        <form method="post" action="/users/<?= (int) $u['id'] ?>/resend-invite" class="inline-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-secondary btn-small"><i class="fa-solid fa-paper-plane"></i> <?= te('Erneut einladen') ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/users/<?= (int) $u['id'] ?>/delete" class="inline-form"
                          onsubmit="return confirm(<?= tjs('Benutzer „%s“ wirklich löschen?', $u['username']) ?>);">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-remove btn-small"><i class="fa-solid fa-trash-can"></i> <?= te('Löschen') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<h2><span><i class="fa-solid fa-user-plus"></i> <?= te('Benutzer einladen') ?></span></h2>
<p class="muted"><?= teh('Legt den Account an und verschickt eine Einladungs-Mail zum Setzen des Passworts (nutzt die SMTP-Zugangsdaten aus %s).', '<a href="/settings/alerting">' . te('Alarmierung') . '</a>') ?></p>
<form method="post" action="/users/new" id="userForm">
    <?= csrf_field() ?>
    <label>
        <?= te('Benutzername') ?>
        <input type="text" name="username" required autocomplete="off">
    </label>
    <label>
        <?= te('E-Mail') ?>
        <input type="email" name="email" required autocomplete="off">
    </label>
    <label>
        <?= te('Rolle') ?>
        <select name="role" id="roleSelect">
            <option value="admin"><?= te('Admin (voller Zugriff)') ?></option>
            <option value="user" selected><?= te('User (Geräte in zugewiesenen Standorten bearbeiten)') ?></option>
            <option value="viewer"><?= te('Viewer (Geräte in zugewiesenen Standorten nur ansehen)') ?></option>
        </select>
    </label>
    <div id="sitesField">
        <span class="target-section-title"><?= te('Standorte') ?></span>
        <?php if (empty($sites)): ?>
            <p class="muted"><?= te('Noch keine Standorte angelegt - siehe') ?> <a href="/sites"><?= te('Standorte') ?></a>.</p>
        <?php else: ?>
            <?php foreach ($sites as $s): ?>
                <label class="check-label">
                    <input type="checkbox" name="sites[]" value="<?= (int) $s['id'] ?>">
                    <?= e($s['name']) ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <button type="submit"><i class="fa-solid fa-paper-plane"></i> <?= te('Einladen') ?></button>
</form>

<script>
(function () {
    var roleSelect = document.getElementById('roleSelect');
    var sitesField = document.getElementById('sitesField');
    function refresh() {
        sitesField.style.display = roleSelect.value === 'admin' ? 'none' : '';
    }
    roleSelect.addEventListener('change', refresh);
    refresh();
})();
</script>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
