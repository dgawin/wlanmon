#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Periodischer Abgleich von OmniVista Cirrus in zwei lokale Tabellen
 * (siehe README "OmniVista Cirrus"):
 *   - cirrus_aps: Basis-MAC-Inventar, dient der BSSID -> AP-Name/
 *     Standort-Aufloesung in der Roaming-Kandidaten-Tabelle
 *     (device_detail.php). Die eigentliche Praefix-Zuordnung einer
 *     beliebigen, tatsaechlich gescannten BSSID passiert erst beim
 *     Lesen (siehe cirrus_lookup_ap() in src/Cirrus.php).
 *   - cirrus_ap_radios: aktueller Funkzustand (Kanal/Auslastung/
 *     Rauschen/Sendeleistung) je Radio jedes bekannten APs, fuer die
 *     "Access Points"-Seite im Dashboard - ein Aufruf fuer alle APs auf
 *     einmal, siehe cirrus_fetch_all_ap_radios() in src/Cirrus.php
 *     (nutzt bewusst einen undokumentierten, per Browser-DevTools der
 *     Cirrus-UI gefundenen Endpunkt, da der oeffentlich dokumentierte
 *     Einzel-AP-Endpunkt live immer leer zurueckkam).
 * Kein Live-API-Aufruf pro Seitenaufruf im Dashboard selbst. Ohne
 * konfigurierten "cirrus"-Block in config.php (siehe config.example.php)
 * bricht der Lauf still mit Exit 0 ab - die Integration ist rein optional.
 */

// Gedacht fuer einen periodischen Cronjob (z.B. stuendlich), Beispiel-
// Cron-Zeile (bewusst als "//"-Kommentar, nicht im Docblock oben - ein
// "*/" mitten im Text wuerde dort das Kommentarende vortaeuschen und die
// Datei mit einem Parse-Fehler kaputt machen):
//   0 * * * * php /pfad/zu/sync_cirrus_aps.php >> /var/log/wlanmon-cirrus-sync.log 2>&1

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/Cirrus.php';

function log_line(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

$cfg = cirrus_config();
if ($cfg === null) {
    log_line('cirrus-Block in config.php fehlt/unvollstaendig - nichts zu tun.');
    exit(0);
}

$token = cirrus_authenticate($cfg);
if ($token === null) {
    log_line('Authentifizierung bei Cirrus fehlgeschlagen, breche ab.');
    exit(1);
}

$devices = cirrus_fetch_device_names($cfg, $token);
if ($devices === null) {
    log_line('Geraete-Inventar-Abruf bei Cirrus fehlgeschlagen, breche ab.');
    exit(1);
}

// updated_at explizit in UTC schreiben statt per MySQL-CURRENT_TIMESTAMP
// (Zeitzone des DB-Servers) - format_local() erwartet UTC, sonst wird der
// Sync-Zeitpunkt in der Anzeige um den Zeitzonen-Offset verschoben.
$now = utc_now();
$pdo = db();
$pdo->beginTransaction();
$pdo->exec('DELETE FROM cirrus_aps');
$stmt = $pdo->prepare(
    'INSERT INTO cirrus_aps (mac_address, ap_name, site_name, updated_at) VALUES (?, ?, ?, ?)'
);
foreach ($devices as $mac => $info) {
    $stmt->execute([$mac, $info['ap_name'], $info['site_name'], $now]);
}
$pdo->commit();
log_line(count($devices) . ' Geraet(e) von Cirrus synchronisiert.');

$radioRows = cirrus_fetch_all_ap_radios($cfg, $token);
if ($radioRows === null) {
    log_line('RF-Details-Abruf bei Cirrus fehlgeschlagen, breche ab (cirrus_aps bleibt aktuell).');
    exit(1);
}

$pdo->beginTransaction();
$pdo->exec('DELETE FROM cirrus_ap_radios');
$stmt = $pdo->prepare(
    'INSERT INTO cirrus_ap_radios
        (mac_address, radio, band, channel, channel_utilization, noise_floor_dbm, tx_power, measured_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($radioRows as $r) {
    if ($r['radio'] === null) {
        continue;
    }
    $stmt->execute([
        $r['mac'], $r['radio'], $r['band'], $r['channel'],
        $r['channel_utilization'], $r['noise_floor_dbm'], $r['tx_power'], $r['measured_at'], $now,
    ]);
}
$pdo->commit();

log_line(count($radioRows) . ' Radio(s) von Cirrus synchronisiert.');

// Verlauf für den Tab "Verlauf" der Geräteseite: cirrus_ap_radios hält nur
// den letzten Stand, hier wird jeder Sync zusätzlich angehängt. Tabelle bei
// Bedarf selbst anlegen (identisch zu schema.sql), damit bestehende
// Installationen keinen manuellen Schritt brauchen; älter als 30 Tage weg.
try {
    $pdo->exec(CIRRUS_RADIO_HISTORY_DDL);
    $hist = $pdo->prepare(
        'INSERT IGNORE INTO cirrus_radio_history (mac_address, band, channel, channel_utilization, measured_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stored = 0;
    foreach ($radioRows as $r) {
        if ($r['channel_utilization'] === null) {
            continue;
        }
        $hist->execute([$r['mac'], $r['band'], $r['channel'], $r['channel_utilization'], $r['measured_at'] ?? $now]);
        $stored += $hist->rowCount();
    }
    $pdo->prepare('DELETE FROM cirrus_radio_history WHERE measured_at < ?')
        ->execute([(new DateTime('-30 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
    log_line("$stored Verlaufswert(e) gespeichert.");
} catch (PDOException $e) {
    // Der Verlauf ist Zusatz - der eigentliche Sync war erfolgreich.
    log_line('Verlauf nicht gespeichert: ' . $e->getMessage());
}
exit(0);
