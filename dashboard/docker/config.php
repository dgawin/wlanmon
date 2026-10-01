<?php
// config.php für den Docker-Betrieb: dieselbe Struktur wie
// config.example.php, die Werte kommen aus Umgebungsvariablen
// (docker-compose.yml bzw. Stack-Variablen in Portainer). Leere
// Cirrus-Werte lassen die Integration inaktiv, wie bei config.php.

$env = static fn(string $name, string $default = ''): string =>
    ($v = getenv($name)) !== false ? (string) $v : $default;

return [
    'db' => [
        'host' => $env('WLANMON_DB_HOST', 'db'),
        'port' => (int) $env('WLANMON_DB_PORT', '3306'),
        'name' => $env('WLANMON_DB_NAME', 'wlanmon'),
        'user' => $env('WLANMON_DB_USER', 'wlanmon'),
        'pass' => $env('WLANMON_DB_PASSWORD'),
    ],
    'admin' => [
        'user' => $env('WLANMON_ADMIN_USER', 'admin'),
        'pass' => $env('WLANMON_ADMIN_PASSWORD'),
    ],
    'zabbix' => [
        'token' => $env('WLANMON_ZABBIX_TOKEN'),
    ],
    'cirrus' => [
        'base_url' => $env('WLANMON_CIRRUS_BASE_URL', 'https://eu.manage.ovcirrus.com'),
        'org_id' => $env('WLANMON_CIRRUS_ORG_ID'),
        'app_id' => $env('WLANMON_CIRRUS_APP_ID'),
        'app_secret' => $env('WLANMON_CIRRUS_APP_SECRET'),
        'email' => $env('WLANMON_CIRRUS_EMAIL'),
        'password' => $env('WLANMON_CIRRUS_PASSWORD'),
    ],
    // Verschlüsselung der Zugangsdaten (siehe README "Zugangsdaten verschlüsseln").
    'secret_key' => $env('WLANMON_SECRET_KEY'),
    // Nächtliches DB-Backup (backup_db.php, im Cron-Container) - Verzeichnis
    // ist ein eigenes Volume, siehe docker-compose.yml.
    'backup' => [
        'dir' => $env('WLANMON_BACKUP_DIR', '/var/backups/wlanmon'),
        'keep_days' => (int) $env('WLANMON_BACKUP_KEEP_DAYS', '14'),
    ],
];
