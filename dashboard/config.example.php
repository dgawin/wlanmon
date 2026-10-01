<?php
// Nach config.php kopieren und echte Werte eintragen.
// config.php NICHT ins Git-Repo committen / öffentlich erreichbar machen
// (liegt außerhalb von public/, ist also über den Webserver ohnehin
// nicht direkt aufrufbar, solange DocumentRoot korrekt auf public/ zeigt).

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'wlanmon',
        'user' => 'wlanmon',
        'pass' => 'change-me-to-a-long-random-value',
    ],
    // Basic-Auth NUR noch für die /api/v1/admin/*-Endpunkte (curl-
    // Automatisierung, siehe README "Geräte per API verwalten"). Der
    // Web-Login unter /login läuft über echte Benutzerkonten (Tabelle
    // "users", siehe create_admin.php und README "Benutzer & Rollen") -
    // NICHT über diesen Block.
    'admin' => [
        'user' => 'admin',
        'pass' => 'change-me-too-long-and-random',
    ],
    'zabbix' => [
        // Bearer-Token für die schreibgeschützten Zabbix-Endpunkte
        // (/api/v1/zabbix/...), separat vom Admin-Login - Zabbix bekommt
        // damit nur Lesezugriff auf Status-Daten, keine Admin-Rechte.
        // Generieren z.B. mit: openssl rand -base64 32
        'token' => 'change-me-too-long-and-random',
    ],

    // OmniVista Cirrus (siehe README "OmniVista Cirrus", src/Cirrus.php,
    // sync_cirrus_aps.php) - rein optional, nur fuer die AP-Name/Standort-
    // Aufloesung in der Roaming-Kandidaten-Tabelle. Fehlt dieser Block
    // ganz oder ist eines der Felder leer, bleibt die Integration einfach
    // inaktiv (kein Fehler). app_id/app_secret: Cirrus-Organisation ->
    // Settings -> API Applications. email/password: dein normaler
    // Cirrus-Login (KEIN separates API-Konto vorgesehen). org_id steht in
    // der Cirrus-URL (.../organizations/<org_id>/...).
    'cirrus' => [
        'base_url' => 'https://eu.manage.ovcirrus.com',
        'org_id' => '',
        'app_id' => '',
        'app_secret' => '',
        'email' => '',
        'password' => '',
    ],

    // Schlüssel für die Verschlüsselung der Zugangsdaten in der Datenbank
    // (PSKs, 802.1X-/Portal-Passwörter, SMTP-Passwort, Bot-Token; siehe README
    // "Zugangsdaten verschlüsseln"). Erzeugen mit:
    //   php tools/secrets.php generate-key
    // Leer = unverschlüsselt wie bisher. GETRENNT vom DB-Backup aufbewahren -
    // ohne ihn sind verschlüsselte Zugangsdaten verloren.
    'secret_key' => '',

    // Nächtliches Datenbank-Backup (backup_db.php, siehe README "Datenhaltung
    // & Backup"): Zielverzeichnis (muss für den Cron-Benutzer beschreibbar
    // sein) und wie viele Tage Backups aufgehoben werden. Die Aufbewahrung
    // der Messungen selbst stellt der Admin im Dashboard ein
    // (/settings/retention).
    'backup' => [
        'dir' => '/var/backups/wlanmon',
        'keep_days' => 14,
    ],

    // Alerting (E-Mail/Telegram bei Störungen) wird NICHT hier gepflegt,
    // sondern über die Weboberfläche unter /settings/alerting - liegt in
    // der Datenbank (Tabelle "settings"), analog zur Geräte-Zentral-
    // Config. Siehe README, Abschnitt "Alerting", und src/Settings.php.
];
