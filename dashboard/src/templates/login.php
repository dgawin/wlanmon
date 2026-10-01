<?php
/** @var string|null $error */
/** @var string $next */
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Login') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-right-to-bracket"></i> <?= te('Login') ?></h1>

<?php if (isset($_GET['activated'])): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Passwort gesetzt, du kannst dich jetzt einloggen.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/login">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>
        <?= te('Benutzername') ?>
        <input type="text" name="username" required autofocus autocomplete="username">
    </label>
    <label>
        <?= te('Passwort') ?>
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit"><i class="fa-solid fa-right-to-bracket"></i> <?= te('Einloggen') ?></button>
</form>
</main>
</body>
</html>
