<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

/**
 * Prüft HTTP-Basic-Auth gegen den in config.php hinterlegten
 * Admin-Account. Bricht mit 401 + WWW-Authenticate-Header ab, wenn
 * die Zugangsdaten fehlen oder falsch sind - das lässt Browser
 * automatisch den nativen Login-Dialog anzeigen, curl -u funktioniert
 * ebenso direkt.
 */
function require_admin(): void
{
    $header = get_authorization_header();
    $cfg = wlanmon_config()['admin'];

    $unauthorized = static function (): void {
        header('WWW-Authenticate: Basic realm="WLANMON Admin"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "401 Unauthorized\n";
        exit;
    };

    if ($header === null || stripos($header, 'Basic ') !== 0) {
        $unauthorized();
    }

    $decoded = base64_decode(trim(substr($header, 6)), true);
    if ($decoded === false || strpos($decoded, ':') === false) {
        $unauthorized();
    }

    // Ohne konfiguriertes Passwort (leer, z.B. vergessene Umgebungsvariable
    // im Docker-Betrieb) bleibt die Admin-API gesperrt - sonst ließe
    // hash_equals('', '') einen Login mit leerem Passwort zu.
    if ((string) ($cfg['user'] ?? '') === '' || (string) ($cfg['pass'] ?? '') === '') {
        $unauthorized();
    }

    [$user, $pass] = explode(':', $decoded, 2);
    $validUser = hash_equals((string) $cfg['user'], $user);
    $validPass = hash_equals((string) $cfg['pass'], $pass);
    if (!($validUser && $validPass)) {
        $unauthorized();
    }
}
