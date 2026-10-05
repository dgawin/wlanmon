<?php
/** @var array $user */
/** @var array $sites */
/** @var int[] $assignedSiteIds */

$roleLabels = ['admin' => __('Admin (voller Zugriff)'), 'user' => __('User (Geräte in zugewiesenen Standorten bearbeiten)'), 'viewer' => __('Viewer (Geräte in zugewiesenen Standorten nur ansehen)')];
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Benutzer bearbeiten') ?> – <?= e($user['username']) ?> – WLANMON</title>
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
<p><a href="/users" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Benutzer') ?></a></p>
<h1><i class="fa-solid fa-user-pen"></i> <?= e($user['username']) ?></h1>
<p class="muted"><?= e($user['email']) ?></p>

<form method="post" action="/users/<?= (int) $user['id'] ?>/edit" id="userEditForm">
    <?= csrf_field() ?>
    <label>
        <?= te('Rolle') ?>
        <select name="role" id="roleSelect">
            <?php foreach ($roleLabels as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div id="sitesField">
        <span class="target-section-title"><?= te('Standorte') ?></span>
        <?php if (empty($sites)): ?>
            <p class="muted"><?= te('Noch keine Standorte angelegt - siehe') ?> <a href="/sites"><?= te('Standorte') ?></a>.</p>
        <?php else: ?>
            <?php foreach ($sites as $s): ?>
                <label class="check-label">
                    <input type="checkbox" name="sites[]" value="<?= (int) $s['id'] ?>"
                           <?= in_array($s['id'], $assignedSiteIds, true) ? 'checked' : '' ?>>
                    <?= e($s['name']) ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Speichern') ?></button>
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
