<?php
/** @var array{scan_days: int, test_days: int, capture_days: int} $retention */
/** @var array{kinds: array, size_bytes: ?int} $overview */
/** @var array|null $lastCleanup */
/** @var array|null $lastBackup */
/** @var bool $settingsSaved */
/** @var string|null $error */
/** @var array{sodium: bool, configured: bool, valid: bool} $secretKey */
/** @var array{encrypted: int, plain: int, broken: int} $secrets */
/** @var int|null $encryptedNow */

$kindRows = [
    'scan' => [__('Scans'), 'scan_days'],
    'connection_test' => [__('Connection-Tests'), 'test_days'],
    'lan_test' => [__('LAN-Tests'), 'test_days'],
];
$daysLabel = static fn(int $days): string => $days > 0 ? __('%d Tage', $days) : __('unbegrenzt');

// Backup gilt als veraltet, wenn das letzte erfolgreiche älter als 36 h ist.
$backupAgeHours = null;
if (!empty($lastBackup['at'])) {
    $backupAgeHours = (time() - (new DateTime((string) $lastBackup['at'], new DateTimeZone('UTC')))->getTimestamp()) / 3600;
}
$backupOk = !empty($lastBackup['ok']) && $backupAgeHours !== null && $backupAgeHours <= 36;
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Datenhaltung') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-database"></i> <?= te('Datenhaltung') ?></h1>

