<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Settings.php';

/*
 * Verschlüsselung der Zugangsdaten in der Datenbank (PSKs, 802.1X-Passwörter
 * und private Schlüssel, Portal-Passwörter, SMTP-Passwort, Telegram-Bot-
 * Token). libsodium secretbox (XSalsa20-Poly1305), gespeichert als
 * "enc:v1:<base64(Nonce + Chiffretext)>".
 *
 * Schlüssel: config.php "secret_key" (32 Byte, base64; erzeugen mit
 * "php tools/secrets.php generate-key"), im Docker-Betrieb
 * WLANMON_SECRET_KEY. Er liegt bewusst NICHT in der Datenbank: ein DB-Dump
 * oder Backup enthält dann nur Chiffretext. Gegen einen Angreifer mit
 * vollem Zugriff auf den Server (Schlüssel + DB) hilft das nicht.
 *
 * Werte ohne Präfix sind Klartext (vor der Umstellung bzw. ohne Schlüssel
 * gespeichert) und werden weiter gelesen - Umstellung also schrittweise
 * beim Speichern oder auf einmal über die Datenhaltungs-Seite bzw.
 * "php tools/secrets.php encrypt-all". Ohne Schlüssel bleibt alles
 * Klartext wie bisher.
 */

const SECRET_PREFIX = 'enc:v1:';

/** Geheime Felder je Ziel-SSID (connection_tests.targets[]). */
const TARGET_SECRET_PATHS = [
    ['psk'],
    ['eap', 'password'],
    ['eap', 'private_key'],
    ['eap', 'private_key_password'],
    ['captive_portal_login', 'password'],
];

/** Gespeicherter Pfad je Ziel => Feldname im Formular (device_config.php). */
const TARGET_SECRET_FORM_FIELDS = [
    [['psk'], ['psk']],
    [['eap', 'password'], ['eap', 'password']],
    [['eap', 'private_key'], ['eap', 'private_key']],
    [['eap', 'private_key_password'], ['eap', 'private_key_password']],
    [['captive_portal_login', 'password'], ['portal_login', 'password']],
];

/** Geheime Felder der Alerting-Zugangsdaten (settings "alerting"). */
const ALERTING_SECRET_PATHS = [
    ['email', 'smtp_pass'],
    ['telegram', 'bot_token'],
];

/** Namen der geheimen Blätter - fürs Änderungsprotokoll (Wert nie protokollieren). */
const SECRET_FIELD_NAMES = ['psk', 'password', 'private_key', 'private_key_password', 'smtp_pass', 'bot_token'];

/**
 * @return array{sodium: bool, configured: bool, valid: bool}
 *   configured = secret_key gesetzt, valid = gesetzt, 32 Byte und sodium da
 */
function secret_key_status(): array
{
    $raw = trim((string) (wlanmon_config()['secret_key'] ?? ''));
    $sodium = function_exists('sodium_crypto_secretbox');
    $bin = $raw !== '' ? base64_decode($raw, true) : false;
    return [
        'sodium' => $sodium,
        'configured' => $raw !== '',
        'valid' => $sodium && is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
    ];
}

/** Schlüssel als Binärstring, null ohne (gültigen) Schlüssel oder ohne sodium. */
function secret_key(): ?string
{
    static $key = false;
    if ($key === false) {
        $key = secret_key_status()['valid']
            ? base64_decode(trim((string) wlanmon_config()['secret_key']), true)
            : null;
    }
    return $key;
}

