<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------
// Kuratierte Standorte (Tabelle "sites", siehe schema.sql). devices.site_id
// ist die massgebliche Zuordnung (Admin-verwaltet, siehe /devices/<id>/config)
// und Grundlage fuer Berechtigungen (user_sites/Session.php) sowie fuer die
// Per-Site-Alerting-Regeln (site_alerting, siehe Alerting.php).
// ---------------------------------------------------------------------

/** @return array<int, array<string, mixed>> Nach Name sortiert. */
function site_list(): array
{
    return db()->query('SELECT * FROM sites ORDER BY name')->fetchAll();
}

function site_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Fuer "php check_alerts.php --test <site-name>" - Name statt ID ist am Terminal praktischer. */
function site_find_by_name(string $name): ?array
{
    $stmt = db()->prepare('SELECT * FROM sites WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @throws PDOException bei doppeltem Namen (UNIQUE-Constraint). */
/** @return int ID des neuen Standorts */
function site_create(string $name): int
{
    $stmt = db()->prepare('INSERT INTO sites (name) VALUES (?)');
    $stmt->execute([$name]);
    return (int) db()->lastInsertId();
}

/** @throws PDOException bei doppeltem Namen (UNIQUE-Constraint). */
function site_rename(int $id, string $name): void
{
    $stmt = db()->prepare('UPDATE sites SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);
}

function site_set_notes(int $id, string $notes): void
{
    $stmt = db()->prepare('UPDATE sites SET notes = ? WHERE id = ?');
    $stmt->execute([$notes, $id]);
}

/**
 * Loescht einen Standort. Geraete mit diesem site_id werden per
 * ON DELETE SET NULL automatisch "nicht zugeordnet" (schema.sql) statt
 * dass das Loeschen scheitert oder verwaiste Referenzen entstehen.
 */
function site_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM sites WHERE id = ?');
    $stmt->execute([$id]);
}

/** Anzahl Geraete, die aktuell dieser Site zugeordnet sind (fuer die Loeschbestaetigung). */
function site_device_count(int $siteId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM devices WHERE site_id = ?');
    $stmt->execute([$siteId]);
    return (int) $stmt->fetchColumn();
}
