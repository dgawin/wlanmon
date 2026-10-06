<?php
/** @var array|null $profile null = neues Profil */
/** @var array $config Testeinstellungen des Profils (für _test_settings.php) */
/** @var int[] $selectedIds SSIDs des Profils in Reihenfolge */
/** @var array $allSsids siehe ssid_list() */
/** @var array $siteChoices */
/** @var array $devices Geräte mit diesem Profil */
/** @var array $auditEntries */
/** @var bool $profileSaved */
/** @var string|null $error */

$user = current_user();
$siteId = $profile !== null ? scope_site_id($profile['site_id']) : null;
$editable = $profile === null || scope_editable($user, $siteId);
// Gewählte SSIDs zuerst in ihrer Reihenfolge, dann der Rest.
$position = array_flip($selectedIds);
usort($allSsids, function (array $a, array $b) use ($position): int {
    return [$position[(int) $a['id']] ?? PHP_INT_MAX, strtolower(ssid_display($a))]
        <=> [$position[(int) $b['id']] ?? PHP_INT_MAX, strtolower(ssid_display($b))];
});
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $profile !== null ? e($profile['name']) : te('Neues Profil') ?> – WLANMON</title>
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
<p><a href="/profiles" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= te('Profile') ?></a></p>
<h1><i class="fa-solid fa-layer-group"></i> <?= $profile !== null ? e($profile['name']) : te('Neues Profil') ?></h1>

<?php if ($profileSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= te('Profil gespeichert. Geräte mit diesem Profil übernehmen die Änderung beim nächsten Heartbeat.') ?></p>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <p class="fail"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></p>
<?php endif; ?>
<?php if (!$editable): ?>
    <p class="muted"><i class="fa-solid fa-lock"></i> <?= te('Globales Profil - nur Admins können es ändern. Du kannst es Geräten deines Standorts zuweisen.') ?></p>
<?php endif; ?>

<form method="post" action="<?= $profile !== null ? '/profiles/' . (int) $profile['id'] : '/profiles/new' ?>" class="config-form">
    <?= csrf_field() ?>
    <fieldset<?= $editable ? '' : ' disabled' ?>>
        <legend><i class="fa-solid fa-circle-info"></i> <?= te('Allgemein') ?></legend>
        <label>
            <?= te('Name') ?>
            <input type="text" name="name" maxlength="100" required value="<?= e($profile['name'] ?? '') ?>"
                   placeholder="<?= te('z.B. Büro-Standard') ?>">
        </label>
        <label>
            <?= te('Standort') ?>
            <select name="site_id" id="profileSite">
                <?php if ($user['role'] === 'admin'): ?>
                    <option value=""><?= te('global (alle Standorte)') ?></option>
                <?php endif; ?>
                <?php foreach ($siteChoices as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $siteId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
                <?php if ($siteId !== null && !in_array($siteId, array_map('intval', array_column($siteChoices, 'id')), true)): ?>
                    <option value="<?= $siteId ?>" selected><?= e($profile['site_name'] ?? '') ?></option>
                <?php endif; ?>
            </select>
            <span class="muted"><?= te('Global: für Geräte aller Standorte, enthält nur globale SSIDs. Mit Standort: für Geräte dieses Standorts, enthält globale SSIDs und die des Standorts.') ?></span>
        </label>
    </fieldset>

    <fieldset<?= $editable ? '' : ' disabled' ?>>
        <legend><i class="fa-solid fa-wifi"></i> <?= te('SSIDs') ?></legend>
        <?php if ($allSsids === []): ?>
            <p class="muted"><?= teh('Noch keine SSIDs - zuerst unter %s anlegen.', '<a href="/ssids">' . te('SSIDs') . '</a>') ?></p>
        <?php else: ?>
            <p class="muted"><?= te('Die Probe testet die gewählten SSIDs in dieser Reihenfolge. Mit den Pfeilen sortieren.') ?></p>
            <ul class="ssid-picker" id="ssidPicker">
                <?php foreach ($allSsids as $s):
                    $t = json_decode((string) $s['target'], true) ?: [];
                    $ssSite = scope_site_id($s['site_id']); ?>
                    <li data-site="<?= $ssSite === null ? '' : $ssSite ?>">
                        <input type="hidden" name="ssid_order[<?= (int) $s['id'] ?>]" value="0" class="ssid-order">
                        <label class="check-label">
                            <input type="checkbox" name="ssids[]" value="<?= (int) $s['id'] ?>" <?= isset($position[(int) $s['id']]) ? 'checked' : '' ?>>
                            <strong><?= e($t['ssid'] ?? '?') ?></strong>
                            <?php if (($s['label'] ?? '') !== ''): ?><span class="muted"><?= e($s['label']) ?></span><?php endif; ?>
                            <span class="muted">· <?= e((string) ($t['security'] ?? '')) ?> · <?= $s['site_name'] !== null ? e($s['site_name']) : te('global') ?>
                                · #<?= (int) $s['id'] ?> · <?= te('in %d Profilen', (int) $s['profile_count']) ?></span>
                            <a href="/ssids/<?= (int) $s['id'] ?>" class="muted" title="<?= te('SSID öffnen') ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                        </label>
                        <span class="ssid-move">
                            <button type="button" class="btn-secondary btn-small move-up" aria-label="<?= te('nach oben') ?>"><i class="fa-solid fa-arrow-up"></i></button>
                            <button type="button" class="btn-secondary btn-small move-down" aria-label="<?= te('nach unten') ?>"><i class="fa-solid fa-arrow-down"></i></button>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="muted" id="ssidSiteHint" hidden><?= te('Ausgegraute SSIDs gehören zu einem anderen Standort und passen nicht zu diesem Profil.') ?></p>
        <?php endif; ?>
    </fieldset>

    <fieldset class="plain-fieldset"<?= $editable ? '' : ' disabled' ?>>
        <?php require __DIR__ . '/_test_settings.php'; ?>
    </fieldset>

    <?php if ($editable): ?>
        <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Profil speichern') ?></button>
    <?php endif; ?>
</form>

<script>
(function () {
    var picker = document.getElementById('ssidPicker');
    if (!picker) { return; }
    // Reihenfolge der Liste in die versteckten Felder schreiben (beim Speichern ausgewertet).
    function renumber() {
        Array.prototype.forEach.call(picker.children, function (li, i) {
            li.querySelector('.ssid-order').value = String(i);
        });
    }
    picker.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) { return; }
        var li = btn.closest('li');
        if (btn.classList.contains('move-up') && li.previousElementSibling) {
            picker.insertBefore(li, li.previousElementSibling);
        } else if (btn.classList.contains('move-down') && li.nextElementSibling) {
            picker.insertBefore(li.nextElementSibling, li);
        }
        renumber();
    });
    // SSIDs anderer Standorte passen nicht zum gewählten Standort des Profils.
    var site = document.getElementById('profileSite');
    function filterBySite() {
        var siteId = site.value, foreign = false;
        Array.prototype.forEach.call(picker.children, function (li) {
            var ok = li.dataset.site === '' || li.dataset.site === siteId;
            var box = li.querySelector('input[type=checkbox]');
            if (!ok) { box.checked = false; foreign = true; }
            box.disabled = !ok;
            li.classList.toggle('dimmed-settings', !ok);
        });
        document.getElementById('ssidSiteHint').hidden = !foreign;
    }
    site.addEventListener('change', filterBySite);
    renumber();
    filterBySite();
})();
</script>