function secret_is_encrypted(string $value): bool
{
    return strncmp($value, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0;
}

/** Verschlüsselt $plain; leer, schon verschlüsselt oder ohne Schlüssel -> unverändert. */
function secret_encrypt(string $plain): string
{
    $key = secret_key();
    if ($plain === '' || secret_is_encrypted($plain) || $key === null) {
        return $plain;
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return SECRET_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

/**
 * Klartext zu $value; Klartext (ohne Präfix) kommt unverändert zurück.
 * null = verschlüsselt, aber nicht zu entschlüsseln (Schlüssel fehlt,
 * falsch oder Wert beschädigt).
 */
function secret_decrypt(string $value): ?string
{
    if (!secret_is_encrypted($value)) {
        return $value;
    }
    $key = secret_key();
    $bin = base64_decode(substr($value, strlen(SECRET_PREFIX)), true);
    if ($key === null || $bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(
        substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $key
    );
    return $plain === false ? null : $plain;
}

/** Wert unter $path in $a, null wenn es ihn nicht gibt. */
function array_path_get(array $a, array $path)
{
    foreach ($path as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return null;
        }
        $a = $a[$k];
    }
    return $a;
}

function array_path_set(array $a, array $path, $value): array
{
    $k = array_shift($path);
    $a[$k] = $path === [] ? $value : array_path_set(is_array($a[$k] ?? null) ? $a[$k] : [], $path, $value);
    return $a;
}

/** $fn auf jedes vorhandene, nicht leere Geheimnis unter $paths anwenden. */
function secrets_map(array $data, array $paths, callable $fn): array
{
    foreach ($paths as $path) {
        $v = array_path_get($data, $path);
        if (is_string($v) && $v !== '') {
            $data = array_path_set($data, $path, $fn($v));
        }
    }
    return $data;
}

/** $fn auf alle Geheimnisse einer Geräte-Config anwenden (je Ziel-SSID). */
function device_config_map_secrets(array $config, callable $fn): array
{
    foreach ((array) ($config['connection_tests']['targets'] ?? []) as $i => $t) {
        if (is_array($t)) {
            $config['connection_tests']['targets'][$i] = secrets_map($t, TARGET_SECRET_PATHS, $fn);
        }
    }
    return $config;
}

/**
 * Geräte-Config mit entschlüsselten Geheimnissen (für die Auslieferung an die
 * Probe). $failed zählt Werte, die sich nicht entschlüsseln ließen.
 */
function device_config_decrypt(array $config, int &$failed = 0): array
{
    return device_config_map_secrets($config, static function (string $v) use (&$failed): string {
        $plain = secret_decrypt($v);
        if ($plain === null) {
            $failed++;
            return '';
        }
        return $plain;
    });
}

/**
 * Wert eines geheimen Formularfelds: neu eingegeben -> neu; leer und
 * "entfernen" angehakt -> leer; sonst der bisher gespeicherte Wert (so wie
 * gespeichert, ggf. verschlüsselt) - die Felder zeigen gespeicherte Werte
 * nie an ("nur schreiben").
 */
function secret_from_form($posted, bool $clear, ?string $stored): string
{
    $posted = is_string($posted) ? $posted : '';
    if ($posted !== '') {
        return $posted;
    }
    return $clear ? '' : (string) $stored;
}

/**
 * Stand der Verschlüsselung über alle gespeicherten Geheimnisse.
 *
 * @return array{encrypted: int, plain: int, broken: int}
 */
/** Zeilen der SSID-Liste (src/Profile.php); leer, solange es die Tabelle noch nicht gibt. */
function secrets_ssid_rows(): array
{
    try {
        return db()->query('SELECT id, target FROM ssids')->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function secrets_overview(): array
{
    $count = ['encrypted' => 0, 'plain' => 0, 'broken' => 0];
    $tally = static function (string $v) use (&$count): string {
        if (!secret_is_encrypted($v)) {
            $count['plain']++;
        } elseif (secret_decrypt($v) === null) {
            $count['broken']++;
        } else {
            $count['encrypted']++;
        }
        return $v;
    };
    foreach (db()->query('SELECT config FROM devices WHERE config IS NOT NULL')->fetchAll() as $row) {
        device_config_map_secrets(json_decode((string) $row['config'], true) ?: [], $tally);
    }
    foreach (secrets_ssid_rows() as $row) {
        secrets_map(json_decode((string) $row['target'], true) ?: [], TARGET_SECRET_PATHS, $tally);
    }
    secrets_map(setting_get('alerting') ?? [], ALERTING_SECRET_PATHS, $tally);
    return $count;
}

/**
 * Alle noch unverschlüsselten Geheimnisse verschlüsseln (Umstellung auf
 * einmal). Ohne gültigen Schlüssel passiert nichts. Gibt die Anzahl der
 * verschlüsselten Werte zurück.
 */
function secrets_encrypt_all(): int
{
    if (secret_key() === null) {
        return 0;
    }
    $done = 0;
    $encrypt = static function (string $v) use (&$done): string {
        if (secret_is_encrypted($v)) {
            return $v;
        }
        $done++;
        return secret_encrypt($v);
    };
    foreach (db()->query('SELECT id, config FROM devices WHERE config IS NOT NULL')->fetchAll() as $row) {
        $before = $done;
        $config = device_config_map_secrets(json_decode((string) $row['config'], true) ?: [], $encrypt);
        if ($done > $before) {
            db()->prepare('UPDATE devices SET config = ? WHERE id = ?')
                ->execute([json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row['id']]);
        }
    }
    foreach (secrets_ssid_rows() as $row) {
        $before = $done;
        $target = secrets_map(json_decode((string) $row['target'], true) ?: [], TARGET_SECRET_PATHS, $encrypt);
        if ($done > $before) {
            db()->prepare('UPDATE ssids SET target = ? WHERE id = ?')
                ->execute([json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row['id']]);
        }
    }
    $alerting = setting_get('alerting');
    if ($alerting !== null) {
        $before = $done;
        $alerting = secrets_map($alerting, ALERTING_SECRET_PATHS, $encrypt);
        if ($done > $before) {
            setting_set('alerting', $alerting);
        }
    }
    return $done;
}
