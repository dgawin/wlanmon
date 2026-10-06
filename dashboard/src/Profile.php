<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Secrets.php';

/*
 * SSIDs und Konfigurationsprofile (Seiten /ssids und /profiles).
 *
 * - Eine SSID (Tabelle ssids) ist ein Ziel-WLAN mit allem, was die Probe dafür
 *   braucht: Sicherheit, PSK bzw. 802.1X, Captive Portal, iperf3, Ping-Ziel,
 *   zufällige MAC. Gespeichert im selben Format wie ein Eintrag in
 *   connection_tests.targets, Geheimnisse verschlüsselt (src/Secrets.php).
 * - Ein Profil (Tabelle profiles) bündelt ausgewählte SSIDs (profile_ssids)
 *   mit den Testeinstellungen: Scan, Heartbeat, Connection-Tests (Intervalle,
 *   Ping, iperf3-Standards, LAN-Test, Mitschnitte).
 * - Ein Gerät nutzt entweder ein Profil (devices.profile_id) oder seine eigene
 *   Konfiguration (devices.config, unverändert wie bisher). Die eigene bleibt
 *   beim Wechsel aufs Profil erhalten, ein Zurückschalten verliert nichts.
 *
 * Die Probe merkt davon nichts: device_effective_config() baut beim Abruf
 * dieselbe flache Konfiguration wie bisher zusammen.
 *
 * Zuständigkeit: site_id NULL = global (nur Admins pflegen), sonst gehört die
 * SSID bzw. das Profil zu einem Standort und dessen User dürfen sie pflegen.
 * Ein Profil darf globale SSIDs und die seines Standorts enthalten, ein
 * Gerät globale Profile und die seines Standorts nutzen.
 */

