#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Einmalig per SSH auszuführen, um den ersten Admin-Account anzulegen
 * (siehe README, Abschnitt "Installation") - danach über die
 * Weboberfläche unter /users weitere Benutzer einladen. Ersetzt den
 * früheren config.php['admin']-Block für den Web-Login (die Basic-Auth
 * dort sichert weiterhin nur noch die /api/v1/admin/*-Endpunkte für
 * curl-Automatisierung, siehe src/admin_auth.php).
 *
 * Aufruf: php create_admin.php <username> <email> <passwort>
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/User.php';

function usage_and_exit(): never
{
    fwrite(STDERR, "Aufruf: php create_admin.php <username> <email> <passwort>\n");
    exit(1);
}

[, $username, $email, $password] = array_pad($argv, 4, null);
if ($username === null || $email === null || $password === null) {
    usage_and_exit();
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Passwort muss mindestens 8 Zeichen lang sein.\n");
    exit(1);
}
if (is_weak_password($username, $password)) {
    fwrite(STDERR, "Passwort ist zu schwach/vorhersehbar (z.B. 'admin' oder identisch zum Benutzernamen) - bitte ein anderes wählen.\n");
    exit(1);
}
if (user_find_by_username($username) !== null) {
    fwrite(STDERR, "Benutzername '$username' existiert bereits.\n");
    exit(1);
}

$id = user_create($username, $email, 'admin');
user_set_password($id, $password);

echo "Admin-Account '$username' angelegt. Login unter /login.\n";
