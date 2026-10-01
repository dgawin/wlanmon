<?php
/** @var string $deviceId */
/** @var string $apiKey */
/** @var bool $rotated Wiederverwendet für handle_rotate_key_via_form() - true = Key rotiert statt neu angelegt. */
$rotated ??= false;
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $rotated ? te('API-Key rotiert') : te('Gerät angelegt') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-circle-check ok"></i> <?= $rotated ? te('API-Key rotiert') : te('Gerät angelegt') ?></h1>
<p>Device-ID: <code><?= e($deviceId) ?></code></p>
<?php if ($rotated): ?>
<p class="muted"><?= teh('Der bisherige Key ist ab sofort ungültig - das Gerät ist offline, bis der neue Key unten in seiner %s eingetragen ist.', '<code>config.yaml</code>') ?></p>
<?php endif; ?>

<p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= teh('Der API-Key wird nur jetzt im Klartext angezeigt und ist danach nirgends mehr abrufbar – jetzt in die %s des Clients übernehmen.', '<code>config.yaml</code>') ?></p>

<pre><code><?= e($apiKey) ?></code></pre>

<p><?= teh('Passender Ausschnitt für %s:', '<code>/etc/wlanmon-probe/config.yaml</code>') ?></p>
<pre><code>server:
  url: "<?= e(request_base_url()) ?>/api/v1"
  api_key: "<?= e($apiKey) ?>"
  verify_tls: true</code></pre>

<?php if ($rotated): ?>
<p><a href="/devices/<?= e(rawurlencode($deviceId)) ?>/config" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Zur Konfiguration') ?></a></p>
<?php else: ?>
<p><a href="/" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Zur Geräteliste') ?></a></p>
<?php endif; ?>
</main>
</body>
</html>
