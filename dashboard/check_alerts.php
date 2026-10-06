#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Periodische Alert-Auswertung (siehe README, Abschnitt "Alerting").
 * Prueft je Geraet, das einer Site zugeordnet ist und fuer dessen Site
 * Alerting aktiviert ist (siehe /sites/<id>/alerting):
 *   - "offline": laenger als offline_after_minutes nicht gemeldet
 *     (bzw. noch nie gemeldet, dann gegen created_at statt last_seen_at).
 *   - "ssid_failing:<SSID>": die letzten consecutive_test_failures
 *     Connection-Tests dieser SSID waren allesamt nicht verbunden.
 *   - "auth_slow:<SSID>": die letzten consecutive_test_failures Tests mit
 *     802.1X-Zeit lagen alle ueber auth_slow_seconds (nur wenn gesetzt).
 *   - "eap_abort_rate:<SSID>": im Zeitfenster eap_abort_window_minutes ist
 *     mindestens eap_abort_rate_pct % der Tests an einer 802.1X-SSID
 *     gescheitert, mind. EAP_ABORT_MIN_FAILURES (nur wenn gesetzt).
 * Bei einer NEUEN Stoerung wird sofort benachrichtigt (sofern gerade im
 * erlaubten Zeitfenster der Site); bleibt sie bestehen, erst wieder nach
 * repeat_after_minutes (kein Spam bei jedem Cron-Tick); klaert sie sich,
 * gibt es eine Entwarnung. Zustand liegt in der Tabelle "alerts" (siehe
 * schema.sql) und wird UNABHAENGIG vom Zeitfenster immer aktuell
 * gehalten - nur der tatsaechliche Versand wird ausserhalb des Fensters
 * unterdrueckt (siehe alert_handle_condition() in src/Alerting.php).
 * Geraete ohne Site-Zuordnung werden uebersprungen - Alerting ist ein
 * reines Site-Feature.
 */

// Gedacht fuer einen periodischen Cronjob (z.B. alle 5 Minuten), Beispiel-
// Cron-Zeile (bewusst als "//"-Kommentar, nicht im Docblock oben - ein
// "*/" mitten im Text wuerde dort das Kommentarende vortaeuschen und die
// Datei mit einem Parse-Fehler kaputt machen):
//   */5 * * * * php /pfad/zu/check_alerts.php >> /var/log/wlanmon-alerts.log 2>&1
//
// Manueller Test der Zustellung (E-Mail/Telegram) fuer eine bestimmte
// Site, unabhaengig von deren "enabled"-Schalter und Zeitfenster, ohne
// die Alert-Tabelle anzufassen:
//   php check_alerts.php --test <site-name>

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/Device.php';
require_once __DIR__ . '/src/Measurement.php';
require_once __DIR__ . '/src/Alerting.php';
require_once __DIR__ . '/src/Site.php';
require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/ProbeError.php';
require_once __DIR__ . '/src/Profile.php';

/** Mindestzahl Fehlschlaege fuer die Regel eap_abort_rate. */
const EAP_ABORT_MIN_FAILURES = 3;

/**
 * SSIDs, die das Gerät laut wirksamer Konfiguration testet (als Set), oder null
 * ohne zentrale Konfiguration - dann weiß das Dashboard nicht, was die Probe testet.
 */
function configured_ssids(array $device): ?array
{
    $config = device_effective_config($device);
    if ($config === null || !isset($config['connection_tests']['targets'])) {
        return null;
    }
    $set = [];
    foreach ((array) $config['connection_tests']['targets'] as $t) {
        if (is_array($t) && (string) ($t['ssid'] ?? '') !== '') {
            $set[(string) $t['ssid']] = true;
        }
    }
    return $set;
}