<?php if ($settingsSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Einstellungen gespeichert.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>

<p class="muted">
    <?= teh('Messungen, die älter als die eingestellte Aufbewahrung sind, löscht das nächtliche Aufräumen (%s) endgültig aus der Datenbank. Das Backup (%s) läuft vorher - gelöschte Daten stecken also noch in den Backups der letzten Tage.', '<code>cleanup_data.php</code>', '<code>backup_db.php</code>') ?>
</p>

<form method="post" action="/settings/retention" class="config-form">
    <?= csrf_field() ?>
    <fieldset>
        <legend><i class="fa-solid fa-clock-rotate-left"></i> <?= te('Aufbewahrung') ?></legend>
        <label>
            <?= te('WLAN-Scans aufbewahren (Tage)') ?>
            <input type="number" min="0" max="<?= RETENTION_MAX_DAYS ?>" name="scan_days" value="<?= (int) $retention['scan_days'] ?>">
            <span class="muted"><?= te('Mit Abstand der größte Datenposten (ein Scan pro Minute und Gerät). Standard: 90 Tage.') ?></span>
        </label>
        <label>
            <?= te('Connection- und LAN-Tests aufbewahren (Tage)') ?>
            <input type="number" min="0" max="<?= RETENTION_MAX_DAYS ?>" name="test_days" value="<?= (int) $retention['test_days'] ?>">
            <span class="muted"><?= te('Die eigentliche Qualitätshistorie, braucht wenig Platz. Gilt auch für behobene Alarme. Standard: 365 Tage.') ?></span>
        </label>
        <label>
            <?= te('Mitschnitte fehlgeschlagener Tests aufbewahren (Tage)') ?>
            <input type="number" min="0" max="<?= RETENTION_MAX_DAYS ?>" name="capture_days" value="<?= (int) $retention['capture_days'] ?>">
            <span class="muted"><?= te('pcap und Adapter-Ereignisse zu fehlgeschlagenen Connection-Tests. Enthalten MAC-Adressen und ggf. 802.1X-Identitäten. Standard: 30 Tage.') ?></span>
        </label>
        <p class="muted"><?= te('0 = unbegrenzt aufbewahren.') ?></p>
    </fieldset>
    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Speichern') ?></button>
</form>

<h2><span><i class="fa-solid fa-chart-simple"></i> <?= te('Gespeicherte Messungen') ?></span></h2>
<div class="table-scroll">
<table>
    <thead><tr><th><?= te('Art') ?></th><th><?= te('Anzahl') ?></th><th><?= te('Älteste') ?></th><th><?= te('Aufbewahrung') ?></th></tr></thead>
    <tbody>
    <?php foreach ($kindRows as $kind => [$label, $key]): $k = $overview['kinds'][$kind] ?? null; ?>
        <tr>
            <td><?= e($label) ?></td>
            <td><?= e(number_format((int) ($k['count'] ?? 0), 0, ',', effective_lang() === 'en' ? ',' : '.')) ?></td>
            <td><?= !empty($k['oldest']) ? e(format_local((string) $k['oldest'], 'd.m.Y')) : '–' ?></td>
            <td><?= e($daysLabel((int) $retention[$key])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="muted"><?= te('Größe der Messwert-Tabelle:') ?> <?= e(format_bytes($overview['size_bytes'])) ?></p>

<h2><span><i class="fa-solid fa-broom"></i> <?= te('Letztes Aufräumen') ?></span></h2>
<?php if (empty($lastCleanup['at'])): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= teh('Noch nie gelaufen - Cronjob für %s einrichten (siehe README, Abschnitt „Datenhaltung & Backup“).', '<code>cleanup_data.php</code>') ?></p>
<?php else: ?>
    <p>
        <?= e(format_local((string) $lastCleanup['at'], 'd.m.Y H:i')) ?> ·
        <?php
        $deletedLabels = [
            'scan' => __('Scans'),
            'connection_test' => __('Connection-Tests'),
            'lan_test' => __('LAN-Tests'),
            'alerts' => __('behobene Alarme'),
            'invites' => __('abgelaufene Einladungen'),
            'audit' => __('Protokolleinträge'),
            'captures' => __('Mitschnitte'),
        ];
        $deletedParts = [];
        foreach ((array) ($lastCleanup['deleted'] ?? []) as $kind => $count) {
            $deletedParts[] = ($deletedLabels[$kind] ?? (string) $kind) . ' ' . (int) $count;
        }
        ?>
        <?= te('gelöscht:') ?> <?= e(implode(' · ', $deletedParts)) ?>
    </p>
<?php endif; ?>

<h2><span><i class="fa-solid fa-box-archive"></i> <?= te('Letztes Backup') ?></span></h2>
<?php if (empty($lastBackup['at'])): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= teh('Noch kein Backup gelaufen - Cronjob für %s einrichten (siehe README, Abschnitt „Datenhaltung & Backup“).', '<code>backup_db.php</code>') ?></p>
<?php else: ?>
    <p>
        <span class="pill <?= $backupOk ? 'pill-ok' : 'pill-fail' ?>">
            <i class="fa-solid <?= $backupOk ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            <?= !empty($lastBackup['ok']) ? ($backupOk ? te('erfolgreich') : te('veraltet')) : te('fehlgeschlagen') ?>
        </span>
        <?= e(format_local((string) $lastBackup['at'], 'd.m.Y H:i')) ?>
        <?php if (!empty($lastBackup['ok'])): ?>
            · <code><?= e((string) $lastBackup['file']) ?></code> (<?= e(format_bytes(isset($lastBackup['bytes']) ? (int) $lastBackup['bytes'] : null)) ?>)
        <?php endif; ?>
    </p>
    <?php if (empty($lastBackup['ok']) && !empty($lastBackup['error'])): ?>
        <p class="fail"><?= e((string) $lastBackup['error']) ?></p>
    <?php endif; ?>
    <p class="muted">
        <?= te('Verzeichnis %s, Aufbewahrung %d Tage, %d Backup(s) vorhanden.', (string) ($lastBackup['dir'] ?? '?'), (int) ($lastBackup['keep_days'] ?? 0), (int) ($lastBackup['kept'] ?? 0)) ?>
    </p>
<?php endif; ?>

<h2><span><i class="fa-solid fa-lock"></i> <?= te('Verschlüsselung der Zugangsdaten') ?></span></h2>
<?php if ($encryptedNow !== null): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('%d Werte verschlüsselt.', $encryptedNow) ?></p>
<?php endif; ?>
<?php if (!$secretKey['sodium']): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Die PHP-Erweiterung sodium fehlt - Zugangsdaten werden unverschlüsselt gespeichert.') ?></p>
<?php elseif (!$secretKey['configured']): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= teh('Kein Schlüssel eingerichtet - Zugangsdaten werden unverschlüsselt gespeichert. Schlüssel mit %s erzeugen und in config.php als %s eintragen (siehe README, Abschnitt „Zugangsdaten verschlüsseln“).', '<code>php tools/secrets.php generate-key</code>', '<code>secret_key</code>') ?></p>
<?php elseif (!$secretKey['valid']): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= teh('Der Schlüssel %s in config.php ist ungültig (erwartet: 32 Byte, base64).', '<code>secret_key</code>') ?></p>
<?php else: ?>
    <p><span class="pill pill-ok"><i class="fa-solid fa-circle-check"></i> <?= te('Schlüssel eingerichtet') ?></span></p>
<?php endif; ?>
<p><?= te('Gespeicherte Zugangsdaten: %d verschlüsselt, %d unverschlüsselt, %d nicht entschlüsselbar.', $secrets['encrypted'], $secrets['plain'], $secrets['broken']) ?></p>
<?php if ($secrets['broken'] > 0): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Einige Werte lassen sich mit dem aktuellen Schlüssel nicht entschlüsseln (falscher oder neuer Schlüssel?). Die betroffenen Geräte bekommen keine neue Konfiguration, bis die Zugangsdaten neu eingegeben sind.') ?></p>
<?php endif; ?>
<?php if ($secretKey['valid'] && $secrets['plain'] > 0): ?>
    <form method="post" action="/settings/secrets/encrypt" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit"><i class="fa-solid fa-lock"></i> <?= te('Jetzt alle verschlüsseln') ?></button>
    </form>
<?php endif; ?>
<p class="muted"><?= te('Den Schlüssel getrennt vom Datenbank-Backup aufbewahren (z. B. im Passwort-Manager): ohne ihn sind die verschlüsselten Zugangsdaten verloren und müssen neu eingegeben werden.') ?></p>
</main>
</body>
</html>
