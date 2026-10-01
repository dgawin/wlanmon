<?php
/*
 * Tabelle mit Einträgen des Änderungsprotokolls (src/Audit.php) - eingebunden
 * in settings_audit.php (alle) und device_config.php (nur dieses Gerät).
 *
 * @var array<int, array<string, mixed>> $auditEntries
 * @var bool $auditShowObject Spalte "Objekt" anzeigen (auf der Geräteseite überflüssig)
 */

$auditActionLabels = [
    'created' => __('angelegt'),
    'config' => __('Konfiguration geändert'),
    'api_key_rotated' => __('API-Key rotiert'),
    'deleted' => __('gelöscht'),
    'changed' => __('geändert'),
    'renamed' => __('umbenannt'),
    'notes' => __('Bemerkung geändert'),
    'alerting' => __('Alarmierung geändert'),
    'encrypted' => __('Zugangsdaten verschlüsselt'),
    'disabled' => __('deaktiviert'),
    'enabled' => __('reaktiviert'),
];
$auditSecretLabels = [
    'set' => __('gesetzt'),
    'changed' => __('geändert'),
    'removed' => __('entfernt'),
];
$auditObjectLink = static function (array $row): string {
    $id = (string) $row['object_id'];
    switch ($row['object_type']) {
        case 'device':
            return '<a href="/devices/' . e(rawurlencode($id)) . '/config"><i class="fa-solid fa-microchip"></i> ' . e($id) . '</a>';
        case 'site':
            return '<a href="/sites/' . (int) $id . '/alerting"><i class="fa-solid fa-building"></i> ' . te('Standort') . ' #' . (int) $id . '</a>';
        case 'user':
            return '<a href="/users/' . (int) $id . '/edit"><i class="fa-solid fa-user"></i> ' . te('Benutzer #%d', (int) $id) . '</a>';
        case 'settings':
            $labels = ['alerting' => ['/settings/alerting', __('Zugangsdaten für die Alarmierung')], 'retention' => ['/settings/retention', __('Datenhaltung')]];
            [$href, $label] = $labels[$id] ?? ['#', $id];
            return '<a href="' . e($href) . '"><i class="fa-solid fa-gear"></i> ' . e($label) . '</a>';
        case 'system':
            return '<i class="fa-solid fa-lock"></i> ' . te('System');
        default:
            return e($row['object_type'] . ' ' . $id);
    }
};
?>
<?php if (empty($auditEntries)): ?>
    <p class="muted"><?= te('Noch keine Änderungen protokolliert.') ?></p>
<?php else: ?>
<div class="table-scroll">
<table class="audit-table">
    <thead>
        <tr>
            <th><?= te('Zeitpunkt') ?></th>
            <th><?= te('Geändert von') ?></th>
            <?php if (!empty($auditShowObject)): ?><th><?= te('Objekt') ?></th><?php endif; ?>
            <th><?= te('Aktion') ?></th>
            <th><?= te('Änderungen') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($auditEntries as $row): ?>
        <tr>
            <td class="nowrap"><?= e(format_local((string) $row['created_at'], 'd.m.Y H:i')) ?></td>
            <td><?= $row['username'] !== null ? e((string) $row['username']) : '<span class="muted">' . te('API/Skript') . '</span>' ?></td>
            <?php if (!empty($auditShowObject)): ?><td><?= $auditObjectLink($row) ?></td><?php endif; ?>
            <td><?= e($auditActionLabels[$row['action']] ?? (string) $row['action']) ?></td>
            <td>
                <?php if ($row['changes'] === []): ?>–<?php endif; ?>
                <?php foreach ($row['changes'] as $c): ?>
                    <?php if ($row['action'] === 'encrypted'): ?>
                        <?php // Gespeichert als secrets: <Anzahl> -> enc:v1 (tools/secrets.php, /settings/secrets/encrypt). ?>
                        <div class="audit-change"><?= te('%d Werte verschlüsselt.', (int) ($c['old'] ?? 0)) ?></div>
                        <?php continue; ?>
                    <?php endif; ?>
                    <div class="audit-change">
                        <code><?= e((string) ($c['field'] ?? '')) ?></code>:
                        <?php if (isset($c['block'])): ?>
                            <?= $c['block'] === 'added' ? te('hinzugefügt') : te('entfernt') ?>
                        <?php elseif (isset($c['secret'])): ?>
                            <i class="fa-solid fa-lock"></i> <?= e($auditSecretLabels[$c['secret']] ?? (string) $c['secret']) ?>
                            <span class="muted">(<?= te('Wert nicht protokolliert') ?>)</span>
                        <?php else: ?>
                            <?= e((string) ($c['old'] ?? '')) ?> → <?= e((string) ($c['new'] ?? '')) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
