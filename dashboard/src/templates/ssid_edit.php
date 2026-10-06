<?php
/** @var array|null $ssid null = neue SSID */
/** @var array $target gespeicherte Form (Geheimnisse ggf. verschlüsselt, werden nie angezeigt) */
/** @var array $siteChoices */
/** @var array $usedIn Profile mit dieser SSID */
/** @var array $auditEntries */
/** @var bool $ssidSaved */
/** @var string|null $error */

$user = current_user();
$siteId = $ssid !== null ? scope_site_id($ssid['site_id']) : null;
$editable = $ssid === null || scope_editable($user, $siteId);
require __DIR__ . '/_target_fields.php';
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $ssid !== null ? e(ssid_display($ssid)) : te('Neue SSID') ?> – WLANMON</title>
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
<p><a href="/ssids" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('SSIDs') ?></a></p>
<h1><i class="fa-solid fa-wifi"></i> <?= $ssid !== null ? e(ssid_display($ssid)) : te('Neue SSID') ?></h1>

<?php if ($ssidSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('SSID gespeichert. Geräte mit einem Profil, das diese SSID enthält, übernehmen die Änderung beim nächsten Heartbeat.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>
<?php if (!$editable): ?>
    <p class="muted"><i class="fa-solid fa-lock"></i> <?= te('Globale SSID - nur Admins können sie ändern. Du kannst sie in Profilen deines Standorts verwenden.') ?></p>
<?php endif; ?>

<form method="post" action="<?= $ssid !== null ? '/ssids/' . (int) $ssid['id'] : '/ssids/new' ?>" class="config-form">
    <?= csrf_field() ?>
    <fieldset<?= $editable ? '' : ' disabled' ?>>
        <legend><i class="fa-solid fa-circle-info"></i> <?= te('Allgemein') ?></legend>
        <label>
            <?= te('Standort') ?>
            <select name="site_id">
                <?php if ($user['role'] === 'admin'): ?>
                    <option value=""><?= te('global (alle Standorte)') ?></option>
                <?php endif; ?>
                <?php foreach ($siteChoices as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $siteId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
                <?php if ($siteId !== null && !in_array($siteId, array_map('intval', array_column($siteChoices, 'id')), true)): ?>
                    <option value="<?= $siteId ?>" selected><?= e($ssid['site_name'] ?? '') ?></option>
                <?php endif; ?>
            </select>
            <span class="muted"><?= te('Global: in allen Profilen nutzbar, nur Admins pflegen sie. Mit Standort: nur in Profilen dieses Standorts, die User des Standorts pflegen sie.') ?></span>
        </label>
        <label>
            <?= te('Bezeichnung (optional)') ?>
            <input type="text" name="label" maxlength="100" value="<?= e($ssid['label'] ?? '') ?>"
                   placeholder="<?= te('z.B. Lager, wenn es dieselbe SSID mehrfach gibt') ?>">
        </label>
    </fieldset>

    <fieldset<?= $editable ? '' : ' disabled' ?>>
        <legend><i class="fa-solid fa-wifi"></i> <?= te('Netz') ?></legend>
        <p class="muted"><?= te('PSKs, Passwörter und private Schlüssel werden verschlüsselt gespeichert (sofern ein Schlüssel eingerichtet ist, siehe Datenhaltung), hier nie wieder angezeigt und nur per Remote-Config (HTTPS, Geräte-API-Key) an das Gerät übertragen. Feld leer lassen = gespeicherter Wert bleibt. Für 802.1X am besten ein eigenes, minimal berechtigtes Test-Konto verwenden.') ?></p>
        <div id="targets"><?php $renderTarget('0', $target, 'target'); ?></div>
    </fieldset>

    <?php if ($editable): ?>
        <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('SSID speichern') ?></button>
    <?php endif; ?>
</form>
<?php require __DIR__ . '/_target_js.php'; ?>

<?php if ($ssid !== null): ?>
    <h2><span><i class="fa-solid fa-layer-group"></i> <?= te('Verwendet in') ?></span></h2>
    <?php if ($usedIn === []): ?>
        <p class="muted"><?= te('Noch in keinem Profil.') ?></p>
    <?php else: ?>
        <ul>
            <?php foreach ($usedIn as $p): ?>
                <li><a href="/profiles/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2><span><i class="fa-solid fa-clock-rotate-left"></i> <?= te('Änderungsprotokoll') ?></span></h2>
    <?php $auditShowObject = false; require __DIR__ . '/_audit_list.php'; ?>

    <?php if ($editable): ?>
    <div class="danger-zone">
        <h2><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Gefahrenzone') ?></h2>
        <form method="post" action="/ssids/<?= (int) $ssid['id'] ?>/delete" class="inline-form"
              onsubmit="return confirm(<?= tjs('SSID „%s“ löschen? Sie verschwindet aus allen Profilen (%d), die Geräte testen sie danach nicht mehr.', ssid_display($ssid), count($usedIn)) ?>);">
            <?= csrf_field() ?>
            <button type="submit" class="btn-danger"><i class="fa-solid fa-trash-can"></i> <?= te('SSID löschen') ?></button>
        </form>
    </div>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
