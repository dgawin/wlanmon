<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Capture.php';

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

/*
 * Grenzen fuer /api/v1/measurements. Eine Probe schickt hoechstens
 * batch_size (Vorgabe 50) Messungen je flush_interval_seconds (Vorgabe 15 s),
 * also ~1000 in 5 Minuten, auch beim Abarbeiten einer langen Warteschlange
 * nach einem Ausfall. Die Grenzen liegen deutlich darueber und greifen nur
 * bei einer fehlkonfigurierten oder missbrauchten Probe (geleakter API-Key).
 * Abgelehnte Batches bleiben in der Warteschlange der Probe und kommen im
 * naechsten Zyklus erneut - es geht nichts verloren.
 */
const INGEST_MAX_BODY_BYTES = 8 * 1024 * 1024;
const INGEST_MAX_BATCH = 500;
const INGEST_RATE_WINDOW_SECONDS = 300;
const INGEST_RATE_MAX_MEASUREMENTS = 3000;

/** Anzahl der Messungen, die ein Geraet in den letzten $seconds Sekunden geliefert hat (Rate-Limit). */
function measurement_count_recent(string $deviceId, int $seconds): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM measurements WHERE device_id = ? AND received_at >= ?');
    $stmt->execute([$deviceId, gmdate('Y-m-d H:i:s', time() - $seconds)]);
    return (int) $stmt->fetchColumn();
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
 * Messungen eines Geraets seit $sinceUtc nach Messzeitpunkt der Probe
 * (client_timestamp, sonst received_at) - eine nach einem Ausfall gesammelt
 * nachgereichte Warteschlange zaehlt so zu ihrer echten Zeit.
 *
 * @return array<int, array<string, mixed>>
 */
function measurement_list_since(string $deviceId, string $kind, string $sinceUtc): array
{
    $stmt = db()->prepare(
        'SELECT * FROM measurements WHERE device_id = ? AND kind = ?
           AND COALESCE(client_timestamp, received_at) >= ? ORDER BY received_at DESC'
    );
    $stmt->execute([$deviceId, $kind, $sinceUtc]);
    return $stmt->fetchAll();
}

/**
 * Kennzahlen über Connection-Tests (neueste zuerst, wie aus measurement_list()):
 * wie viele SSIDs im jeweils neuesten Test verbunden waren, Tests gesamt /
 * erfolgreich und die Durchschnittsdauern (nur über Tests, in denen der Wert
 * vorlag - 802.1X z.B. nur bei EAP-SSIDs).
 *
 * @param array<int, array<string, mixed>> $tests
 * @return array{latest_per_ssid: array<string, bool>, ssid_ok: int, ssid_total: int, test_count: int, test_ok: int,
 *               avg_assoc: ?float, avg_auth: ?float, avg_dhcp: ?float}
 */
function connection_test_summary(array $tests): array
{
    $latestPerSsid = [];
    $testOk = 0;
    $sums = ['assoc' => [], 'auth' => [], 'dhcp' => []];
    foreach ($tests as $t) {
        $data = json_decode((string) $t['data'], true) ?: [];
        $connected = !empty($data['connected']);
        $testOk += $connected ? 1 : 0;
        $ssid = $data['ssid'] ?? null;
        if ($ssid !== null && !isset($latestPerSsid[$ssid])) {
            $latestPerSsid[$ssid] = $connected;
        }
        foreach ($sums as $key => $_) {
            if (isset($data[$key . '_seconds'])) {
                $sums[$key][] = (float) $data[$key . '_seconds'];
            }
        }
    }
    $avg = fn(array $v): ?float => $v === [] ? null : array_sum($v) / count($v);
    return [
        'latest_per_ssid' => $latestPerSsid,
        'ssid_ok' => count(array_filter($latestPerSsid)),
        'ssid_total' => count($latestPerSsid),
        'test_count' => count($tests),
        'test_ok' => $testOk,
        'avg_assoc' => $avg($sums['assoc']),
        'avg_auth' => $avg($sums['auth']),
        'avg_dhcp' => $avg($sums['dhcp']),
    ];
}

/**
 * Löscht eine einzelne Messung. device_id wird bewusst in der WHERE-
 * Klausel mitgeführt (nicht nur die id) - verhindert, dass über die
 * Detailseite eines Geräts versehentlich/absichtlich die ID einer
 * Messung eines ANDEREN Geräts durchgereicht werden könnte.
 */
function measurement_delete(int $id, string $deviceId): void
{
    measurement_delete_captures($deviceId, 'id = ?', [$id]);
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
        if ($kind === 'connection_test') {
            // alle Mitschnitte des Geraets, auch frueher verwaiste
            captures_delete_for_device($deviceId);
        }
        $stmt = db()->prepare('DELETE FROM measurements WHERE device_id = ? AND kind = ?');
        $stmt->execute([$deviceId, $kind]);
        return;
    }
    captures_delete_for_device($deviceId);
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
    measurement_delete_captures($deviceId, "id IN ($placeholders)", $ids);
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
    $captureIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string) $row['data'], true) ?: [];
        $rowSsid = $data['ssid'] ?? null;
        if (($ssid === null && ($rowSsid === null || $rowSsid === '')) || $rowSsid === $ssid) {
            $ids[] = (int) $row['id'];
            if (is_string($data['capture_id'] ?? null)) {
                $captureIds[] = $data['capture_id'];
            }
        }
    }
    if ($ids === []) {
        return;
    }
    captures_delete_ids($deviceId, $captureIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $del = db()->prepare("DELETE FROM measurements WHERE device_id = ? AND id IN ($placeholders)");
    $del->execute([$deviceId, ...$ids]);
}

/**
 * Loescht vor dem Loeschen von Messungen deren Mitschnitte (pcap, Ereignisse,
 * wpa_supplicant-Log). Kein Fremdschluessel moeglich, weil die Probe den
 * Mitschnitt erst nach der Messung hochlaedt - der Verweis steht nur als
 * capture_id im JSON. Nur connection_test-Messungen haben Mitschnitte, Scans
 * werden deshalb gar nicht erst geladen. $where bezieht sich auf die
 * Tabelle measurements und wird mit device_id und kind UND-verknuepft.
 */
function measurement_delete_captures(string $deviceId, string $where, array $params): void
{
    $stmt = db()->prepare(
        "SELECT data FROM measurements WHERE device_id = ? AND kind = 'connection_test' AND ($where)"
    );
    $stmt->execute([$deviceId, ...$params]);
    $captureIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $data = json_decode((string) $json, true) ?: [];
        if (is_string($data['capture_id'] ?? null)) {
            $captureIds[] = $data['capture_id'];
        }
    }
    captures_delete_ids($deviceId, $captureIds);
}
