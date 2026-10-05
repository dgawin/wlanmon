<?php
/**
 * Cirrus-Anmeldeversuche zu einem Connection-Test (siehe
 * handle_cirrus_auth_fragment(), src/CirrusAuth.php). Wird per Klick in die
 * Fehlerspalte der Testtabelle geladen.
 *
 * @var array{status: string, records: array<int, array<string, mixed>>, cached: bool, window: array{0: string, 1: string}|null} $result
 * @var array<string, mixed> $measurement
 */
$testData = json_decode((string) $measurement['data'], true) ?: [];
?>
<div class="cirrus-auth">
<?php if ($result['status'] === 'error'): ?>
    <span class="fail"><?= te('Cirrus nicht erreichbar oder Anmeldung bei Cirrus fehlgeschlagen (Details im PHP-Fehlerlog).') ?></span>
<?php elseif ($result['status'] === 'no_mac'): ?>
    <?= te('Der Test enthält keine MAC-Adresse (ältere Probe) - ohne sie lässt sich kein Eintrag zuordnen.') ?>
<?php elseif ($result['status'] === 'disabled'): ?>
    <?= te('OmniVista Cirrus ist nicht eingerichtet.') ?>
<?php elseif ($result['status'] === 'none'): ?>
    <?= te('Cirrus hat rund um diesen Test keinen Anmeldeversuch von %s verzeichnet. Das heißt nicht sicher, dass die Anfrage dort nicht ankam: Anmeldungen, die mitten im EAP-Ablauf abbrechen (z. B. beim TLS-Aufbau), tauchen in der Historie möglicherweise nicht auf.', (string) ($testData['mac_address'] ?? '')) ?>
<?php else: ?>
    <?php foreach ($result['records'] as $r):
        $ok = ($r['result'] ?? '') === 'SUCCESSFUL'; ?>
        <div class="cirrus-auth-record">
            <span class="pill <?= $ok ? 'pill-ok' : 'pill-fail' ?>">
                <i class="fa-solid <?= $ok ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
                <?= $ok ? te('angenommen') : (($r['result'] ?? '') === 'FAILED' ? te('abgelehnt') : e($r['result'] ?? __('unbekannt'))) ?>
            </span>
            <?= e(format_local($r['session_start'] ?? null, 'H:i:s')) ?>
            <?php if (!empty($r['auth_method'])): ?>· <?= e($r['auth_method']) ?><?php endif; ?>
            <?php if (!empty($r['reject_reason'])): ?>
                <br><strong><?= te('Ablehnungsgrund') ?>:</strong> <?= e($r['reject_reason']) ?>
            <?php endif; ?>
            <?php if (!empty($r['ap_mac'])): ?>
                <br>AP <?= e($r['ap_name'] ?? $r['ap_mac']) ?><?php if (!empty($r['ap_name'])): ?> <span class="muted">(<?= e($r['ap_mac']) ?>)</span><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($r['username']) || !empty($r['auth_resource'])): ?>
                <br><?= te('Benutzername') ?> <?= e($r['username'] ?? '–') ?>
                <?php if (!empty($r['auth_resource'])): ?><span class="muted">(<?= e($r['auth_resource']) ?>)</span><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($r['access_policy']) || !empty($r['role']) || !empty($r['vlan'])): ?>
                <br><?= te('Richtlinie') ?> <?= e($r['access_policy'] ?? '–') ?>
                <?php if (!empty($r['role'])): ?>→ <?= te('Rolle') ?> <?= e($r['role']) ?><?php endif; ?>
                <?php if (!empty($r['vlan'])): ?>· VLAN <?= e($r['vlan']) ?><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($r['terminate_reason']) || !empty($r['session_time'])): ?>
                <br><span class="muted"><?= te('Sitzung') ?> <?= e($r['session_time'] ?? '') ?><?php if (!empty($r['terminate_reason'])): ?>, <?= te('beendet') ?>: <?= e($r['terminate_reason']) ?><?php endif; ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
