#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Nächtliches Datenbank-Backup (siehe README "Datenhaltung & Backup"):
 * kompletter Dump per mariadb-dump/mysqldump, gzip-komprimiert nach
 * <dir>/wlanmon-JJJJ-MM-TT_HHMM.sql.gz. Nach einem erfolgreichen Backup
 * werden Dateien älter als keep_days gelöscht (nie nach einem Fehlschlag).
 * Zugangsdaten aus config.php ("db"), Ziel/Aufbewahrung aus config.php
 * ("backup": dir, keep_days) oder per --dir=... --keep-days=...
 * Ergebnis landet zusätzlich in der DB (Schlüssel "backup_last_run") und
 * wird unter /settings/retention angezeigt.
 *
 * Die Dumps enthalten WLAN-Passwörter, 802.1X- und SMTP-Zugangsdaten im
 * Klartext - Dateien werden mit 0600 angelegt, das Verzeichnis mit 0750.
 *
 * Wiederherstellen:
 *   gunzip < wlanmon-2026-09-30_0215.sql.gz | mysql -u wlanmon -p wlanmon
 */

// Beispiel-Cronzeile (vor dem Aufräumen um 03:30, siehe cleanup_data.php):
//   15 2 * * * php /pfad/zu/backup_db.php >> /var/log/wlanmon-backup.log 2>&1

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/Settings.php';

function backup_log(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

/** Erstes ausführbares Programm aus $names im PATH, sonst null. */
function backup_find_binary(array $names): ?string
{
    $dirs = array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/bin', '/usr/local/bin']);
    foreach ($names as $name) {
        foreach ($dirs as $dir) {
            $path = rtrim($dir, '/') . '/' . $name;
            if ($dir !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }
    }
    return null;
}

/** Wert für eine MySQL-Optionsdatei in Anführungszeichen. */
function backup_option_value(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

$options = getopt('', ['dir:', 'keep-days:']);
$config = wlanmon_config();
$backupConfig = is_array($config['backup'] ?? null) ? $config['backup'] : [];
$dir = rtrim((string) ($options['dir'] ?? $backupConfig['dir'] ?? '/var/backups/wlanmon'), '/');
$keepDays = max(1, (int) ($options['keep-days'] ?? $backupConfig['keep_days'] ?? 14));
$db = $config['db'];

$record = static function (bool $ok, ?string $file, ?int $bytes, ?string $error, int $kept = 0) use ($dir, $keepDays): void {
    try {
        setting_set('backup_last_run', [
            'at' => gmdate('Y-m-d H:i:s'),
            'ok' => $ok,
            'file' => $file,
            'bytes' => $bytes,
            'error' => $error,
            'dir' => $dir,
            'keep_days' => $keepDays,
            'kept' => $kept,
        ]);
    } catch (Throwable $e) {
        backup_log('Status konnte nicht gespeichert werden: ' . $e->getMessage());
    }
};

$fail = static function (string $error) use ($record): void {
    backup_log('FEHLER: ' . $error);
    $record(false, null, null, $error);
    exit(1);
};

$dumpBinary = backup_find_binary(['mariadb-dump', 'mysqldump']);
if ($dumpBinary === null) {
    $fail('mariadb-dump/mysqldump nicht gefunden (Paket mariadb-client bzw. mysql-client installieren)');
}
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    $fail("Backup-Verzeichnis $dir lässt sich nicht anlegen");
}
if (!is_writable($dir)) {
    $fail("Backup-Verzeichnis $dir ist nicht beschreibbar");
}

// Zugangsdaten über eine temporäre Optionsdatei statt auf der Kommandozeile
// (dort wären sie für andere Benutzer in der Prozessliste sichtbar).
$optionsFile = tempnam(sys_get_temp_dir(), 'wlanmon-dump-');
chmod($optionsFile, 0600);
file_put_contents($optionsFile, implode("\n", [
    '[client]',
    'host=' . backup_option_value((string) $db['host']),
    'port=' . (int) ($db['port'] ?? 3306),
    'user=' . backup_option_value((string) $db['user']),
    'password=' . backup_option_value((string) $db['pass']),
    '',
]));

$file = $dir . '/wlanmon-' . date('Y-m-d_Hi') . '.sql.gz';
$partial = $file . '.partial';
$start = microtime(true);

try {
    $process = proc_open(
        [
            $dumpBinary,
            '--defaults-extra-file=' . $optionsFile,
            '--single-transaction',   // konsistenter Stand ohne Tabellensperren (InnoDB)
            '--quick',
            '--no-tablespaces',       // braucht sonst das PROCESS-Recht
            '--default-character-set=utf8mb4',
            (string) $db['name'],
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        $fail('Dump-Prozess ließ sich nicht starten');
    }
    $gz = gzopen($partial, 'wb6');
    chmod($partial, 0600);
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1 << 20);
        if ($chunk !== false && $chunk !== '') {
            gzwrite($gz, $chunk);
        }
    }
    gzclose($gz);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
} finally {
    @unlink($optionsFile);
}

if ($exitCode !== 0) {
    @unlink($partial);
    $fail("Dump fehlgeschlagen (Exit-Code $exitCode): " . ($stderr !== '' ? $stderr : 'keine Meldung'));
}
rename($partial, $file);
$bytes = (int) filesize($file);

// Alte Backups erst nach einem erfolgreichen neuen löschen.
$kept = 0;
foreach (glob($dir . '/wlanmon-*.sql.gz') ?: [] as $old) {
    if (filemtime($old) < time() - $keepDays * 86400) {
        @unlink($old);
    } else {
        $kept++;
    }
}

$record(true, basename($file), $bytes, $stderr !== '' ? $stderr : null, $kept);
backup_log(sprintf(
    'Backup %s geschrieben (%.1f MB, %.1f s), %d Backup(s) im Verzeichnis, Aufbewahrung %d Tage',
    $file,
    $bytes / 1048576,
    microtime(true) - $start,
    $kept,
    $keepDays
));
