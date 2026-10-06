<?php
/** @var array $device */
/** @var array $config */
/** @var bool $configSaved */
/** @var array $sites */
/** @var string|null $newApiKey Nur direkt nach einer Rotation gesetzt - einmalige Anzeige. */
/** @var array $profiles Profile, die dieses Gerät nutzen darf */
/** @var array|null $currentProfile */

$isAdmin = (current_user()['role'] ?? null) === 'admin';

require __DIR__ . '/_target_fields.php';
?>
<!DOCTYPE html>
<html lang="<?= e(effective_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Konfiguration') ?> – <?= e($device['id']) ?> – WLANMON</title>
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
<p><a href="/devices/<?= e(rawurlencode($device['id'])) ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> <?= e($device['id']) ?></a></p>
<h1><i class="fa-solid fa-gear"></i> <?= te('Konfiguration:') ?> <?= e($device['id']) ?></h1>

<?php if ($configSaved): ?>
    <p class="ok"><i class="fa-solid fa-circle-check"></i> <?= teh('Konfiguration gespeichert. Das Gerät übernimmt sie beim nächsten Poll (siehe %s).', '<code>remote_config.poll_interval_seconds</code>') ?></p>
<?php endif; ?>
<p class="muted">
    <?= teh('Wirkt nur, wenn der Client mit %s läuft.', '<code>remote_config.enabled: true</code>') ?>
    <?= te('Leeres Ping-Ziel/iperf3-Server unten = Standardwert bzw. deaktiviert. Captive Portal, iperf3-Server/-Dauer und die zufällige MAC lassen sich außerdem je Ziel-SSID einstellen bzw. überschreiben - siehe die jeweilige SSID-Karte weiter unten.') ?>
