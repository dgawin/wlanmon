<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';

/**
 * Eigener Bearer-Token statt Admin-Basic-Auth: Zabbix bekommt damit nur
 * Lesezugriff auf die Status-/Discovery-Endpunkte, nicht die vollen
 * Admin-Rechte (Geräte anlegen/löschen, Config setzen, PSKs einsehen).
 */
function require_zabbix_auth(): void
{
    $token = extract_bearer_token();
    $expected = (string) (wlanmon_config()['zabbix']['token'] ?? '');
    if ($token === null || $expected === '' || !hash_equals($expected, $token)) {
        json_error(401, "Authorization-Header muss 'Bearer <zabbix-token>' sein");
    }
}
