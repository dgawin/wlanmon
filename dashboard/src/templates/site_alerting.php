<?php
/** @var array $site */
/** @var array $siteAlerting */
/** @var bool $settingsSaved */
/** @var array|null $testResult null = kein Testalarm in diesem Request, sonst Ergebnis je Kanal */

$emailTo = json_decode((string) ($siteAlerting['email_to'] ?? '[]'), true);
$emailTo = is_array($emailTo) ? $emailTo : [];
$scheduleDays = array_map('intval', array_filter(explode(',', (string) ($siteAlerting['schedule_days'] ?? ''))));
$weekdayLabels = [1 => __('Mo'), 2 => __('Di'), 3 => __('Mi'), 4 => __('Do'), 5 => __('Fr'), 6 => __('Sa'), 7 => __('So')];

/** Ergebnis-Pill für einen Kanal, falls gerade ein Testalarm gelaufen ist. */
$resultPill = function (string $channel) use ($testResult): void {
    if ($testResult === null || !array_key_exists($channel, $testResult)) {
        return;
    }
    $r = $testResult[$channel];
    if (!empty($r['ok'])) {
        echo '<span class="pill pill-ok"><i class="fa-solid fa-circle-check"></i> ' . te('Testalarm gesendet') . '</span>';
    } else {
        echo '<span class="pill pill-fail" title="' . e((string) ($r['error'] ?? '')) . '">'
            . '<i class="fa-solid fa-circle-xmark"></i> ' . te('Fehlgeschlagen:') . ' ' . e((string) ($r['error'] ?? __('unbekannter Fehler')))
            . '</span>';
    }
};
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Alarmierung') ?> – <?= e($site['name']) ?> – WLANMON</title>
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
<p><a href="/sites" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Standorte') ?></a></p>
<h1><i class="fa-solid fa-bell"></i> <?= te('Alarmierung') ?>: <?= e($site['name']) ?></h1>

