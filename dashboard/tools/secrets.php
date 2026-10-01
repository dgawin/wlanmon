#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Verschlüsselung der Zugangsdaten verwalten (siehe src/Secrets.php und
 * README "Zugangsdaten verschlüsseln"):
 *
 *   php tools/secrets.php generate-key   neuen Schlüssel ausgeben (für config.php "secret_key")
 *   php tools/secrets.php status         Schlüssel und Stand der gespeicherten Zugangsdaten
 *   php tools/secrets.php encrypt-all    alle noch unverschlüsselten Werte verschlüsseln
 */

$command = $argv[1] ?? 'status';

if ($command === 'generate-key') {
    // Braucht weder Datenbank noch config.php.
    fwrite(STDOUT, base64_encode(random_bytes(32)) . "\n");
    fwrite(STDERR, "In config.php eintragen: 'secret_key' => '<obiger Wert>',\n"
        . "und GETRENNT vom Datenbank-Backup aufbewahren (z.B. Passwort-Manager).\n");
    exit(0);
}

require_once __DIR__ . '/../src/Secrets.php';
require_once __DIR__ . '/../src/Audit.php';

$key = secret_key_status();
$keyText = !$key['sodium'] ? 'PHP-Erweiterung sodium fehlt'
    : (!$key['configured'] ? 'kein secret_key in config.php'
    : (!$key['valid'] ? 'secret_key ungültig (erwartet 32 Byte base64)' : 'eingerichtet'));

if ($command === 'status') {
    $s = secrets_overview();
    fwrite(STDOUT, "Schlüssel: $keyText\n"
        . "Zugangsdaten: {$s['encrypted']} verschlüsselt, {$s['plain']} unverschlüsselt, {$s['broken']} nicht entschlüsselbar\n");
    exit($s['broken'] > 0 ? 1 : 0);
}

if ($command === 'encrypt-all') {
    if (!$key['valid']) {
        fwrite(STDERR, "Abbruch: $keyText\n");
        exit(1);
    }
    $count = secrets_encrypt_all();
    if ($count > 0) {
        audit_log('system', 'secrets', 'encrypted', [['field' => 'secrets', 'old' => (string) $count, 'new' => 'enc:v1']]);
    }
    fwrite(STDOUT, "$count Werte verschlüsselt.\n");
    exit(0);
}

fwrite(STDERR, "Aufruf: php tools/secrets.php generate-key|status|encrypt-all\n");
exit(1);
