<?php
declare(strict_types=1);

/**
 * Datenbank für den Docker-Betrieb vorbereiten (aufgerufen von
 * docker/entrypoint.sh bei jedem Containerstart):
 *
 * 1. Bis zu 60 s warten, bis MariaDB Verbindungen annimmt.
 * 2. schema.sql einspielen. Alle Tabellen stehen dort als CREATE TABLE IF
 *    NOT EXISTS - das ist bei jedem Start gefahrlos wiederholbar.
 * 3. Spalten nachziehen, die spätere Versionen ergänzt haben und die eine
 *    aus einem älteren Dump importierte Datenbank noch nicht hat (die
 *    ALTER-Hinweise in schema.sql sind nur Kommentare).
 *
 * Exit-Code != 0 bricht den Containerstart ab (Docker startet neu).
 */

require_once __DIR__ . '/../src/db.php';

$deadline = time() + 60;
while (true) {
    try {
        $pdo = db();
        break;
    } catch (Throwable $e) {
        if (time() >= $deadline) {
            fwrite(STDERR, "[init_db] Datenbank nicht erreichbar: {$e->getMessage()}\n");
            exit(1);
        }
        sleep(2);
    }
}

// Kommentarzeilen entfernen (sie enthalten ALTER-Beispiele mit ";"), dann
// an ";" am Zeilenende in einzelne Anweisungen teilen.
$sql = (string) file_get_contents(__DIR__ . '/../schema.sql');
$lines = array_filter(
    preg_split('/\R/', $sql) ?: [],
    static fn(string $l): bool => !preg_match('/^\s*--/', $l)
);
$statements = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
$count = 0;
foreach ($statements as $stmt) {
    if (trim($stmt) === '') {
        continue;
    }
    $pdo->exec($stmt);
    $count++;
}

// Nachgezogene Spalten: Tabelle, Spalte, Definition.
$migrations = [
    ['devices', 'auto_update', 'LONGTEXT NULL AFTER probe_version'],
    ['users', 'language', 'VARCHAR(5) NULL AFTER role'],
    ['users', 'disabled_at', 'DATETIME NULL AFTER language'],
    ['site_alerting', 'language', 'VARCHAR(5) NULL AFTER schedule_end_hour'],
    ['site_alerting', 'auth_slow_seconds', 'DECIMAL(5,1) NULL AFTER language'],
];
foreach ($migrations as [$table, $column, $definition]) {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute([$table, $column]);
    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "[init_db] Spalte $table.$column ergänzt\n";
    }
}

echo "[init_db] Schema geprüft ($count Anweisungen)\n";
