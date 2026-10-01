<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';

function measurement_insert(string $deviceId, string $kind, ?string $clientTimestamp, array $data): void
{
    // received_at explizit selbst in UTC berechnen statt MySQLs
    // DEFAULT CURRENT_TIMESTAMP zu verwenden - das würde von der
    // Systemzeitzone des DB-Servers abhängen, die wir nicht
    // kontrollieren/kennen. So sind alle Zeitstempel garantiert
    // konsistent UTC, unabhängig von Server-Konfiguration.
    $stmt = db()->prepare(
        'INSERT INTO measurements (device_id, kind, client_timestamp, received_at, data) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $deviceId,
        $kind,
        $clientTimestamp,
        utc_now(),
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

/** Einzelne Messung per ID, mit device_id-Check (siehe measurement_delete()). */
function measurement_find(int $id, string $deviceId): ?array
{
    $stmt = db()->prepare('SELECT * FROM measurements WHERE id = ? AND device_id = ?');
    $stmt->execute([$id, $deviceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function measurement_last(string $deviceId, string $kind): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM measurements WHERE device_id = ? AND kind = ? ORDER BY received_at DESC LIMIT 1'
    );
    $stmt->execute([$deviceId, $kind]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array<int, array<string, mixed>> */
function measurement_list(string $deviceId, string $kind, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT * FROM measurements WHERE device_id = ? AND kind = ? ORDER BY received_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $deviceId, PDO::PARAM_STR);
    $stmt->bindValue(2, $kind, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Löscht eine einzelne Messung. device_id wird bewusst in der WHERE-
 * Klausel mitgeführt (nicht nur die id) - verhindert, dass über die
 * Detailseite eines Geräts versehentlich/absichtlich die ID einer
 * Messung eines ANDEREN Geräts durchgereicht werden könnte.
 */
function measurement_delete(int $id, string $deviceId): void
{
    $stmt = db()->prepare('DELETE FROM measurements WHERE id = ? AND device_id = ?');
    $stmt->execute([$id, $deviceId]);
}

/**
 * Löscht alle Messungen eines Geräts, optional nur eine Art
 * ("scan" oder "connection_test"). $kind === null löscht beides.
 */
function measurement_delete_all(string $deviceId, ?string $kind = null): void
{
    if ($kind !== null) {
        $stmt = db()->prepare('DELETE FROM measurements WHERE device_id = ? AND kind = ?');
        $stmt->execute([$deviceId, $kind]);
        return;
    }
    $stmt = db()->prepare('DELETE FROM measurements WHERE device_id = ?');
    $stmt->execute([$deviceId]);
}

/**
 * Loescht eine per Checkbox ausgewaehlte Teilmenge von Messungen (Bulk-
 * Loeschen in der "Letzte Tests"-Tabelle auf der Geraete-Detailseite).
 * device_id wird wie bei measurement_delete() in der WHERE-Klausel
 * mitgefuehrt. Leeres $ids-Array ist ein No-Op statt eines Fehlers, damit
 * ein versehentlicher Aufruf ohne Auswahl nichts kaputt macht.
 */
function measurement_delete_many(string $deviceId, array $ids): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("DELETE FROM measurements WHERE device_id = ? AND id IN ($placeholders)");
    $stmt->execute([$deviceId, ...$ids]);
}

/**
 * Loescht alle connection_test-Messungen eines Geraets fuer eine
 * bestimmte SSID (SSID-Tab "Alle Connection-Tests löschen" auf der
 * Geraete-Detailseite, nur diese eine SSID statt aller). SSID-Filterung
 * in PHP statt per SQL JSON_EXTRACT - konsistent mit dem Rest des
 * Projekts (siehe device_detail.php), das bewusst keine JSON-SQL-
 * Funktionen voraussetzt (Mindestversion laut README ist MySQL 5.7 /
 * MariaDB 10.2, JSON_EXTRACT ist dort nicht durchgehend garantiert).
 * $ssid === null loescht Messungen ganz ohne (bzw. mit leerem) SSID-Feld
 * im Payload - die Detailseite gruppiert die als "(unbekannt)".
 */
function measurement_delete_all_for_ssid(string $deviceId, ?string $ssid): void
{
    $stmt = db()->prepare("SELECT id, data FROM measurements WHERE device_id = ? AND kind = 'connection_test'");
    $stmt->execute([$deviceId]);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string) $row['data'], true) ?: [];
        $rowSsid = $data['ssid'] ?? null;
        if (($ssid === null && ($rowSsid === null || $rowSsid === '')) || $rowSsid === $ssid) {
            $ids[] = (int) $row['id'];
        }
    }
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $del = db()->prepare("DELETE FROM measurements WHERE device_id = ? AND id IN ($placeholders)");
    $del->execute([$deviceId, ...$ids]);
}
