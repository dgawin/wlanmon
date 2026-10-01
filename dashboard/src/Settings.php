<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------
// Generischer Key-Value-Speicher fuer server-weite, ueber das Web
// gepflegte Einstellungen (Tabelle "settings", siehe schema.sql). Werte
// liegen als JSON-Text vor, genau wie devices.config bei der
// Geraete-Zentral-Config.
// ---------------------------------------------------------------------

/** @return array<string, mixed>|null null, wenn der Schluessel noch nie gespeichert wurde. */
function setting_get(string $name): ?array
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row === false || $row['value'] === null) {
        return null;
    }
    $decoded = json_decode((string) $row['value'], true);
    return is_array($decoded) ? $decoded : null;
}

function setting_set(string $name, array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = db()->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([$name, $json]);
}
