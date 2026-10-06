<?php
/*
 * Gemeinsames Markup einer Ziel-SSID (Sicherheit, PSK/802.1X, Ping, iperf3,
 * Captive Portal, zufällige MAC) für die Gerätekonfiguration (Liste von
 * Karten) und die SSID-Seite (eine Karte). Definiert $pwField und
 * $renderTarget; das zugehörige Skript steht in _target_js.php.
 */

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
$renderTarget = function (string $idx, array $t, ?string $name = null) use ($securityOptions, $portalTypes, $pwField): void {
    $security = $t['security'] ?? 'wpa2-psk';
    $eap = is_array($t['eap'] ?? null) ? $t['eap'] : [];
    $method = $eap['method'] ?? 'peap';
    $phase2 = $eap['phase2'] ?? 'PAP';
    $verify = array_key_exists('verify_server', $eap) ? !empty($eap['verify_server']) : true;
    // In der Liste (Gerät) "targets[<idx>]", als einzelne SSID (Seite /ssids) z.B. "target".
    $n = $name ?? 'targets[' . $idx . ']';
    $inList = $name === null;
    ?>
    <div class="target-card">
        <?php if ($inList && $idx !== '__IDX__'): ?>
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
            <?php if ($inList): ?>
            <button type="button" class="remove-target btn-remove"><i class="fa-solid fa-trash-can"></i> <?= te('Entfernen') ?></button>
            <?php endif; ?>
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
