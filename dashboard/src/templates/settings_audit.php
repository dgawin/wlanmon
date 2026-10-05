<?php
/** @var array<int, array<string, mixed>> $auditEntries */
$auditShowObject = true;
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Änderungsprotokoll') ?> – WLANMON</title>
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
<p><a href="/" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Übersicht') ?></a></p>
<h1><i class="fa-solid fa-clock-rotate-left"></i> <?= te('Änderungsprotokoll') ?></h1>
<p class="muted">
    <?= te('Wer wann was an Geräten, Standorten, Benutzern, Alarmierung und Datenhaltung geändert hat (die letzten 300 Einträge). Passwörter, PSKs, Schlüssel und Tokens erscheinen nur als „geändert“, nie mit Wert. Einträge werden so lange aufbewahrt wie die Tests (siehe Datenhaltung).') ?>
</p>
<?php require __DIR__ . '/_audit_list.php'; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
