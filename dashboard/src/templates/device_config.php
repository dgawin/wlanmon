<?php
/** @var array $device */
/** @var array $config */
/** @var bool $configSaved */
/** @var array $sites */
/** @var string|null $newApiKey Nur direkt nach einer Rotation gesetzt - einmalige Anzeige. */

$isAdmin = (current_user()['role'] ?? null) === 'admin';

$securityOptions = [
    'open' => 'open',
    'wpa2-psk' => 'wpa2-psk',
    'wpa3-psk' => 'wpa3-psk',
    'wpa2-wpa3-psk' => 'wpa2-wpa3-psk',
    'wpa2-eap' => 'wpa2-eap (802.1X)',
];

/** Portal-Typen für den automatischen Captive-Portal-Login (Module der Probe, siehe portals/). */
$portalTypes = [
    'auto' => __('Automatisch erkennen'),
    'cirrus' => 'Alcatel-Lucent OmniVista Cirrus',
    'form' => __('Formular-Login (generisch, z.B. pfSense)'),
];

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

/**
 * Eine Karte für ein Ziel-WLAN. $idx ist der Array-Index im Formular
 * (targets[<idx>][...]); für das JS-Template wird der Platzhalter __IDX__
 * übergeben. Wird für bestehende Ziele und für das Template genutzt, damit
 * das Markup nur einmal existiert.
 */
