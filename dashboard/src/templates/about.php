<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Über') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-circle-info"></i> <?= te('Über WLANMON') ?></h1>
<p>
    <?= te('WLANMON überwacht WLAN-Qualität und -Erreichbarkeit an mehreren Standorten: ein schlanker Probe-Client (Python, läuft auf NanoPi/Raspberry-Pi-Hardware) führt periodisch WLAN-Scans, Verbindungstests und Durchsatzmessungen durch und meldet die Ergebnisse an dieses Dashboard (PHP/MySQL), das sie auswertet, visualisiert und bei Störungen per E-Mail/Telegram alarmiert.') ?>
</p>
<p>
    <?= teh('Entwickelt von %s gemeinsam mit %s (Anthropic) als KI-Umsetzungspartner.', '<strong>Dominik Gawin</strong>', '<strong>Claude</strong>') ?>
</p>
<p>
    <a href="https://dominikgawin.de" target="_blank" rel="noopener">
        <i class="fa-solid fa-globe"></i> dominikgawin.de
    </a>
</p>
<p class="muted"><?= te('Dashboard-Version %s', dashboard_version()) ?></p>
</main>
</body>
</html>