function profiles_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    // Normalfall: alles da - eine billige Abfrage statt CREATE TABLE (DDL holt
    // sich Metadaten-Sperren und ist auf einem gut ausgelasteten Server teuer).
    try {
        $pdo->query('SELECT d.profile_id, ps.position FROM devices d, profile_ssids ps LIMIT 0');
        $done = true;
        return;
    } catch (PDOException $e) {
        // Erster Aufruf: Tabellen bzw. Spalte anlegen.
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS ssids (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id     INT UNSIGNED NULL,
        label       VARCHAR(100) NULL,
        target      MEDIUMTEXT NOT NULL,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ssids_site (site_id),
        CONSTRAINT fk_ssids_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS profiles (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        site_id     INT UNSIGNED NULL,
        name        VARCHAR(100) NOT NULL,
        settings    MEDIUMTEXT NOT NULL,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_profiles_site (site_id),
        CONSTRAINT fk_profiles_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS profile_ssids (
        profile_id  INT UNSIGNED NOT NULL,
        ssid_id     INT UNSIGNED NOT NULL,
        position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (profile_id, ssid_id),
        CONSTRAINT fk_profile_ssids_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
        CONSTRAINT fk_profile_ssids_ssid FOREIGN KEY (ssid_id) REFERENCES ssids(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    try {
        $pdo->query('SELECT profile_id FROM devices LIMIT 0');
    } catch (PDOException $e) {
        $pdo->exec('ALTER TABLE devices ADD COLUMN profile_id INT UNSIGNED NULL AFTER config');
    }
    $done = true;
}

// ---- Zuständigkeit ---------------------------------------------------------

/** Darf $user eine SSID/ein Profil mit diesem site_id sehen (auswählen)? */
function scope_visible(array $user, ?int $siteId): bool
{
    return $user['role'] === 'admin' || $siteId === null || user_can_access_site($user, $siteId);
}

/** Darf $user eine SSID/ein Profil mit diesem site_id ändern? */
function scope_editable(array $user, ?int $siteId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    return $user['role'] === 'user' && $siteId !== null && user_can_access_site($user, $siteId);
}

/** Standorte, denen $user neue SSIDs/Profile zuordnen darf (Admin: alle). */
function scope_site_choices(array $user): array
{
    $sites = site_list();
    if ($user['role'] === 'admin') {
        return $sites;
    }
    $mine = user_site_ids((int) $user['id']);
    return array_values(array_filter($sites, fn(array $s): bool => in_array((int) $s['id'], $mine, true)));
}

function scope_site_id($value): ?int
{
    return $value === null || $value === '' ? null : (int) $value;
}

// ---- SSIDs -------------------------------------------------------------------

/** Anzeigename: SSID, bei gesetztem Label "SSID (Label)". */
function ssid_display(array $row): string
{
    $target = json_decode((string) $row['target'], true) ?: [];
    $name = (string) ($target['ssid'] ?? '?');
    return ($row['label'] ?? '') !== '' ? $name . ' (' . $row['label'] . ')' : $name;
}

/** @return array<int, array<string, mixed>> SSIDs, die $user sehen darf, mit Standortname und Nutzung. */
function ssid_list(array $user): array
{
    profiles_ensure_tables();
    $rows = db()->query(
        'SELECT s.*, st.name AS site_name,
                (SELECT COUNT(*) FROM profile_ssids ps WHERE ps.ssid_id = s.id) AS profile_count
         FROM ssids s LEFT JOIN sites st ON st.id = s.site_id'
    )->fetchAll();
    $rows = array_values(array_filter($rows, fn(array $r): bool => scope_visible($user, scope_site_id($r['site_id']))));
    usort($rows, fn(array $a, array $b): int => [(string) ($a['site_name'] ?? ''), strtolower(ssid_display($a))]
        <=> [(string) ($b['site_name'] ?? ''), strtolower(ssid_display($b))]);
    return $rows;
}

function ssid_find(int $id): ?array
{
    profiles_ensure_tables();
    $stmt = db()->prepare('SELECT s.*, st.name AS site_name FROM ssids s LEFT JOIN sites st ON st.id = s.site_id WHERE s.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Speichert eine SSID (neu, wenn $id null) und gibt ihre ID zurück. Geheimnisse werden verschlüsselt. */
function ssid_save(?int $id, ?int $siteId, string $label, array $target): int
{
    profiles_ensure_tables();
    $json = json_encode(secrets_map($target, TARGET_SECRET_PATHS, 'secret_encrypt'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $label = $label !== '' ? $label : null;
    if ($id === null) {
        db()->prepare('INSERT INTO ssids (site_id, label, target) VALUES (?, ?, ?)')->execute([$siteId, $label, $json]);
        return (int) db()->lastInsertId();
    }
    db()->prepare('UPDATE ssids SET site_id = ?, label = ?, target = ? WHERE id = ?')->execute([$siteId, $label, $json, $id]);
    return $id;
}

/**
 * Vergleichsschlüssel für den Inhalt einer SSID: Geheimnisse entschlüsselt
 * (verschlüsselte Werte unterscheiden sich bei jedem Speichern), Schlüssel
 * sortiert. Nicht entschlüsselbare Werte bleiben wie gespeichert.
 */
function ssid_content_key(array $target): string
{
    $plain = secrets_map($target, TARGET_SECRET_PATHS, static fn(string $v): string => secret_decrypt($v) ?? $v);
    $sort = static function (array $a) use (&$sort): array {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $sort($v);
            }
        }
        return $a;
    };
    return (string) json_encode($sort($plain), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ssid_delete(int $id): void
{
    profiles_ensure_tables();
    db()->prepare('DELETE FROM ssids WHERE id = ?')->execute([$id]);
}

/** @return array<int, array<string, mixed>> Profile, die diese SSID enthalten. */
function ssid_profiles(int $id): array
{
    profiles_ensure_tables();
    $stmt = db()->prepare('SELECT p.id, p.name FROM profile_ssids ps JOIN profiles p ON p.id = ps.profile_id WHERE ps.ssid_id = ? ORDER BY p.name');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

// ---- Profile ----------------------------------------------------------------------

/** @return array<int, array<string, mixed>> Profile, die $user sehen darf, mit Standortname, SSID- und Geräteanzahl. */
function profile_list(array $user): array
{
    profiles_ensure_tables();
    $rows = db()->query(
        'SELECT p.*, st.name AS site_name,
                (SELECT COUNT(*) FROM profile_ssids ps WHERE ps.profile_id = p.id) AS ssid_count,
                (SELECT COUNT(*) FROM devices d WHERE d.profile_id = p.id) AS device_count
         FROM profiles p LEFT JOIN sites st ON st.id = p.site_id'
    )->fetchAll();
    $rows = array_values(array_filter($rows, fn(array $r): bool => scope_visible($user, scope_site_id($r['site_id']))));
    usort($rows, fn(array $a, array $b): int => [(string) ($a['site_name'] ?? ''), strtolower((string) $a['name'])]
        <=> [(string) ($b['site_name'] ?? ''), strtolower((string) $b['name'])]);
    return $rows;
}

function profile_find(int $id): ?array
{
    profiles_ensure_tables();
    $stmt = db()->prepare('SELECT p.*, st.name AS site_name FROM profiles p LEFT JOIN sites st ON st.id = p.site_id WHERE p.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return int[] SSID-IDs des Profils in ihrer Reihenfolge. */
function profile_ssid_ids(int $profileId): array
{
    profiles_ensure_tables();
    $stmt = db()->prepare('SELECT ssid_id FROM profile_ssids WHERE profile_id = ? ORDER BY position, ssid_id');
    $stmt->execute([$profileId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @param int[] $ssidIds in gewünschter Reihenfolge */
function profile_save(?int $id, ?int $siteId, string $name, array $settings, array $ssidIds): int
{
    profiles_ensure_tables();
    $pdo = db();
    $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $pdo->prepare('INSERT INTO profiles (site_id, name, settings) VALUES (?, ?, ?)')->execute([$siteId, $name, $json]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE profiles SET site_id = ?, name = ?, settings = ? WHERE id = ?')->execute([$siteId, $name, $json, $id]);
            $pdo->prepare('DELETE FROM profile_ssids WHERE profile_id = ?')->execute([$id]);
        }
        $insert = $pdo->prepare('INSERT INTO profile_ssids (profile_id, ssid_id, position) VALUES (?, ?, ?)');
        foreach (array_values(array_unique($ssidIds)) as $pos => $ssidId) {
            $insert->execute([$id, $ssidId, $pos]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function profile_delete(int $id): void
{
    profiles_ensure_tables();
    db()->prepare('DELETE FROM profiles WHERE id = ?')->execute([$id]);
}

/** @return array<int, array<string, mixed>> Geräte, die dieses Profil nutzen. */
function profile_devices(int $id): array
{
    profiles_ensure_tables();
    $stmt = db()->prepare('SELECT id, site_id FROM devices WHERE profile_id = ? ORDER BY id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

/** Darf ein Profil mit $profileSiteId diese SSID enthalten (global oder gleicher Standort)? */
function profile_may_use_ssid(?int $profileSiteId, array $ssid): bool
{
    $ssidSite = scope_site_id($ssid['site_id']);
    return $ssidSite === null || $ssidSite === $profileSiteId;
}

/** Darf ein Gerät an $deviceSiteId dieses Profil nutzen (global oder gleicher Standort)? */
function device_may_use_profile(?int $deviceSiteId, array $profile): bool
{
    $profileSite = scope_site_id($profile['site_id']);
    return $profileSite === null || $profileSite === $deviceSiteId;
}

function device_set_profile(string $deviceId, ?int $profileId): void
{
    profiles_ensure_tables();
    db()->prepare('UPDATE devices SET profile_id = ? WHERE id = ?')->execute([$profileId, $deviceId]);
}

// ---- Wirksame Konfiguration ---------------------------------------------------

/**
 * Konfiguration, die für das Gerät gilt (Geheimnisse wie gespeichert, ggf.
 * verschlüsselt): aus dem zugewiesenen Profil zusammengebaut, sonst die eigene.
 * null = keine zentrale Konfiguration. Ein zugewiesenes, aber inzwischen
 * gelöschtes Profil fällt auf die eigene Konfiguration zurück.
 */
function device_effective_config(array $device): ?array
{
    $profileId = isset($device['profile_id']) ? (int) $device['profile_id'] : 0;
    if ($profileId > 0) {
        $config = profile_build_config($profileId);
        if ($config !== null) {
            return $config;
        }
    }
    if (empty($device['config'])) {
        return null;
    }
    return json_decode((string) $device['config'], true) ?: null;
}

/** Profil als Gerätekonfiguration (settings + connection_tests.targets aus den SSIDs). */
function profile_build_config(int $profileId): ?array
{
    $profile = profile_find($profileId);
    if ($profile === null) {
        return null;
    }
    $config = json_decode((string) $profile['settings'], true) ?: [];
    $targets = [];
    foreach (profile_ssid_ids($profileId) as $ssidId) {
        $ssid = ssid_find($ssidId);
        if ($ssid !== null) {
            $targets[] = json_decode((string) $ssid['target'], true) ?: [];
        }
    }
    $config['connection_tests'] = (array) ($config['connection_tests'] ?? []);
    $config['connection_tests']['targets'] = $targets;
    return $config;
}

/** Kennung der wirksamen Konfiguration für den Heartbeat (ändert sie sich, holt die Probe sie sofort). */
function device_config_version(array $device): string
{
    $config = device_effective_config($device);
    return $config === null ? 'none' : substr(sha1((string) json_encode($config)), 0, 16);
}
