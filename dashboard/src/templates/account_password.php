<?php
/** @var string|null $error */
/** @var bool $passwordChanged */
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Passwort ändern') ?> – WLANMON</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/static/style.css">
    <link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
    <script src="/static/theme.js"></script>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<main class="narrow-main">
<h1><i class="fa-solid fa-key"></i> <?= te('Passwort ändern') ?></h1>

<?php if ($passwordChanged): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Passwort geändert.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/account/password">
    <?= csrf_field() ?>
    <label>
        <?= te('Aktuelles Passwort') ?>
        <input type="password" name="current_password" required autocomplete="current-password" autofocus>
    </label>
    <label>
        <?= te('Neues Passwort') ?>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
    </label>
    <label>
        <?= te('Neues Passwort wiederholen') ?>
        <input type="password" name="new_password_repeat" required minlength="8" autocomplete="new-password">
    </label>
    <p class="muted"><?= te('Mindestens 8 Zeichen.') ?></p>
    <button type="submit"><i class="fa-solid fa-check"></i> <?= te('Passwort ändern') ?></button>
</form>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