<?php if ($settingsSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Einstellungen gespeichert.') ?></p>
<?php endif; ?>
<?php if ($testResult === []): ?>
    <p class="muted"><i class="fa-solid fa-circle-info"></i> <?= te('Testalarm ausgelöst, aber kein Kanal ist unten aktiviert (E-Mail bzw. Telegram) – es wurde nichts verschickt.') ?></p>
<?php elseif (is_array($testResult)): ?>
    <p class="ok"><i class="fa-solid fa-paper-plane"></i> <?= te('Testalarm ausgelöst – Ergebnis je Kanal unten.') ?></p>
<?php endif; ?>

<p class="muted">
    <?= teh('SMTP-/Telegram-Zugangsdaten sind gemeinsam für alle Standorte unter %s hinterlegt (nur Admin). Hier legst du nur fest, OB/AN WEN/WANN für %s alarmiert wird.', '<a href="/settings/alerting">' . te('Zugangsdaten für die Alarmierung') . '</a>', e($site['name'])) ?>
</p>

<form method="post" action="/sites/<?= (int) $site['id'] ?>/alerting" class="config-form" id="siteAlertingForm">
    <?= csrf_field() ?>
    <fieldset>
        <legend><i class="fa-solid fa-toggle-on"></i> <?= te('Allgemein') ?></legend>
        <label class="check-label">
            <input type="checkbox" name="enabled" value="1" <?= !empty($siteAlerting['enabled']) ? 'checked' : '' ?>>
            <?= te('Alarmierung für %s aktiv', $site['name']) ?>
        </label>
        <label>
            <?= te('Gerät gilt als offline nach (Minuten)') ?>
            <input type="number" min="1" name="offline_after_minutes"
                   value="<?= e($siteAlerting['offline_after_minutes'] ?? 20) ?>">
        </label>
        <label>
            <?= te('SSID gilt als ausgefallen nach (aufeinanderfolgenden fehlgeschlagenen Connection-Tests)') ?>
            <input type="number" min="2" name="consecutive_test_failures"
                   value="<?= e($siteAlerting['consecutive_test_failures'] ?? 3) ?>">
        </label>
        <label>
            <?= te('Erinnerung bei andauernder Störung nach (Minuten)') ?>
            <input type="number" min="5" name="repeat_after_minutes"
                   value="<?= e($siteAlerting['repeat_after_minutes'] ?? 240) ?>">
            <span class="muted"><?= te('Verhindert Spam bei jedem Cron-Tick, solange dieselbe Störung weiterbesteht.') ?></span>
        </label>
        <label>
            <?= te('Sprache der Alarmmeldungen') ?>
            <?php $alertLang = $siteAlerting['language'] ?? 'de'; ?>
            <select name="language">
                <?php foreach (WLANMON_LANGS as $code => $name): ?>
                    <option value="<?= e($code) ?>" <?= $alertLang === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="muted"><?= te('Gilt für E-Mail und Telegram dieses Standorts, unabhängig von der Sprache der Weboberfläche.') ?></span>
        </label>
    </fieldset>

    <fieldset>
        <legend><i class="fa-solid fa-clock"></i> <?= te('Zeitfenster') ?></legend>
        <?php $scheduleMode = $siteAlerting['schedule_mode'] ?? 'always'; ?>
        <label class="check-label">
            <input type="radio" name="schedule_mode" value="always" id="scheduleAlways" <?= $scheduleMode !== 'custom' ? 'checked' : '' ?>>
            <?= te('Durchgängig (24/7)') ?>
        </label>
        <label class="check-label">
            <input type="radio" name="schedule_mode" value="custom" id="scheduleCustom" <?= $scheduleMode === 'custom' ? 'checked' : '' ?>>
            <?= te('Nur zu bestimmten Zeiten') ?>
        </label>
        <div id="scheduleFields" class="eap-grid">
            <div>
                <span class="target-section-title"><?= te('Wochentage') ?></span>
                <?php foreach ($weekdayLabels as $iso => $label): ?>
                    <label class="check-label">
                        <input type="checkbox" name="schedule_days[]" value="<?= $iso ?>"
                               <?= in_array($iso, $scheduleDays, true) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <label><?= te('Von (Stunde)') ?>
                <input type="number" min="0" max="23" name="schedule_start_hour"
                       value="<?= e($siteAlerting['schedule_start_hour'] ?? 8) ?>">
            </label>
            <label><?= te('Bis (Stunde, exklusiv)') ?>
                <input type="number" min="1" max="24" name="schedule_end_hour"
                       value="<?= e($siteAlerting['schedule_end_hour'] ?? 20) ?>">
                <span class="muted"><?= te('20 = bis 19:59 Uhr.') ?></span>
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fa-solid fa-envelope"></i> <?= te('E-Mail') ?></legend>
        <label class="check-label">
            <input type="checkbox" name="email_enabled" value="1" <?= !empty($siteAlerting['email_enabled']) ? 'checked' : '' ?>>
            <?= te('E-Mail-Kanal aktiv') ?>
        </label>
        <label>
            <?= te('Empfänger (kommagetrennt)') ?>
            <input type="text" name="email_to"
                   value="<?= e(implode(', ', array_map('strval', $emailTo))) ?>"
                   placeholder="admin@example.com, noc@example.com">
        </label>
        <?php $resultPill('email'); ?>
    </fieldset>

    <fieldset>
        <legend><i class="fa-brands fa-telegram"></i> <?= te('Telegram') ?></legend>
        <label class="check-label">
            <input type="checkbox" name="telegram_enabled" value="1" <?= !empty($siteAlerting['telegram_enabled']) ? 'checked' : '' ?>>
            <?= te('Telegram-Kanal aktiv') ?>
        </label>
        <label>
            <?= te('Chat-ID') ?>
            <input type="text" name="telegram_chat_id" value="<?= e($siteAlerting['telegram_chat_id'] ?? '') ?>" placeholder="-100123456789">
            <span class="muted"><?= teh('Ermitteln z.B. über %s nach einer Testnachricht an den Bot.', '<code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code>') ?></span>
        </label>
        <?php $resultPill('telegram'); ?>
    </fieldset>

    <div class="form-actions">
        <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Speichern') ?></button>
        <button type="submit" formaction="/sites/<?= (int) $site['id'] ?>/alerting/test" class="btn-secondary">
            <i class="fa-solid fa-paper-plane"></i> <?= te('Testalarm senden') ?>
        </button>
    </div>
    <span class="muted"><?= te('„Testalarm senden“ nutzt die zuletzt gespeicherten Einstellungen – vorher speichern.') ?></span>
</form>

<script>
(function () {
    var scheduleFields = document.getElementById('scheduleFields');
    var always = document.getElementById('scheduleAlways');
    var custom = document.getElementById('scheduleCustom');
    function refresh() {
        scheduleFields.style.display = custom.checked ? '' : 'none';
    }
    always.addEventListener('change', refresh);
    custom.addEventListener('change', refresh);
    refresh();
})();
</script>
</main>
</body>
</html>
