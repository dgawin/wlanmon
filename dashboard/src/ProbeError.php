<?php
declare(strict_types=1);

require_once __DIR__ . '/I18n.php';

/*
 * Fehlermeldungen der Probe aus ihren Fehlercodes formulieren. Die Probe
 * schickt neben dem deutschen Text "error" (fuer ihr eigenes Log) die Liste
 * "error_codes" mit Bausteinen {"code": ..., Parameter ...} - siehe _err()
 * in wifi_ops.py. Hier entsteht daraus der Text in der aktuellen Sprache
 * (Oberflaeche bzw. Alarmsprache des Standorts), ueber die normalen
 * __()-Uebersetzungen in lang/en.php.
 *
 * Neue Codes in der Probe hier ergaenzen. Kennt das Dashboard einen Code
 * nicht (Probe neuer als das Dashboard), zeigt es den deutschen Text der
 * Probe, statt einen halben Satz zu bauen.
 */

/** Fehlertext eines Connection- oder LAN-Tests (Messdaten wie gespeichert). */
function probe_error_text(array $data): string
{
    $codes = $data['error_codes'] ?? null;
    if (!is_array($codes) || $codes === []) {
        return (string) ($data['error'] ?? '');
    }
    $parts = [];
    foreach ($codes as $code) {
        $part = is_array($code) ? probe_error_part($code) : null;
        if ($part === null) {
            return (string) ($data['error'] ?? '');
        }
        $parts[] = $part;
    }
    return implode(': ', $parts);
}

/** Ein Baustein als Text, null bei unbekanntem Code. */
function probe_error_part(array $c): ?string
{
    $s = static fn(string $key): string => (string) ($c[$key] ?? '');
    switch ((string) ($c['code'] ?? '')) {
        // --- Phase, in der der Test scheiterte ---
        case 'assoc_failed':
            return !empty($c['timeout']) ? __('Assoziation fehlgeschlagen (Timeout)') : __('Assoziation fehlgeschlagen');
        case 'auth_8021x_failed':
            return __('802.1X-Authentifizierung fehlgeschlagen');
        case 'key_exchange_failed':
            return __('WPA-Schlüsselaustausch fehlgeschlagen');
        case 'dhcp_timeout':
            return __('Kein DHCP-Lease erhalten (Timeout)') . probe_error_dhcp_detail($c);
        case 'dhcp_client_missing':
            return __('Kein DHCP-Client auf der Probe installiert (dhclient oder dhcpcd) – sudo apt install dhcpcd-base');
        case 'eap_config_invalid':
            return __('802.1X-Angaben unvollständig');
        case 'config_failed':
            return __('WLAN-Konfiguration nicht erzeugbar: %s', $s('detail'));
        case 'internal_error':
            return __('Interner Fehler der Probe: %s', $s('detail'));

        // --- 802.1X-Angaben am Ziel ---
        case 'eap_block_missing':
            return __('Am Ziel fehlen die 802.1X-Angaben (Methode, Benutzername …)');
        case 'eap_method_unknown':
            return __('Unbekannte EAP-Methode „%s“ (erlaubt: peap, ttls, tls)', $s('method'));
        case 'eap_identity_missing':
            return __('Benutzername/Identity fehlt');
        case 'eap_password_missing':
            return __('Passwort fehlt');
        case 'eap_tls_cert_missing':
            return __('EAP-TLS braucht Client-Zertifikat und privaten Schlüssel');
        case 'wpa_cli_missing':
            return __('wpa_cli auf der Probe nicht installiert (Paket wpasupplicant)');

        // --- Ursache aus dem wpa_supplicant-Log ---
        case 'server_cert_rejected':
            return __('Server-Zertifikat nicht akzeptiert: %s', $s('reason'));
        case 'radius_rejected':
            return __('vom RADIUS-Server abgelehnt (Benutzername/Passwort falsch oder Konto gesperrt)');
        case 'radius_timeout':
            return __('keine Antwort vom RADIUS-Server (EAP-Timeout)');
        case 'eap_method_init_failed':
            return __('EAP-Methode konnte auf der Probe nicht initialisiert werden (fehlt MD4/Legacy-Provider in OpenSSL?)');
        case 'tls_handshake_failed':
            return __('TLS-Handshake mit dem RADIUS-Server fehlgeschlagen');
        case 'eap_failure':
            return __('EAP-Authentifizierung fehlgeschlagen (EAP-Failure)');
        case 'eap_rejected_phase2':
            return __('vom RADIUS-Server in Phase 2 abgelehnt (innere Anmeldung: Benutzername/Passwort, Konto oder Richtlinie)');
        case 'sae_password_wrong':
            return __('WPA3-Passwort vermutlich falsch: der AP lehnt die SAE-Bestätigung ab (Status %s)', $s('status'));
        case 'sae_rejected':
            return __('WPA3 (SAE) vom AP abgelehnt (Schritt %s, Status %s) – SAE-Einstellungen prüfen (H2E, Gruppe, PMF)', $s('step'), $s('status'));
        case 'auth_rejected':
            return __('Authentifizierung vom AP abgelehnt (Typ %s, Status %s)', $s('auth_type'), $s('status'));
        case 'psk_wrong':
            return __('PSK vermutlich falsch (4-Way-Handshake fehlgeschlagen)');
        case 'network_disabled':
            return __('Netz von wpa_supplicant vorübergehend gesperrt (%s)', $s('reason'));
        case 'deauth_by_ap':
            return (int) ($c['reason'] ?? 0) === 23
                ? __('vom AP getrennt: 802.1X-Authentifizierung fehlgeschlagen (Deauth-Grund 23)')
                : __('vom AP getrennt (Deauth-Grund %s)', $s('reason'));
        case 'wpa_log':
            return __('wpa_supplicant-Log: %s', $s('text'));

        // --- 802.1X blieb haengen ---
        case 'eap_no_4way':
            return __('EAP erfolgreich, aber 4-Way-Handshake danach nicht abgeschlossen') . probe_error_waited($c);
        case 'eap_stalled_after_cert':
            return ($s('subject') !== ''
                    ? __('Server-Zertifikat erhalten (%s), aber innere Authentifizierung (Phase 2) nicht abgeschlossen (RADIUS-Server antwortet zu langsam oder nicht mehr)', $s('subject'))
                    : __('Server-Zertifikat erhalten, aber innere Authentifizierung (Phase 2) nicht abgeschlossen (RADIUS-Server antwortet zu langsam oder nicht mehr)'))
                . probe_error_waited($c);
        case 'eap_stalled_method':
            return __('EAP-Methode ausgehandelt, aber TLS-Tunnel nicht aufgebaut (Server-Zertifikat nicht vollständig empfangen: RADIUS-Server antwortet zu langsam, unvollständig oder gar nicht)') . probe_error_waited($c);
        case 'eap_stalled_start':
            return __('EAP gestartet, aber keine Methode ausgehandelt (RADIUS-Server antwortet zu langsam oder gar nicht)') . probe_error_waited($c);

        // --- Netz nicht gefunden / unpassend ---
        case 'ssid_not_found':
            return __('SSID „%s“ nicht gefunden: im Scan während des Tests nicht gesehen (außer Reichweite, anderes Band oder Name weicht ab)', $s('ssid'));
        case 'security_mismatch':
            $seen = implode(', ', probe_error_bands((array) ($c['frequencies_mhz'] ?? [])));
            if (isset($c['signal_dbm'])) {
                $seen .= ($seen !== '' ? ', ' : '') . __('bestes Signal %s dBm', $s('signal_dbm'));
            }
            $network = implode('; ', array_map('strval', (array) ($c['network'] ?? [])))
                . (!empty($c['pmf_required']) ? ', ' . __('PMF erforderlich') : '');
            return __('SSID „%s“ gefunden (%s), aber kein Verbindungsversuch – Sicherheitstyp passt vermutlich nicht: konfiguriert %s, Netz meldet %s', $s('ssid'), $seen, $s('configured'), $network);

        // --- iperf3 / LAN-Test ---
        case 'iperf3_missing':
            return __('iperf3 auf der Probe nicht installiert');
        case 'no_ipv4':
            return __('Interface %s hat keine IPv4-Adresse', $s('interface'));
        case 'lan_upload_failed':
            return __('Upload');
        case 'lan_download_failed':
            return __('Download');
        case 'iperf3_timeout':
            return __('iperf3: Zeitüberschreitung');
        case 'iperf3_no_output':
            return __('iperf3: keine Ausgabe');
        case 'iperf3_no_result':
            return __('iperf3: Ausgabe ohne Ergebnis');
        case 'iperf3_busy':
            // Andere Probe misst gerade am selben Server - kein Netzfehler.
            return __('iperf3-Server belegt (%s Versuche), Messung im nächsten Zyklus', $s('attempts'));
        case 'iperf3_failed':
            // Meldung von iperf3 selbst (englisch); "the server is busy ..."
            // nur noch von Probes vor 1.0.1.52 (danach iperf3_busy).
            return isset($c['attempts'])
                ? __('%s (%s Versuche im Abstand von %s s)', $s('detail'), $s('attempts'), $s('wait_s'))
                : $s('detail');
    }
    return null;
}

