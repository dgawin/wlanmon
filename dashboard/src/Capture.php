<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/*
 * Mitschnitte fehlgeschlagener Connection-Tests (Probe: FailureCapture in
 * wifi_ops.py). Je Mitschnitt ein pcap (EAPOL, DHCP, ARP, DNS, ICMP auf dem
 * Test-Interface) und die Kernel-Ereignisse aus "iw event" (Authentication,
 * Association, Deauth mit Status-/Reason-Code - die 802.11-Rahmen selbst
 * sieht tcpdump im Client-Modus nicht), dazu das Log von wpa_supplicant
 * (EAP-Methode, Server-Zertifikat, Abbruchgrund aus Client-Sicht).
 *
 * Die Probe schickt die capture_id im Testergebnis mit und laedt die Dateien
 * danach separat hoch (POST /api/v1/devices/<id>/captures/<capture_id>).
 * Gespeichert in der Datenbank (Tabelle "captures"), damit Backup und
 * Aufbewahrung (Retention.php, Standard 30 Tage) ohne eigenes Verzeichnis
 * greifen. Enthalten MAC-Adressen und ggf. 802.1X-Identitaeten - herunterladen
 * duerfen nur Admin und User mit Zugriff auf das Geraet.
 */

/** Etwas ueber der Grenze der Probe (1,5 MB), aber unter upload_max_filesize (2 MB). */
const CAPTURE_MAX_PCAP_BYTES = 2000000;
const CAPTURE_MAX_EVENTS_BYTES = 262144;
const CAPTURE_MAX_WPA_LOG_BYTES = 262144;

/**
 * Legt die Tabelle bei Bedarf an - bestehende klassische Installationen
 * bekommen sie so beim ersten Mitschnitt, ohne schema.sql neu einzuspielen.
 */
function captures_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS captures (
            id          CHAR(32) NOT NULL PRIMARY KEY,
            device_id   VARCHAR(191) NOT NULL,
            created_at  DATETIME NOT NULL,
            pcap        MEDIUMBLOB NULL,
            pcap_size   INT UNSIGNED NOT NULL DEFAULT 0,
            events      MEDIUMTEXT NULL,
            wpa_log     MEDIUMTEXT NULL,
            KEY idx_captures_device (device_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Spalte wpa_log kam mit Dashboard 1.0.1.49 dazu - in schon angelegten
    // Tabellen nachziehen.
    try {
        db()->query('SELECT wpa_log FROM captures LIMIT 0');
    } catch (PDOException $e) {
        db()->exec('ALTER TABLE captures ADD COLUMN wpa_log MEDIUMTEXT NULL');
    }
    $done = true;
}

function capture_id_valid(string $id): bool
{
    return (bool) preg_match('/^[0-9a-f]{32}$/', $id);
}

/** pcap (beide Byte-Reihenfolgen, Mikro-/Nanosekunden) oder pcapng. */
function capture_is_pcap(string $data): bool
{
    $magic = substr($data, 0, 4);
    return in_array($magic, ["\xd4\xc3\xb2\xa1", "\xa1\xb2\xc3\xd4", "\x4d\x3c\xb2\xa1", "\xa1\xb2\x3c\x4d", "\x0a\x0d\x0d\x0a"], true);
}

/**
 * Speichert einen Mitschnitt. Ein erneuter Upload derselben capture_id (die
 * Probe wiederholt, wenn die Antwort verloren ging) ueberschreibt nur, wenn
 * er vom selben Geraet kommt.
 */
function capture_store(string $id, string $deviceId, ?string $pcap, string $events, ?string $wpaLog = null): void
{
    captures_ensure_table();
    $stmt = db()->prepare(
        'INSERT INTO captures (id, device_id, created_at, pcap, pcap_size, events, wpa_log)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            pcap = IF(device_id = VALUES(device_id), VALUES(pcap), pcap),
            pcap_size = IF(device_id = VALUES(device_id), VALUES(pcap_size), pcap_size),
            events = IF(device_id = VALUES(device_id), VALUES(events), events),
            wpa_log = IF(device_id = VALUES(device_id), VALUES(wpa_log), wpa_log)'
    );
    $stmt->bindValue(1, $id);
    $stmt->bindValue(2, $deviceId);
    $stmt->bindValue(3, $pcap, $pcap === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $stmt->bindValue(4, $pcap === null ? 0 : strlen($pcap), PDO::PARAM_INT);
    $stmt->bindValue(5, $events);
    $stmt->bindValue(6, $wpaLog, $wpaLog === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();
}

/** @return array<string, mixed>|null inkl. pcap und events */
function capture_find(string $id, string $deviceId): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM captures WHERE id = ? AND device_id = ?');
        $stmt->execute([$id, $deviceId]);
    } catch (PDOException $e) {
        return null; // Tabelle gibt es erst nach dem ersten Mitschnitt
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Welche der capture_ids (aus den Testergebnissen) auf dem Server liegen -
 * fuer die Download-Links in der Testtabelle, ohne die Dateien zu laden.
 *
 * @param string[] $ids
 * @return array<string, array{pcap_size: int, has_events: bool, has_wpa_log: bool}>
 */
function captures_existing(string $deviceId, array $ids): array
{
    $ids = array_values(array_filter(array_unique($ids), 'capture_id_valid'));
    if ($ids === []) {
        return [];
    }
    try {
        $stmt = db()->prepare(
            'SELECT id, pcap_size, events IS NOT NULL AND events <> \'\' AS has_events,
                    wpa_log IS NOT NULL AND wpa_log <> \'\' AS has_wpa_log FROM captures
             WHERE device_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([$deviceId], $ids));
    } catch (PDOException $e) {
        return [];
    }
    $found = [];
    foreach ($stmt->fetchAll() as $row) {
        $found[(string) $row['id']] = [
            'pcap_size' => (int) $row['pcap_size'],
            'has_events' => (bool) $row['has_events'],
            'has_wpa_log' => (bool) $row['has_wpa_log'],
        ];
    }
    return $found;
}

/**
 * Loescht die Mitschnitte geloeschter Messungen - ohne Messung gibt es
 * keinen Download-Link mehr, sie laegen sonst bis zur Aufbewahrungsgrenze
 * unerreichbar in der Datenbank.
 *
 * @param string[] $ids
 */
function captures_delete_ids(string $deviceId, array $ids): void
{
    $ids = array_values(array_filter(array_unique($ids), 'capture_id_valid'));
    if ($ids === []) {
        return;
    }
    try {
        db()->prepare(
            'DELETE FROM captures WHERE device_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        )->execute(array_merge([$deviceId], $ids));
    } catch (PDOException $e) {
        // keine Tabelle = nichts zu loeschen
    }
}

function captures_delete_for_device(string $deviceId): void
{
    try {
        db()->prepare('DELETE FROM captures WHERE device_id = ?')->execute([$deviceId]);
    } catch (PDOException $e) {
        // keine Tabelle = nichts zu loeschen
    }
}
