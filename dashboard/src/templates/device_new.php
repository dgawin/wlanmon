<?php
/** @var string|null $error */
/** @var array $sites */
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Neues Gerät') ?> – WLANMON</title>
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
<p><a href="/" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Alle Geräte') ?></a></p>
<h1><i class="fa-solid fa-plus"></i> <?= te('Neues Gerät anlegen') ?></h1>

<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/devices/new">
    <?= csrf_field() ?>
    <label>
        <?= te('Device-ID') ?>
        <input type="text" name="device_id" required
               pattern="[a-zA-Z0-9_.\-]+"
               placeholder="wlanmon-probe-02">
    </label>
    <p class="muted"><?= teh('Nur Buchstaben, Zahlen, Punkt, Bindestrich, Unterstrich. Muss zur %s in der config.yaml des Clients passen.', '<code>device.id</code>') ?></p>

    <label>
        <?= te('Standort (optional)') ?>
        <select name="site_id">
            <option value=""><?= te('– kein Standort –') ?></option>
            <?php foreach ($sites as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (empty($sites)): ?>
            <span class="muted"><?= te('Noch keine Standorte angelegt - siehe') ?> <a href="/sites"><?= te('Standorte') ?></a>.</span>
        <?php endif; ?>
    </label>

    <button type="submit"><i class="fa-solid fa-plus"></i> <?= te('Anlegen') ?></button>
</form>
</main>
</body>
</html>