/** " – nach 22 s ohne Ergebnis abgebrochen", wenn die Probe die Wartezeit mitschickt. */
function probe_error_waited(array $c): string
{
    return isset($c['waited_s']) ? ' – ' . __('nach %s s ohne Ergebnis abgebrochen', (string) $c['waited_s']) : '';
}

/** " [AP …, -58 dBm, 5180 MHz, während DHCP Rx 3 / Tx 7 Pakete]" */
function probe_error_dhcp_detail(array $c): string
{
    $parts = [];
    if (!empty($c['link_down'])) {
        $parts[] = __('Link bereits getrennt');
    }
    if (!empty($c['bssid'])) {
        $parts[] = 'AP ' . $c['bssid'];
    }
    if (isset($c['signal_dbm'])) {
        $parts[] = $c['signal_dbm'] . ' dBm';
    }
    if (!empty($c['frequency_mhz'])) {
        $parts[] = $c['frequency_mhz'] . ' MHz';
    }
    if (isset($c['rx_packets'], $c['tx_packets'])) {
        $parts[] = __('während DHCP Rx %s / Tx %s Pakete', (string) $c['rx_packets'], (string) $c['tx_packets']);
    }
    return $parts === [] ? '' : ' [' . implode(', ', $parts) . ']';
}

/**
 * Frequenzen zu Bändern, z.B. [2437, 5180, 5220] -> ["2,4 GHz", "5 GHz"].
 *
 * @param array<int, mixed> $frequencies
 * @return string[]
 */
function probe_error_bands(array $frequencies): array
{
    $bands = [];
    foreach ($frequencies as $f) {
        $f = (int) $f;
        $bands[$f < 3000 ? 0 : ($f < 5925 ? 1 : 2)] = true;
    }
    ksort($bands);
    $labels = [0 => __('2,4 GHz'), 1 => '5 GHz', 2 => '6 GHz'];
    return array_map(static fn(int $b): string => $labels[$b], array_keys($bands));
}