<?php if ($profile !== null): ?>
    <h2><span><i class="fa-solid fa-microchip"></i> <?= te('Geräte mit diesem Profil') ?></span></h2>
    <?php if ($devices === []): ?>
        <p class="muted"><?= te('Noch keinem Gerät zugewiesen - in der Konfiguration eines Geräts unter „Dieses Gerät nutzt“ auswählen.') ?></p>
    <?php else: ?>
        <ul>
            <?php foreach ($devices as $d): ?>
                <li><a href="/devices/<?= e(rawurlencode((string) $d['id'])) ?>/config"><?= e($d['id']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2><span><i class="fa-solid fa-clock-rotate-left"></i> <?= te('Änderungsprotokoll') ?></span></h2>
    <?php $auditShowObject = false; require __DIR__ . '/_audit_list.php'; ?>

    <?php if ($editable): ?>
    <div class="danger-zone">
        <h2><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Gefahrenzone') ?></h2>
        <?php if ($devices !== []): ?>
            <p class="muted"><?= te('Löschen geht erst, wenn kein Gerät das Profil mehr nutzt.') ?></p>
        <?php else: ?>
            <form method="post" action="/profiles/<?= (int) $profile['id'] ?>/delete" class="inline-form"
                  onsubmit="return confirm(<?= tjs('Profil „%s“ löschen? Die SSIDs bleiben erhalten.', (string) $profile['name']) ?>);">
                <?= csrf_field() ?>
                <button type="submit" class="btn-danger"><i class="fa-solid fa-trash-can"></i> <?= te('Profil löschen') ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
