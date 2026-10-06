<?php
/*
 * Testeinstellungen (Scan, Heartbeat, Connection-Tests, LAN-Test) für die
 * Gerätekonfiguration und die Profile. Erwartet $config (gespeicherte Form);
 * gelesen von test_settings_from_post() in public/index.php.
 */
/** @var array $config */
?>
    <fieldset>
        <legend><i class="fa-solid fa-magnifying-glass"></i> <?= te('Scan') ?></legend>
        <label>
            <?= te('Scan-Intervall (Sekunden)') ?>
            <input type="number" min="1" name="scan_interval_seconds"
                   value="<?= e($config['scan']['interval_seconds'] ?? 60) ?>">
        </label>
        <label>
            <?= te('Scans je Durchlauf') ?>
            <input type="number" min="1" max="5" name="scan_passes"
                   value="<?= e($config['scan']['passes'] ?? 2) ?>">
            <span class="muted"><?= te('Mehrere Scans direkt hintereinander, zusammengeführt. Ein einzelner Scan verpasst schnell ein paar Netze, die Anzahl schwankt dann stark; 2 glättet das und dauert nur wenige Sekunden länger. Ab Probe 1.0.1.61.') ?></span>
        </label>
    </fieldset>

    <?php $hb = is_array($config['heartbeat'] ?? null) ? $config['heartbeat'] : []; ?>
    <fieldset>
        <legend><i class="fa-solid fa-heart-pulse"></i> <?= te('Heartbeat und Systemwerte') ?></legend>
        <label class="check-label">
            <input type="checkbox" name="hb_enabled" value="1" <?= ($hb['enabled'] ?? true) ? 'checked' : '' ?>>
            <?= te('Heartbeat senden') ?>
        </label>
        <label>
            <?= te('Intervall') ?>
            <select name="hb_interval_seconds">
                <?php foreach (HEARTBEAT_INTERVALS as $sec): ?>
                    <option value="<?= $sec ?>" <?= (int) ($hb['interval_seconds'] ?? 60) === $sec ? 'selected' : '' ?>>
                        <?= $sec < 60 ? te('%d Sekunden', $sec) : ($sec === 60 ? te('1 Minute') : te('%d Minuten', intdiv($sec, 60))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="muted"><?= te('Die Probe meldet sich im festen Takt, auch wenn gerade keine Messung ansteht, und schickt dabei ihre Systemwerte mit: CPU, Arbeitsspeicher, Speicherplatz, Temperatur, Laufzeit und noch nicht übertragene Messungen. So ist der Online-Status unabhängig vom Messtakt, und Änderungen an dieser Konfiguration kommen sofort an statt nach bis zu 5 Minuten. Ab Probe 1.0.1.54.') ?></p>
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
            <span class="muted"><?= te('0 = bei jedem Test. Verbindung, DHCP, Ping und Portal-Check laufen weiter in jedem Zyklus, nur die Durchsatzmessung wird seltener - sonst zeigt der Verlauf vor allem die Last der eigenen Tests, und mehrere Probes am selben iperf3-Server blockieren sich gegenseitig. Empfehlung: 60. Taste 3 am Gerät misst immer mit. Gilt auch für den LAN-Test, solange dort kein eigener Abstand eingetragen ist.') ?></span>
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
            <?= te('LAN-Durchsatztest aktivieren') ?>
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
        <label>
            <?= te('LAN-Test höchstens alle … Minuten') ?>
            <input type="number" min="0" max="10080" name="lan_min_interval_minutes" placeholder="<?= te('wie oben') ?>"
                   value="<?= isset($lan['min_interval_minutes']) ? e((int) $lan['min_interval_minutes']) : '' ?>">
            <span class="muted"><?= te('0 = in jedem Testzyklus, leer = wie „iperf3 höchstens alle … Minuten“ oben. Messen mehrere Probes gegen denselben iperf3-Server, blockieren sie sich bei zu kurzem Abstand gegenseitig – z. B. 15 oder 30. Ab Probe 1.0.1.53.') ?></span>
        </label>
    </fieldset>