</p>
<form method="post" action="/devices/<?= e(rawurlencode($device['id'])) ?>/config" class="config-form">
    <?= csrf_field() ?>
    <fieldset>
        <legend><i class="fa-solid fa-circle-info"></i> <?= te('Allgemein') ?></legend>
        <?php if ($isAdmin): ?>
        <label>
            <?= te('Standort') ?>
            <select name="site_id">
                <option value=""><?= te('– kein Standort –') ?></option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) ($device['site_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($sites)): ?>
                <span class="muted"><?= te('Noch keine Standorte angelegt - siehe') ?> <a href="/sites"><?= te('Standorte') ?></a>.</span>
            <?php endif; ?>
        </label>
        <?php endif; ?>
        <label>
            <?= te('Bemerkung (optional)') ?>
            <textarea name="notes" rows="3" placeholder="<?= te('z.B. Ansprechpartner vor Ort, Hardware-Besonderheiten') ?>"><?= e($device['notes'] ?? '') ?></textarea>
        </label>
    </fieldset>

    <fieldset>
        <legend><i class="fa-solid fa-layer-group"></i> <?= te('Konfiguration') ?></legend>
        <label>
            <?= te('Dieses Gerät nutzt') ?>
            <select name="profile_id" id="profileSelect">
                <option value=""><?= te('eigene Einstellungen (unten)') ?></option>
                <?php foreach ($profiles as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) ($currentProfile['id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= te('Profil „%s“', $p['name']) ?> (<?= $p['site_name'] !== null ? e($p['site_name']) : te('global') ?>, <?= te('%d SSIDs', (int) $p['ssid_count']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="muted" id="profileHint"<?= $currentProfile === null ? ' hidden' : '' ?>>
            <?= te('Testeinstellungen und SSIDs kommen aus dem Profil. Die eigenen Einstellungen unten bleiben gespeichert und gelten wieder, wenn du zurückschaltest.') ?>
            <?php if ($currentProfile !== null): ?>
                <a href="/profiles/<?= (int) $currentProfile['id'] ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> <?= te('Profil „%s“ öffnen', $currentProfile['name']) ?></a>
            <?php endif; ?>
        </p>
        <?php if ($profiles === []): ?>
            <p class="muted"><?= teh('Noch kein passendes Profil. Profile bündeln SSIDs und Testeinstellungen für mehrere Geräte, siehe %s.', '<a href="/profiles">' . te('Profile') . '</a>') ?></p>
        <?php endif; ?>
    </fieldset>

    <div id="ownSettings"<?= $currentProfile !== null ? ' class="dimmed-settings"' : '' ?>>
    <?php require __DIR__ . '/_test_settings.php'; ?>

    <fieldset>
        <legend><i class="fa-solid fa-wifi"></i> <?= te('Ziel-SSIDs') ?></legend>
        <p class="muted">
            <?= te('PSKs, Passwörter und private Schlüssel werden verschlüsselt gespeichert (sofern ein Schlüssel eingerichtet ist, siehe Datenhaltung), hier nie wieder angezeigt und nur per Remote-Config (HTTPS, Geräte-API-Key) an das Gerät übertragen. Feld leer lassen = gespeicherter Wert bleibt. Für 802.1X am besten ein eigenes, minimal berechtigtes Test-Konto verwenden.') ?>
        </p>
        <div id="targets">
            <?php
            $targets = $config['connection_tests']['targets'] ?? [];
            foreach (array_values($targets) as $i => $t) {
                $renderTarget((string) $i, is_array($t) ? $t : []);
            }
            ?>
        </div>
        <template id="targetTemplate">
            <?php $renderTarget('__IDX__', ['security' => 'wpa2-psk']); ?>
        </template>
        <button type="button" id="addTarget" class="btn-secondary"><i class="fa-solid fa-plus"></i> <?= te('Ziel-SSID hinzufügen') ?></button>
    </fieldset>
    </div>

    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Konfiguration speichern') ?></button>
</form>

<?php require __DIR__ . '/_target_js.php'; ?>
<script>
// Mit Profil sind die eigenen Einstellungen nur abgeblendet (sie werden weiter gespeichert).
document.getElementById('profileSelect').addEventListener('change', function () {
    var withProfile = this.value !== '';
    document.getElementById('ownSettings').classList.toggle('dimmed-settings', withProfile);
    document.getElementById('profileHint').hidden = !withProfile;
});
</script>

<?php if ($currentProfile === null && !empty($config['connection_tests']['targets'])
    && scope_editable(current_user(), isset($device['site_id']) ? (int) $device['site_id'] : null)): ?>
<form method="post" action="/devices/<?= e(rawurlencode($device['id'])) ?>/config/to-profile" class="inline-form"
      onsubmit="return confirm(<?= tjs('Aus den gespeicherten Einstellungen dieses Geräts ein Profil machen? Die SSIDs landen in der SSID-Liste, und das Gerät nutzt danach das neue Profil. Ungespeicherte Änderungen oben gehen verloren.') ?>);">
    <?= csrf_field() ?>
    <button type="submit" class="btn-secondary"><i class="fa-solid fa-layer-group"></i> <?= te('Als Profil übernehmen') ?></button>
    <span class="muted"><?= te('Macht aus den Einstellungen dieses Geräts ein Profil, das du anderen Geräten zuweisen kannst.') ?></span>
</form>
<?php endif; ?>

<h2><span><i class="fa-solid fa-clock-rotate-left"></i> <?= te('Änderungsprotokoll') ?></span></h2>
<?php $auditShowObject = false; require __DIR__ . '/_audit_list.php'; ?>

<div class="danger-zone">
    <h2><i class="fa-solid fa-triangle-exclamation"></i> <?= te('Gefahrenzone') ?></h2>
    <p class="muted"><?= te('Diese Aktionen lassen sich nicht rückgängig machen.') ?></p>

    <form method="post" action="/devices/<?= e(rawurlencode($device['id'])) ?>/measurements/delete-all"
          onsubmit="return confirm(<?= tjs('Wirklich ALLE Messungen (Scans + Connection-Tests) dieses Geräts löschen?') ?>);"
          class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-danger"><i class="fa-solid fa-trash-can"></i> <?= te('Alle Messungen löschen') ?></button>
    </form>

    <?php if ($isAdmin): ?>
    <form method="post" action="/devices/<?= e(rawurlencode($device['id'])) ?>/rotate-key"
          onsubmit="return confirm(<?= tjs('Neuen API-Key für „%s“ erzeugen? Der bisherige Key wird sofort ungültig - das Gerät ist offline, bis der neue Key in seiner config.yaml eingetragen ist.', $device['id']) ?>);"
          class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-danger"><i class="fa-solid fa-key"></i> <?= te('API-Key rotieren') ?></button>
    </form>

    <form method="post" action="/devices/<?= e(rawurlencode($device['id'])) ?>/delete"
          onsubmit="return confirm(<?= tjs('Gerät „%s“ inkl. aller Messungen wirklich endgültig löschen?', $device['id']) ?>);"
          class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-danger"><i class="fa-solid fa-trash-can"></i> <?= te('Gerät löschen') ?></button>
    </form>
    <?php endif; ?>
</div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
