#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Nächtliches Aufräumen nach der Aufbewahrung aus /settings/retention
 * (Scans Standard 90 Tage, Connection-/LAN-Tests 365 Tage, 0 = unbegrenzt,
 * siehe src/Retention.php und README "Datenhaltung & Backup"). Löscht in
 * Portionen, damit die Probes währenddessen weiter hochladen können.
 */

// Beispiel-Cronzeile (nach dem Backup um 02:15, siehe backup_db.php):
//   30 3 * * * php /pfad/zu/cleanup_data.php >> /var/log/wlanmon-cleanup.log 2>&1

require_once __DIR__ . '/src/Retention.php';

function cleanup_log(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

try {
    $cfg = retention_config();
    $start = microtime(true);
    $deleted = retention_cleanup();
    $days = static fn(int $d): string => $d > 0 ? "$d Tage" : 'unbegrenzt';
    $parts = [];
    foreach ($deleted as $kind => $count) {
        $parts[] = "$kind $count";
    }
    cleanup_log(sprintf(
        'Aufgeräumt (Scans %s, Tests %s): %s gelöscht, %.1f s',
        $days($cfg['scan_days']),
        $days($cfg['test_days']),
        implode(', ', $parts),
        microtime(true) - $start
    ));
} catch (Throwable $e) {
    cleanup_log('FEHLER: ' . $e->getMessage());
    exit(1);
}
