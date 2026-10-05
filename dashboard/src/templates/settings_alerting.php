<?php
/** @var array $alerting */
/** @var bool $settingsSaved */

$email = is_array($alerting['email'] ?? null) ? $alerting['email'] : [];
$telegram = is_array($alerting['telegram'] ?? null) ? $alerting['telegram'] : [];

/** Passwortfeld mit Anzeigen/Verbergen-Schalter (Umschalten macht das JS unten). */
$pwField = function (string $name, bool $isSet, string $placeholder = ''): void {
    // "Nur schreiben": gespeicherte Werte gehen nie ins Formular. Leer lassen =
    // unverändert, "entfernen" löscht den gespeicherten Wert (siehe
    // secret_from_form() in src/Secrets.php).
    $clearName = substr($name, -1) === ']' ? substr($name, 0, -1) . '_clear]' : $name . '_clear';
    ?>
    <span class="pw-field">
        <input type="password" name="<?= e($name) ?>" value=""
               placeholder="<?= e($isSet ? __('gespeichert – leer lassen = unverändert') : $placeholder) ?>" autocomplete="new-password">
        <button type="button" class="pw-toggle btn-secondary btn-small" aria-label="<?= te('Passwort anzeigen') ?>"><?= te('Anzeigen') ?></button>
    </span>
    <?php if ($isSet): ?>
        <span class="secret-clear"><input type="checkbox" name="<?= e($clearName) ?>" value="1"> <?= te('gespeicherten Wert entfernen') ?></span>
    <?php endif; ?>
    <?php
};
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Zugangsdaten für die Alarmierung') ?> – WLANMON</title>
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
<h1><i class="fa-solid fa-bell"></i> <?= te('Zugangsdaten für die Alarmierung') ?></h1>

<?php if ($settingsSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Einstellungen gespeichert.') ?></p>
<?php endif; ?>

<p class="muted">
    <?= teh('Gemeinsamer E-Mail-/Telegram-Kanal für alle Standorte. Diese Einstellungen liegen in der Datenbank (nicht in %s). Passwort und Bot-Token werden verschlüsselt gespeichert (sofern ein Schlüssel eingerichtet ist, siehe Datenhaltung) und hier nicht wieder angezeigt.', '<code>config.php</code>') ?>
    <?= teh('Ob/an wen/wann je Standort tatsächlich alarmiert wird, legst du unter %s fest.', '<a href="/sites">' . te('Standorte') . '</a> → <i class="fa-solid fa-bell"></i> ' . te('Alarmierung')) ?>
</p>

<form method="post" action="/settings/alerting" class="config-form">
    <?= csrf_field() ?>
    <fieldset>
        <legend><i class="fa-solid fa-envelope"></i> <?= te('E-Mail (SMTP)') ?></legend>
        <div class="eap-grid">
            <label><?= te('SMTP-Host') ?>
                <input type="text" name="smtp_host" value="<?= e($email['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
            </label>
            <label><?= te('SMTP-Port') ?>
                <input type="number" min="1" max="65535" name="smtp_port" value="<?= e($email['smtp_port'] ?? 587) ?>">
            </label>
            <label><?= te('Verschlüsselung') ?>
                <?php $secure = $email['smtp_secure'] ?? 'tls'; ?>
                <select name="smtp_secure">
                    <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                    <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>><?= te('SSL/TLS (implizit)') ?></option>
                    <option value="none" <?= $secure === 'none' ? 'selected' : '' ?>><?= te('Keine') ?></option>
                </select>
            </label>
            <label><?= te('SMTP-Benutzername') ?>
                <input type="text" name="smtp_user" value="<?= e($email['smtp_user'] ?? '') ?>" autocomplete="off">
            </label>
            <label><?= te('SMTP-Passwort') ?>
                <?php $pwField('smtp_pass', ($email['smtp_pass'] ?? '') !== ''); ?>
            </label>
            <label><?= te('Absender (From)') ?>
                <input type="email" name="email_from" value="<?= e($email['from'] ?? '') ?>" placeholder="wlanmon@example.com">
            </label>
            <label><?= te('Absendername') ?>
                <input type="text" name="email_from_name" value="<?= e($email['from_name'] ?? 'WLANMON') ?>">
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fa-brands fa-telegram"></i> <?= te('Telegram') ?></legend>
        <label><?= te('Bot-Token') ?>
            <?php $pwField('bot_token', ($telegram['bot_token'] ?? '') !== '', '123456:ABC-DEF...'); ?>
        </label>
        <span class="muted">
            <?= teh('Bot-Token über %s anlegen. Chat-ID wird je Standort unter %s hinterlegt.', '<a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>', '<a href="/sites">' . te('Standorte') . '</a>') ?>
        </span>
    </fieldset>

    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Speichern') ?></button>
</form>

<script>
(function () {
    document.body.addEventListener('click', function (e) {
        if (e.target.classList.contains('pw-toggle')) {
            var input = e.target.closest('.pw-field').querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            e.target.textContent = show ? <?= tjson('Verbergen') ?> : <?= tjson('Anzeigen') ?>;
            e.target.setAttribute('aria-label', show ? <?= tjson('Passwort verbergen') ?> : <?= tjson('Passwort anzeigen') ?>);
        }
    });
})();
</script>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