function log_line(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

$testIndex = array_search('--test', $argv, true);
if ($testIndex !== false) {
    $siteName = $argv[$testIndex + 1] ?? null;
    if ($siteName === null) {
        log_line('Aufruf: php check_alerts.php --test <site-name>');
        exit(1);
    }
    $site = site_find_by_name($siteName);
    if ($site === null) {
        log_line("Site '$siteName' nicht gefunden (siehe /sites).");
        exit(1);
    }
    $siteAlerting = site_alerting_get((int) $site['id']) ?? [];
    log_line("Sende Test-Alarm fuer Site '$siteName' (E-Mail/Telegram, je nach *_enabled unter /sites/{$site['id']}/alerting)...");
    // Texte in der Alert-Sprache des Standorts (siehe /sites/<id>/alerting).
    with_lang($siteAlerting['language'] ?? null, function () use ($siteAlerting, $siteName): void {
        dispatch_alert(
            site_alerting_channels($siteAlerting),
            __('WLANMON: Test-Alarm (%s)', $siteName),
            __('Dies ist ein Testalarm von „php check_alerts.php --test“, um die Zustellung zu prüfen. Keine echte Störung.')
        );
    });
    log_line('Fertig - bei Fehlern steht die Ursache im PHP-Error-Log (error_log).');
    exit(0);
}

/** Ruft alert_handle_condition() auf und loggt, was passiert ist. */
function handle_condition(
    string $deviceId,
    string $rule,
    bool $active,
    string $subject,
    string $message,
    int $repeatAfterSeconds,
    array $siteChannels,
    bool $notifyAllowed
): void {
    $outcome = alert_handle_condition($deviceId, $rule, $active, $subject, $message, $repeatAfterSeconds, $siteChannels, $notifyAllowed);
    if ($outcome !== 'unveraendert') {
        log_line(strtoupper($outcome) . ": $deviceId / $rule" . ($notifyAllowed ? '' : ' (Zeitfenster zu, nur Zustand aktualisiert)'));
    }
}

// Site-Alerting-Configs nicht pro Geraet einzeln nachladen, sondern einmal
// pro tatsaechlich vorkommender Site cachen (mehrere Geraete teilen sich
// oft dieselbe Site).
$siteAlertingCache = [];

foreach (device_list() as $device) {
    $deviceId = (string) $device['id'];
    $siteId = isset($device['site_id']) ? (int) $device['site_id'] : null;
    if ($siteId === null) {
        continue;
    }
    if (!array_key_exists($siteId, $siteAlertingCache)) {
        $siteAlertingCache[$siteId] = site_alerting_get($siteId);
    }
    $siteAlerting = $siteAlertingCache[$siteId];
    if ($siteAlerting === null || empty($siteAlerting['enabled'])) {
        continue;
    }
    $site = $device['site_name'] ?? '–';
    // Alle Texte dieses Geräts (Meldung, Erinnerung, Entwarnung, Fehler)
    // in der Alert-Sprache seines Standorts.
    current_lang_override(isset(WLANMON_LANGS[$siteAlerting['language'] ?? '']) ? $siteAlerting['language'] : 'de');
    $siteChannels = site_alerting_channels($siteAlerting);
    $notifyAllowed = alerting_schedule_allows_now($siteAlerting);

    $offlineAfterSeconds = max(60, (int) $siteAlerting['offline_after_minutes'] * 60);
    $consecutiveFailures = max(2, (int) $siteAlerting['consecutive_test_failures']);
    $repeatAfterSeconds = max(300, (int) $siteAlerting['repeat_after_minutes'] * 60);

    // --- Regel "offline" ---
    $referenceTime = $device['last_seen_at'] ?? $device['created_at'];
    $secondsSince = null;
    if (!empty($referenceTime)) {
        $ref = new DateTime((string) $referenceTime, new DateTimeZone('UTC'));
        $secondsSince = (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp() - $ref->getTimestamp();
    }
    $isOffline = $secondsSince === null || $secondsSince > $offlineAfterSeconds;
    $minutesSince = $secondsSince !== null ? (int) round($secondsSince / 60) : null;

    handle_condition(
        $deviceId,
        'offline',
        $isOffline,
        __('WLANMON: %s offline', $deviceId),
        $minutesSince !== null
            ? __('Gerät %s (Standort: %s) hat sich seit %d Minuten nicht mehr gemeldet.', $deviceId, $site, $minutesSince)
            : __('Gerät %s (Standort: %s) hat sich seit der Anlage des Geräts nicht mehr gemeldet.', $deviceId, $site),
        $repeatAfterSeconds,
        $siteChannels,
        $notifyAllowed
    );

    // Waehrend ein Geraet offline ist, sind SSID-Aussagen nur veraltete
    // Messwerte - keine zusaetzlichen (unter Umstaenden irrefuehrenden)
    // SSID-Alarme daraus ableiten.
    if ($isOffline) {
        continue;
    }

    // --- Regel "ssid_failing:<SSID>" ---
    // Die letzten $consecutiveFailures Connection-Tests je SSID sammeln.
    // Lookback grosszuegig (300), damit bei mehreren Ziel-SSIDs pro Geraet
    // genug Historie je SSID zusammenkommt.
    // Nebenbei je SSID: Fehlertext des neuesten Tests (fuer die Meldung) und
    // die letzten $consecutiveFailures 802.1X-Dauern (Regel "auth_slow").
    $bySsid = [];
    $lastError = [];
    $authBySsid = [];
    foreach (measurement_list($deviceId, 'connection_test', 300) as $t) {
        $data = json_decode((string) $t['data'], true) ?: [];
        $ssid = $data['ssid'] ?? null;
        if ($ssid === null) {
            continue;
        }
        if (!isset($bySsid[$ssid])) {
            $bySsid[$ssid] = [];
            // Aus den Fehlercodes in der Alarmsprache des Standorts (Override oben).
            $lastError[$ssid] = trim(probe_error_text($data));
        }
        if (count($bySsid[$ssid]) < $consecutiveFailures) {
            $bySsid[$ssid][] = !empty($data['connected']);
        }
        if (isset($data['auth_seconds'])) {
            $authBySsid[$ssid] ??= [];
            if (count($authBySsid[$ssid]) < $consecutiveFailures) {
                $authBySsid[$ssid][] = (float) $data['auth_seconds'];
            }
        }
    }

    // Nur SSIDs, die laut wirksamer Konfiguration (Profil oder eigene) noch
    // getestet werden. Wurde eine SSID entfernt, blieben ihre letzten Tests
    // sonst ewig "fehlgeschlagen" in der Historie - der Alarm ging nie weg.
    // Offene Alarme zu solchen SSIDs still schließen (keine Entwarnung - die
    // SSID wurde ja nicht repariert, sondern abbestellt). Ohne zentrale
    // Konfiguration (Probe nur mit lokaler config.yaml) bleibt alles wie bisher.
    $configured = configured_ssids($device);
    if ($configured !== null) {
        $bySsid = array_intersect_key($bySsid, $configured);
        $authBySsid = array_intersect_key($authBySsid, $configured);
        foreach (['ssid_failing:', 'auth_slow:', 'eap_abort_rate:'] as $prefix) {
            foreach (alert_active_rules_with_prefix($deviceId, $prefix) as $openRule) {
                if (!isset($configured[substr($openRule, strlen($prefix))])) {
                    $open = alert_find_active($deviceId, $openRule);
                    if ($open !== null) {
                        alert_resolve((int) $open['id']);
                        log_line("GESCHLOSSEN (SSID nicht mehr konfiguriert): $deviceId / $openRule");
                    }
                }
            }
        }
    }

    foreach ($bySsid as $ssid => $results) {
        // $results[0] ist der NEUESTE Test (measurement_list liefert DESC),
        // $results[1.. ] jeweils aeltere. Zwei eindeutige Faelle:
        //   - der neueste Test war erfolgreich -> definitiv erholt, sofort
        //     entwarnen (nicht erst nach $consecutiveFailures Erfolgen).
        //   - die letzten $consecutiveFailures Tests (ab dem neuesten)
        //     waren ALLE Fehlschlaege -> definitiv gestoert.
        // Dazwischen (neuester Test fehlgeschlagen, aber weniger als
        // $consecutiveFailures Fehlschlaege in Folge seit einem aelteren
        // Erfolg im Lookback-Fenster) bewusst NICHT anfassen: ohne diese
        // Unterscheidung wuerde ein laengst vergangener Erfolg im Fenster
        // eine ganz frische, noch andauernde Stoerung faelschlich als
        // "behoben" melden, obwohl der neueste Test gerade fehlgeschlagen
        // ist (in der Praxis beobachtet).
        $newestOk = $results[0] ?? null;
        $allFailing = count($results) >= $consecutiveFailures && !in_array(true, $results, true);
        if ($newestOk !== true && !$allFailing) {
            continue;
        }
        $message = __('Gerät %s (Standort: %s): SSID „%s“ ist bei den letzten %d Connection-Tests in Folge fehlgeschlagen.', $deviceId, $site, (string) $ssid, $consecutiveFailures);
        // Fehlertext des Probes (z.B. "TLS-Tunnel nicht aufgebaut")
        // gleich mitschicken - spart den Blick ins Dashboard.
        if ($allFailing && $lastError[$ssid] !== '') {
            $error = $lastError[$ssid];
            // Zeichen- statt Byte-genau kuerzen (ohne mbstring, s.o.).
            if (preg_match('/^.{300}/su', $error, $m) && strlen($m[0]) < strlen($error)) {
                $error = $m[0] . '…';
            }
            $message .= "\n" . __('Letzter Fehler: %s', $error);
        }
        handle_condition(
            $deviceId,
            'ssid_failing:' . $ssid,
            $allFailing,
            __('WLANMON: %s – SSID „%s“ fällt aus', $deviceId, (string) $ssid),
            $message,
            $repeatAfterSeconds,
            $siteChannels,
            $notifyAllowed
        );
    }

    // --- Regel "auth_slow:<SSID>" ---
    // 802.1X-Anmeldung (EAP + 4-Way-Handshake, auth_seconds der Probe) bei
    // den letzten $consecutiveFailures Tests mit 802.1X-Zeit JEDES MAL
    // langsamer als die Schwelle. "Jedes Mal" statt Durchschnitt, damit ein
    // einzelner Ausreisser keinen Alarm ausloest. Entwarnung wie bei
    // ssid_failing, sobald der neueste Wert wieder darunter liegt;
    // dazwischen (gemischt) Zustand unveraendert lassen.
    $authSlowSeconds = isset($siteAlerting['auth_slow_seconds']) ? (float) $siteAlerting['auth_slow_seconds'] : 0.0;
    foreach ($authBySsid as $ssid => $values) {
        $rule = 'auth_slow:' . $ssid;
        if ($authSlowSeconds <= 0) {
            // Regel abgeschaltet: einen noch offenen Alarm still schliessen,
            // statt ihn ewig offen zu halten oder eine Entwarnung zu senden.
            $open = alert_find_active($deviceId, $rule);
            if ($open !== null) {
                alert_resolve((int) $open['id']);
            }
            continue;
        }
        $newestFast = $values[0] <= $authSlowSeconds;
        $allSlow = count($values) >= $consecutiveFailures
            && min($values) > $authSlowSeconds;
        if (!$newestFast && !$allSlow) {
            continue;
        }
        handle_condition(
            $deviceId,
            $rule,
            $allSlow,
            __('WLANMON: %s – 802.1X an „%s“ langsam', $deviceId, (string) $ssid),
            __('Gerät %s (Standort: %s): Die 802.1X-Anmeldung an SSID „%s“ dauerte bei den letzten %d Tests jeweils länger als %s s (zuletzt %s s, Ø %s s). RADIUS-Server langsam oder schlecht erreichbar?',
                $deviceId, $site, (string) $ssid, count($values),
                number_format($authSlowSeconds, 1), number_format($values[0], 1),
                number_format(array_sum($values) / count($values), 1)),
            $repeatAfterSeconds,
            $siteChannels,
            $notifyAllowed
        );
    }
    // --- Regel "eap_abort_rate:<SSID>" ---
    // Anteil fehlgeschlagener Tests an einer 802.1X-SSID im Zeitfenster. Fängt
    // eine zeitweise hakende Anmeldung ab, die "ssid_failing" (alle letzten
    // Tests gescheitert) nie erreicht. Mindestens EAP_ABORT_MIN_FAILURES
    // Fehlschläge, damit ein einzelner Ausreißer bei wenigen Tests nichts
    // auslöst. Entwarnung erst unter der halben Schwelle (kein Flattern um
    // die Schwelle herum), dazwischen Zustand unverändert. Fällt die SSID
    // gerade ganz aus, meldet das schon "ssid_failing" - dann hier nichts tun.
    $abortRatePct = isset($siteAlerting['eap_abort_rate_pct']) ? (int) $siteAlerting['eap_abort_rate_pct'] : 0;
    $abortWindow = min(1440, max(10, (int) ($siteAlerting['eap_abort_window_minutes'] ?? 60)));
    $eapTests = [];
    if ($abortRatePct > 0) {
        $since = gmdate('Y-m-d H:i:s', time() - $abortWindow * 60);
        foreach (measurement_list_since($deviceId, 'connection_test', $since) as $t) {
            $data = json_decode((string) $t['data'], true) ?: [];
            $isEap = !empty($data['eap_method']) || stripos((string) ($data['security'] ?? ''), 'eap') !== false;
            if ($isEap && isset($data['ssid']) && ($configured === null || isset($configured[(string) $data['ssid']]))) {
                $eapTests[(string) $data['ssid']][] = $data;
            }
        }
    }
    foreach (alert_active_rules_with_prefix($deviceId, 'eap_abort_rate:') as $openRule) {
        // Regel abgeschaltet oder SSID nicht mehr getestet: still schließen.
        if ($abortRatePct <= 0 || !isset($eapTests[substr($openRule, strlen('eap_abort_rate:'))])) {
            $open = alert_find_active($deviceId, $openRule);
            if ($open !== null) {
                alert_resolve((int) $open['id']);
            }
        }
    }
    foreach ($eapTests as $ssid => $tests) {
        $failed = array_values(array_filter($tests, fn(array $d): bool => empty($d['connected'])));
        $total = count($tests);
        $failures = count($failed);
        $ratePct = $total > 0 ? 100 * $failures / $total : 0.0;
        // Totalausfall (die letzten Tests alle gescheitert) meldet "ssid_failing".
        $lastResults = $bySsid[$ssid] ?? [];
        if (count($lastResults) >= $consecutiveFailures && !in_array(true, $lastResults, true)) {
            continue;
        }
        $active = $failures >= EAP_ABORT_MIN_FAILURES && $ratePct >= $abortRatePct;
        $cleared = $failures < EAP_ABORT_MIN_FAILURES || $ratePct < $abortRatePct / 2;
        if (!$active && !$cleared) {
            continue;
        }
        // Häufigste Fehlerursache in der Alarmsprache des Standorts.
        $reasons = array_count_values(array_filter(array_map(fn(array $d): string => trim(probe_error_text($d)), $failed)));
        arsort($reasons);
        $topReason = (string) (array_key_first($reasons) ?? '');
        $message = __('Gerät %s (Standort: %s): An SSID „%s“ sind in den letzten %d Minuten %d von %d Tests gescheitert (%d %%).',
            $deviceId, $site, (string) $ssid, $abortWindow, $failures, $total, (int) round($ratePct));
        if ($topReason !== '') {
            $message .= "\n" . __('Häufigste Ursache (%d×): %s', $reasons[$topReason], $topReason);
        }
        handle_condition(
            $deviceId,
            'eap_abort_rate:' . $ssid,
            $active,
            __('WLANMON: %s – 802.1X an „%s“ bricht oft ab (%d %%)', $deviceId, (string) $ssid, (int) round($ratePct)),
            $message,
            $repeatAfterSeconds,
            $siteChannels,
            $notifyAllowed
        );
    }
}

log_line('Durchlauf abgeschlossen.');