$renderTarget = function (string $idx, array $t) use ($securityOptions, $portalTypes, $pwField): void {
    $security = $t['security'] ?? 'wpa2-psk';
    $eap = is_array($t['eap'] ?? null) ? $t['eap'] : [];
    $method = $eap['method'] ?? 'peap';
    $phase2 = $eap['phase2'] ?? 'PAP';
    $verify = array_key_exists('verify_server', $eap) ? !empty($eap['verify_server']) : true;
    $n = 'targets[' . $idx . ']';
    ?>
    <div class="target-card">
        <?php if ($idx !== '__IDX__'): ?>
            <?php // Ursprüngliche Position + SSID: ordnet leer gelassene Passwortfelder beim Speichern dem richtigen gespeicherten Ziel zu. ?>
            <input type="hidden" name="<?= e($n) ?>[_orig]" value="<?= e($idx) ?>">
            <input type="hidden" name="<?= e($n) ?>[_orig_ssid]" value="<?= e($t['ssid'] ?? '') ?>">
        <?php endif; ?>
        <div class="target-head">
            <input type="text" name="<?= e($n) ?>[ssid]" value="<?= e($t['ssid'] ?? '') ?>" placeholder="SSID">
            <select name="<?= e($n) ?>[security]" class="target-security">
                <?php foreach ($securityOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $security === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="remove-target btn-remove"><i class="fa-solid fa-trash-can"></i> <?= te('Entfernen') ?></button>
        </div>

        <div class="target-section">
            <div class="target-psk">
                <label>PSK
                    <?php $pwField($n . '[psk]', ($t['psk'] ?? '') !== '', 'Passphrase'); ?>
                </label>
            </div>

            <div class="target-eap">
                <div class="eap-grid">
                    <label><?= te('EAP-Methode') ?>
                        <select name="<?= e($n) ?>[eap][method]" class="eap-method">
                            <option value="peap" <?= $method === 'peap' ? 'selected' : '' ?>>PEAP (MSCHAPv2)</option>
                            <option value="ttls" <?= $method === 'ttls' ? 'selected' : '' ?>>EAP-TTLS</option>
                            <option value="tls" <?= $method === 'tls' ? 'selected' : '' ?>><?= te('EAP-TLS (Client-Zertifikat)') ?></option>
                        </select>
                    </label>
                    <label><?= te('Benutzername / Identity') ?>
                        <input type="text" name="<?= e($n) ?>[eap][identity]" value="<?= e($eap['identity'] ?? '') ?>"
                               autocomplete="off">
                    </label>
                    <label><?= te('Anonyme Identity (optional)') ?>
                        <input type="text" name="<?= e($n) ?>[eap][anonymous_identity]"
                               value="<?= e($eap['anonymous_identity'] ?? '') ?>" autocomplete="off">
                    </label>
                    <label class="eap-pw"><?= te('Passwort') ?>
                        <?php $pwField($n . '[eap][password]', ($eap['password'] ?? '') !== ''); ?>
                    </label>
                    <label class="eap-ttls"><?= te('Phase 2 (innere Authentifizierung)') ?>
                        <select name="<?= e($n) ?>[eap][phase2]">
                            <?php foreach (['PAP', 'MSCHAPV2', 'MSCHAP', 'CHAP'] as $p2): ?>
                                <option value="<?= e($p2) ?>" <?= $phase2 === $p2 ? 'selected' : '' ?>><?= e($p2) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="eap-tls"><?= te('Passwort des privaten Schlüssels (optional)') ?>
                        <?php $pwField($n . '[eap][private_key_password]', ($eap['private_key_password'] ?? '') !== ''); ?>
                    </label>
                </div>

                <label><?= te('CA-Zertifikat (PEM, optional – mit CA wird der RADIUS-Server geprüft)') ?>
                    <textarea name="<?= e($n) ?>[eap][ca_cert]" rows="4" spellcheck="false"
                              placeholder="-----BEGIN CERTIFICATE-----"><?= e($eap['ca_cert'] ?? '') ?></textarea>
                </label>
                <div class="eap-grid">
                    <label><?= te('Erwarteter Servername (optional)') ?>
                        <input type="text" name="<?= e($n) ?>[eap][server_name]"
                               value="<?= e($eap['server_name'] ?? '') ?>" placeholder="radius.example.com">
                    </label>
                    <label class="check-label">
                        <input type="checkbox" name="<?= e($n) ?>[eap][verify_server]" value="1" <?= $verify ? 'checked' : '' ?>>
                        <?= te('Server-Zertifikat prüfen (aus = nie prüfen, auch mit CA)') ?>
                    </label>
                </div>

                <div class="eap-tls">
                    <label><?= te('Client-Zertifikat (PEM)') ?>
                        <textarea name="<?= e($n) ?>[eap][client_cert]" rows="4" spellcheck="false"
                                  placeholder="-----BEGIN CERTIFICATE-----"><?= e($eap['client_cert'] ?? '') ?></textarea>
                    </label>
                    <label><?= te('Privater Schlüssel (PEM)') ?>
                        <?php $keySet = ($eap['private_key'] ?? '') !== ''; ?>
                        <textarea name="<?= e($n) ?>[eap][private_key]" rows="4" spellcheck="false"
                                  placeholder="<?= e($keySet ? __('gespeichert – leer lassen = unverändert') : '-----BEGIN PRIVATE KEY-----') ?>"></textarea>
                        <?php if ($keySet): ?>
                            <span class="secret-clear"><input type="checkbox" name="<?= e($n) ?>[eap][private_key_clear]" value="1"> <?= te('gespeicherten Wert entfernen') ?></span>
                        <?php endif; ?>
                    </label>
                </div>
            </div>
        </div>

        <div class="target-section">
            <div class="target-section-title"><i class="fa-solid fa-satellite-dish"></i> <?= te('Ping') ?></div>
            <label><?= te('Ping-Ziel') ?>
                <input type="text" name="<?= e($n) ?>[ping_target]"
                       value="<?= e($t['ping_target'] ?? '') ?>"
                       placeholder="<?= te('leer = Default-Gateway dieses Netzes') ?>">
            </label>
            <span class="muted"><?= te('Nur nötig, wenn ein bestimmtes Ziel erreichbar sein soll (z. B. Internet-Adresse). Leer = das Default-Gateway, das die SSID per DHCP vergibt - ideal für getrennte/isolierte Netze.') ?></span>
        </div>

        <?php $pl = is_array($t['captive_portal_login'] ?? null) ? $t['captive_portal_login'] : []; ?>
        <div class="target-section">
            <div class="target-section-title"><i class="fa-solid fa-gauge-high"></i> <?= te('Durchsatztest (iperf3)') ?></div>
            <label class="check-label">
                <input type="checkbox" name="<?= e($n) ?>[iperf3_enabled]" value="1"
                       <?= ($t['iperf3_enabled'] ?? true) ? 'checked' : '' ?>>
                <?= te('iperf3-Durchsatztest ausführen') ?>
            </label>
            <div class="eap-grid">
                <label><?= te('iperf3-Server') ?>
                    <input type="text" name="<?= e($n) ?>[iperf3_server]"
                           value="<?= e($t['iperf3_server'] ?? '') ?>"
                           placeholder="<?= te('leer = Standard-Server oben (Connection-Tests)') ?>">
                </label>
                <label><?= te('iperf3-Dauer (Sekunden)') ?>
                    <input type="number" min="0" name="<?= e($n) ?>[iperf3_duration_seconds]"
                           value="<?= empty($t['iperf3_duration_seconds']) ? '' : e($t['iperf3_duration_seconds']) ?>"
                           placeholder="<?= te('leer = Standard-Dauer oben') ?>">
                </label>
                <label><?= te('iperf3-Port') ?>
                    <input type="number" min="0" max="65535" name="<?= e($n) ?>[iperf3_port]"
                           value="<?= empty($t['iperf3_port']) ? '' : e($t['iperf3_port']) ?>"
                           placeholder="<?= te('leer = Standard-Port oben') ?>">
                </label>
                <label><?= te('Ratenbegrenzung (Mbit/s)') ?>
                    <input type="number" min="0" step="0.1" name="<?= e($n) ?>[iperf3_bitrate_mbps]"
                           value="<?= empty($t['iperf3_bitrate_mbps']) ? '' : e($t['iperf3_bitrate_mbps']) ?>"
                           placeholder="<?= te('leer = ohne Begrenzung') ?>">
                </label>
                <label class="check-label">
                    <input type="checkbox" name="<?= e($n) ?>[iperf3_download]" value="1"
                           <?= ($t['iperf3_download'] ?? true) ? 'checked' : '' ?>>
                    <?= te('Nach dem Upload auch Download messen') ?>
                </label>
            </div>
            <span class="muted"><?= te('Eigener Server/Dauer/Port nur nötig, wenn diese SSID in ein anderes VLAN mit eigenem iperf3-Server führt - sonst leer lassen (nutzt den Standard oben). Ohne Ratenbegrenzung lastet der Test den Kanal für seine Dauer voll aus (im Tab „Verlauf“ als Last-Zeitraum markiert); mit Begrenzung, z. B. 20 Mbit/s, prüft er, ob diese Rate stabil erreicht wird, und belastet andere Nutzer kaum. Wirkt ab Probe 1.0.1.13.') ?></span>
        </div>

        <div class="target-section">
            <div class="target-section-title"><i class="fa-solid fa-door-open"></i> <?= te('Captive Portal') ?></div>
            <label class="check-label">
                <input type="checkbox" name="<?= e($n) ?>[captive_portal_check]" value="1"
                       <?= !empty($t['captive_portal_check']) ? 'checked' : '' ?>>
                <?= te('Captive Portal prüfen (nach dem DHCP, vor Ping/iperf3)') ?>
            </label>
            <div class="eap-grid">
                <label class="check-label">
                    <input type="checkbox" name="<?= e($n) ?>[portal_login][enabled]" value="1"
                           <?= !empty($pl['enabled']) ? 'checked' : '' ?>>
                    <?= te('Automatisch anmelden (Ping/iperf3 laufen dann erst danach)') ?>
                </label>
                <label><?= te('Portal-Typ') ?>
                    <select name="<?= e($n) ?>[portal_login][type]">
                        <?php foreach ($portalTypes as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($pl['type'] ?? 'auto') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?= te('Portal-Benutzername') ?>
                    <input type="text" name="<?= e($n) ?>[portal_login][username]"
                           value="<?= e($pl['username'] ?? '') ?>" autocomplete="off"
                           placeholder="<?= te('leer bei reinen Nutzungsbedingungen') ?>">
                </label>
                <label><?= te('Portal-Passwort bzw. Access-Code') ?>
                    <?php $pwField($n . '[portal_login][password]', ($pl['password'] ?? '') !== '', __('leer bei reinen Nutzungsbedingungen')); ?>
                </label>
                <label class="check-label">
                    <input type="checkbox" name="<?= e($n) ?>[portal_login][logoff]" value="1"
                           <?= ($pl['logoff'] ?? true) ? 'checked' : '' ?>>
                    <?= te('Nach dem Test vom Portal abmelden') ?>
                </label>
            </div>
        </div>

        <div class="target-section">
            <div class="target-section-title"><i class="fa-solid fa-sliders"></i> <?= te('Sonstiges') ?></div>
            <label class="check-label">
                <input type="checkbox" name="<?= e($n) ?>[random_mac]" value="1"
                       <?= !empty($t['random_mac']) ? 'checked' : '' ?>>
                <?= te('Zufällige MAC-Adresse pro Test (für Captive Portale; nicht bei MAC-gefilterten Netzen oder DHCP-Reservierungen verwenden)') ?>
            </label>
        </div>
    </div>
    <?php
};
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
        <legend><i class="fa-solid fa-magnifying-glass"></i> <?= te('Scan') ?></legend>
        <label>
            <?= te('Scan-Intervall (Sekunden)') ?>
            <input type="number" min="1" name="scan_interval_seconds"
                   value="<?= e($config['scan']['interval_seconds'] ?? 60) ?>">
        </label>
    </fieldset>

    <fieldset>
        <legend><i class="fa-solid fa-plug-circle-check"></i> <?= te('Connection-Tests') ?></legend>
        <label>
            <?= te('Test-Intervall (Sekunden)') ?>
            <input type="number" min="1" name="ct_interval_seconds"
                   value="<?= e($config['connection_tests']['interval_seconds'] ?? 900) ?>">
        </label>
        <label>
            <?= te('Connect-Timeout (Sekunden)') ?>
            <input type="number" min="1" name="ct_connect_timeout_seconds"
                   value="<?= e($config['connection_tests']['connect_timeout_seconds'] ?? 30) ?>">
        </label>
        <label>
            <?= te('Ping-Ziel (Fallback)') ?>
            <input type="text" name="ct_ping_target"
                   value="<?= e($config['connection_tests']['ping_target'] ?? '1.1.1.1') ?>"
                   placeholder="1.1.1.1">
            <span class="muted"><?= te('Wird nur genutzt, wenn eine SSID kein eigenes Ziel hat und kein Gateway ermittelbar ist.') ?></span>
        </label>
        <label>
            <?= te('Ping-Anzahl') ?>
            <input type="number" min="1" name="ct_ping_count"
                   value="<?= e($config['connection_tests']['ping_count'] ?? 5) ?>">
        </label>
        <label>
            <?= te('Captive-Portal-Detection-URL (gilt für alle SSIDs mit aktivierter Portal-Prüfung)') ?>
            <input type="text" name="ct_captive_portal_url"
                   value="<?= e($config['connection_tests']['captive_portal_url'] ?? 'http://connectivitycheck.gstatic.com/generate_204') ?>">
            <span class="muted"><?= te('Muss per http:// erreichbar sein und ohne Portal HTTP 204 liefern.') ?></span>
        </label>
        <label>
            <?= te('iperf3-Server, Standard (leer = deaktiviert)') ?>
            <input type="text" name="ct_iperf3_server"
                   value="<?= e($config['connection_tests']['iperf3_server'] ?? '') ?>">
            <span class="muted"><?= te('Nur der Fallback, wenn eine SSID unten keinen eigenen Server einträgt.') ?></span>
        </label>
        <label>
            <?= te('iperf3-Dauer, Standard (Sekunden)') ?>
            <input type="number" min="1" name="ct_iperf3_duration_seconds"
                   value="<?= e($config['connection_tests']['iperf3_duration_seconds'] ?? 5) ?>">
        </label>
        <label>
            <?= te('iperf3-Port, Standard') ?>
            <input type="number" min="1" max="65535" name="ct_iperf3_port"
                   value="<?= e($config['connection_tests']['iperf3_port'] ?? 5201) ?>">
        </label>
        <label>
            <?= te('iperf3 höchstens alle … Minuten (je SSID)') ?>
            <input type="number" min="0" max="10080" name="ct_iperf3_min_interval_minutes"
                   value="<?= e($config['connection_tests']['iperf3_min_interval_minutes'] ?? 0) ?>">
            <span class="muted"><?= te('0 = bei jedem Test. Verbindung, DHCP, Ping und Portal-Check laufen weiter in jedem Zyklus, nur die Durchsatzmessung wird seltener - sonst zeigt der Verlauf vor allem die Last der eigenen Tests. Empfehlung: 60. Taste 3 am Gerät misst immer mit. Ab Probe 1.0.1.14.') ?></span>
        </label>
        <label class="check-label">
            <input type="checkbox" name="ct_capture_on_failure" value="1"
                   <?= ($config['connection_tests']['capture_on_failure'] ?? true) ? 'checked' : '' ?>>
            <?= te('Fehlgeschlagene Tests mitschneiden') ?>
        </label>
        <p class="muted"><?= te('pcap (EAPOL, DHCP, ARP, DNS, ICMP) und Adapter-Ereignisse (Authentifizierung, Assoziation, Deauth) eines fehlgeschlagenen Verbindungsaufbaus, Download in der Testtabelle. Enthält MAC-Adressen und ggf. 802.1X-Identitäten; Aufbewahrung unter Datenhaltung. pcap braucht tcpdump auf der Probe. Ab Probe 1.0.1.35.') ?></p>
    </fieldset>

    <?php $lan = $config['connection_tests']['iperf3_lan'] ?? []; ?>
    <fieldset>
        <legend><i class="fa-solid fa-ethernet"></i> <?= te('iperf3 über LAN (Upload + Download)') ?></legend>
        <label>
            <input type="checkbox" name="lan_enabled" value="1" <?= !empty($lan['enabled']) ? 'checked' : '' ?>>
            <?= te('LAN-Durchsatztest aktivieren (einmal pro Testzyklus)') ?>
        </label>
        <label>
            <?= te('iperf3-Server (Zieladresse)') ?>
            <input type="text" name="lan_server" placeholder="192.168.1.251"
                   value="<?= e($lan['server'] ?? '192.168.1.251') ?>">
        </label>
        <label>
            <?= te('Interface') ?>
            <input type="text" name="lan_interface"
                   value="<?= e($lan['interface'] ?? 'eth0') ?>">
        </label>
        <label>
            <?= te('Dauer je Richtung (Sekunden)') ?>
            <input type="number" min="1" name="lan_duration_seconds"
                   value="<?= e($lan['duration_seconds'] ?? 5) ?>">
        </label>
        <label>
            <?= te('Port') ?>
            <input type="number" min="1" max="65535" name="lan_port"
                   value="<?= e($lan['port'] ?? 5201) ?>">
        </label>
    </fieldset>

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

    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= te('Konfiguration speichern') ?></button>
</form>

<script>
(function () {
    var container = document.getElementById('targets');
    var template = document.getElementById('targetTemplate');
    var nextIdx = container.children.length;

    // Blendet je nach Sicherheitstyp/EAP-Methode nur die passenden Felder ein.
    function refresh(card) {
        var security = card.querySelector('.target-security').value;
        var method = card.querySelector('.eap-method').value;
        var isEap = security === 'wpa2-eap';
        card.querySelector('.target-psk').style.display =
            (security === 'open' || isEap) ? 'none' : '';
        card.querySelector('.target-eap').style.display = isEap ? '' : 'none';
        card.querySelectorAll('.eap-pw').forEach(function (el) {
            el.style.display = method === 'tls' ? 'none' : '';
        });
        card.querySelectorAll('.eap-ttls').forEach(function (el) {
            el.style.display = method === 'ttls' ? '' : 'none';
        });
        card.querySelectorAll('.eap-tls').forEach(function (el) {
            el.style.display = method === 'tls' ? '' : 'none';
        });
    }

    Array.prototype.forEach.call(container.children, refresh);

    document.getElementById('addTarget').addEventListener('click', function () {
        var wrap = document.createElement('div');
        wrap.innerHTML = template.innerHTML.replace(/__IDX__/g, String(nextIdx++)).trim();
        var card = wrap.firstElementChild;
        container.appendChild(card);
        refresh(card);
    });

    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-target')) {
            e.target.closest('.target-card').remove();
        }
        if (e.target.classList.contains('pw-toggle')) {
            var input = e.target.closest('.pw-field').querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            e.target.textContent = show ? <?= tjson('Verbergen') ?> : <?= tjson('Anzeigen') ?>;
            e.target.setAttribute('aria-label', show ? <?= tjson('Passwort verbergen') ?> : <?= tjson('Passwort anzeigen') ?>);
        }
    });

    container.addEventListener('change', function (e) {
        if (e.target.classList.contains('target-security') || e.target.classList.contains('eap-method')) {
            refresh(e.target.closest('.target-card'));
        }
    });
})();
</script>

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
</body>
</html>
