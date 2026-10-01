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
<main class="text-main">
<h1><i class="fa-solid fa-circle-info"></i> <?= te('Über WLANMON') ?></h1>
<p>
    <?= te('WLANMON überwacht WLAN-Qualität und -Erreichbarkeit an mehreren Standorten. Kleine Messgeräte (NanoPi, Raspberry Pi) scannen regelmäßig die WLAN-Umgebung, verbinden sich mit den konfigurierten Netzen und messen Verbindungsaufbau, DHCP, Latenz und Durchsatz. Dieses Dashboard wertet die Ergebnisse aus, zeigt Verlauf und Spektrum und alarmiert bei Störungen per E-Mail oder Telegram.') ?>
</p>

<h2><span><i class="fa-solid fa-door-open"></i> <?= te('Gast-WLANs mit Captive Portal') ?></span></h2>
<p>
    <?= te('Ein Gast-WLAN mit Anmeldeseite ist für viele Messwerkzeuge eine Sackgasse. WLANMON erkennt das Portal, meldet sich automatisch an – mit Benutzername und Passwort, Voucher-Code oder per Bestätigung der Nutzungsbedingungen – und misst danach Latenz und Durchsatz so, wie ein echter Gast sie erlebt. Am Ende des Tests meldet sich die Probe wieder ab; mit zufälliger MAC-Adresse durchläuft sie das Portal bei jedem Test wie ein neues Gerät.') ?>
</p>
<p class="muted">
    <?= te('Unterstützt: Alcatel-Lucent OmniVista Cirrus und klassische Formular-Portale (z. B. pfSense). Weitere Portale lassen sich als eigenes Modul ergänzen.') ?>
</p>

<h2><span><i class="fa-solid fa-link"></i> <?= te('Projekt') ?></span></h2>
<p>
    <a href="https://wlanmon.com" target="_blank" rel="noopener"><i class="fa-solid fa-globe"></i> wlanmon.com</a><br>
    <a href="https://wlanmon.de" target="_blank" rel="noopener"><i class="fa-solid fa-globe"></i> wlanmon.de</a><br>
    <a href="https://github.com/dgawin/wlanmon" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> github.com/dgawin/wlanmon</a>
</p>
<p>
    <?= teh('Entwickelt von %s gemeinsam mit %s (Anthropic) als KI-Umsetzungspartner. Open Source unter der MIT-Lizenz.', '<strong>Dominik Gawin</strong>', '<strong>Claude</strong>') ?>
</p>
<p class="muted"><?= te('Dashboard-Version %s', dashboard_version()) ?></p>
</main>
</body>
</html>
