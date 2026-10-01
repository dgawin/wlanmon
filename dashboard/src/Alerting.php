<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/Secrets.php';

/**
 * Gemeinsame SMTP-/Telegram-Zugangsdaten (Tabelle "settings", Schluessel
 * "alerting"), ueber /settings/alerting im Web gepflegt (Admin-only) -
 * nicht in config.php, analog zu devices.config bei der Geraete-Config.
 * Enthaelt NUR die Kanal-Zugangsdaten (smtp_host/port/user/pass/secure/
 * from/from_name, bot_token) - OB/AN WEN/WANN je Standort alarmiert wird,
 * steht in der Tabelle "site_alerting" (siehe site_alerting_get() unten).
 * Noch nie gespeichert -> leeres Array.
 */
function alerting_config(): array
{
    // SMTP-Passwort und Bot-Token liegen verschlüsselt (src/Secrets.php).
    return secrets_map(setting_get('alerting') ?? [], ALERTING_SECRET_PATHS, static function (string $v): string {
        $plain = secret_decrypt($v);
        if ($plain === null) {
            error_log('[wlanmon-alert] Alerting-Zugangsdaten nicht entschlüsselbar (secret_key fehlt oder falsch)');
            return '';
        }
        return $plain;
    });
}

// ---------------------------------------------------------------------
// Alerting-Regeln je Site (Tabelle "site_alerting", siehe schema.sql und
// /sites/<id>/alerting in public/index.php).
// ---------------------------------------------------------------------

/** @return array<string, mixed>|null null, wenn fuer diese Site noch nie gespeichert wurde. */
function site_alerting_get(int $siteId): ?array
{
    $stmt = db()->prepare('SELECT * FROM site_alerting WHERE site_id = ?');
    $stmt->execute([$siteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Upsert wie setting_set() - $data-Keys entsprechen den Spalten von
 * site_alerting (siehe schema.sql), email_to wird als JSON gespeichert.
 */
function site_alerting_set(int $siteId, array $data): void
{
    try {
        site_alerting_write($siteId, $data);
    } catch (PDOException $e) {
        // Ältere Installation ohne Spalte "language": einmalig anlegen und
        // erneut speichern (wie users.language, siehe lang_set()).
        db()->exec('ALTER TABLE site_alerting ADD COLUMN language VARCHAR(5) NULL');
        site_alerting_write($siteId, $data);
    }
}

function site_alerting_write(int $siteId, array $data): void
{
    $stmt = db()->prepare(
        'INSERT INTO site_alerting (
            site_id, enabled, offline_after_minutes, consecutive_test_failures, repeat_after_minutes,
            email_enabled, email_to, telegram_enabled, telegram_chat_id,
            schedule_mode, schedule_days, schedule_start_hour, schedule_end_hour, language
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            offline_after_minutes = VALUES(offline_after_minutes),
            consecutive_test_failures = VALUES(consecutive_test_failures),
            repeat_after_minutes = VALUES(repeat_after_minutes),
            email_enabled = VALUES(email_enabled),
            email_to = VALUES(email_to),
            telegram_enabled = VALUES(telegram_enabled),
            telegram_chat_id = VALUES(telegram_chat_id),
            schedule_mode = VALUES(schedule_mode),
            schedule_days = VALUES(schedule_days),
            schedule_start_hour = VALUES(schedule_start_hour),
            schedule_end_hour = VALUES(schedule_end_hour),
            language = VALUES(language)'
    );
    $stmt->execute([
        $siteId,
        !empty($data['enabled']) ? 1 : 0,
        $data['offline_after_minutes'],
        $data['consecutive_test_failures'],
        $data['repeat_after_minutes'],
        !empty($data['email_enabled']) ? 1 : 0,
        json_encode($data['email_to'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        !empty($data['telegram_enabled']) ? 1 : 0,
        $data['telegram_chat_id'] ?? null,
        $data['schedule_mode'] ?? 'always',
        $data['schedule_days'] ?? null,
        $data['schedule_start_hour'] ?? null,
        $data['schedule_end_hour'] ?? null,
        isset(WLANMON_LANGS[$data['language'] ?? '']) ? $data['language'] : null,
    ]);
}

/**
 * Fuegt die gemeinsamen Kanal-Zugangsdaten (alerting_config()) mit den
 * Site-spezifischen Ein/Aus-Schaltern und Empfaengern zu der Form
 * zusammen, die send_alert_email()/send_alert_telegram() erwarten.
 */
function site_alerting_channels(array $siteAlerting): array
{
    $creds = alerting_config();
    $emailTo = json_decode((string) ($siteAlerting['email_to'] ?? '[]'), true);
    return [
        'email' => array_merge((array) ($creds['email'] ?? []), [
            'enabled' => !empty($siteAlerting['email_enabled']),
            'to' => is_array($emailTo) ? $emailTo : [],
        ]),
        'telegram' => [
            'enabled' => !empty($siteAlerting['telegram_enabled']),
            'bot_token' => (string) ($creds['telegram']['bot_token'] ?? ''),
            'chat_id' => (string) ($siteAlerting['telegram_chat_id'] ?? ''),
        ],
    ];
}

/**
 * Zeitfenster-Pruefung: 'always' (durchgaengig) ist immer erlaubt; bei
 * 'custom' muss der aktuelle Wochentag (Europe/Berlin, wie die restliche
 * Anzeige - siehe DISPLAY_TIMEZONE in Response.php) in schedule_days
 * stehen UND die aktuelle Stunde im [start, end)-Bereich liegen (Ende
 * exklusiv: 20 = bis 19:59).
 */
function alerting_schedule_allows_now(array $siteAlerting): bool
{
    if (($siteAlerting['schedule_mode'] ?? 'always') !== 'custom') {
        return true;
    }
    $now = new DateTime('now', new DateTimeZone(DISPLAY_TIMEZONE));
    $isoWeekday = (int) $now->format('N'); // 1=Mo..7=So
    $allowedDays = array_map('intval', array_filter(
        explode(',', (string) ($siteAlerting['schedule_days'] ?? '')),
        fn($d) => $d !== ''
    ));
    if (!in_array($isoWeekday, $allowedDays, true)) {
        return false;
    }
    $hour = (int) $now->format('G');
    $start = (int) ($siteAlerting['schedule_start_hour'] ?? 0);
    $end = (int) ($siteAlerting['schedule_end_hour'] ?? 24);
    return $hour >= $start && $hour < $end;
}

// ---------------------------------------------------------------------
// Alert-Zustand (Tabelle "alerts", siehe schema.sql). Ein Alert ist
// "aktiv", solange resolved_at NULL ist. Pro (device_id, rule) darf es
// zu jedem Zeitpunkt hoechstens eine aktive Zeile geben - das stellt
// check_alerts.php sicher (erst alert_find_active(), dann entscheiden).
// ---------------------------------------------------------------------

function alert_find_active(string $deviceId, string $rule): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM alerts WHERE device_id = ? AND rule = ? AND resolved_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$deviceId, $rule]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function alert_open(string $deviceId, string $rule): int
{
    $now = utc_now();
    $stmt = db()->prepare(
        'INSERT INTO alerts (device_id, rule, first_seen_at, last_notified_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$deviceId, $rule, $now, $now]);
    return (int) db()->lastInsertId();
}

function alert_touch(int $id): void
{
    $stmt = db()->prepare('UPDATE alerts SET last_notified_at = ? WHERE id = ?');
    $stmt->execute([utc_now(), $id]);
}

function alert_resolve(int $id): void
{
    $stmt = db()->prepare('UPDATE alerts SET resolved_at = ? WHERE id = ?');
    $stmt->execute([utc_now(), $id]);
}

/**
 * Schreibt den Alert-Zustand fuer (deviceId, rule) anhand von $active fort
 * und loest bei Bedarf eine Benachrichtigung aus (siehe check_alerts.php):
 *   - $active=true, keine aktive Zeile -> neu anlegen + sofort melden.
 *   - $active=true, aktive Zeile vorhanden, aber schon lange nicht mehr
 *     erinnert -> last_notified_at auffrischen + erneut melden.
 *   - $active=true, aktive Zeile vorhanden, erst kuerzlich erinnert ->
 *     nichts tun (kein Spam).
 *   - $active=false, aktive Zeile vorhanden -> aufloesen + Entwarnung.
 *   - $active=false, keine aktive Zeile -> nichts tun (Normalzustand).
 * $notifyAllowed = false (Zeitfenster der Site gerade zu, siehe
 * alerting_schedule_allows_now()): Zustand wird trotzdem immer aktuell
 * gehalten, nur der tatsaechliche Versand (dispatch_alert()) entfaellt -
 * und last_notified_at bleibt bei einer unterdrueckten Erinnerung
 * bewusst unangetastet, damit die erste ECHTE Zustellung nach
 * Fensteroeffnung nicht zusaetzlich um ein volles repeatAfterSeconds
 * verzoegert wird.
 * Gibt zur Diagnose/zum Testen zurueck, was passiert ist ("neu"/
 * "erinnerung"/"entwarnung"/"unveraendert").
 */
function alert_handle_condition(
    string $deviceId,
    string $rule,
    bool $active,
    string $subject,
    string $message,
    int $repeatAfterSeconds,
    array $siteChannels,
    bool $notifyAllowed
): string {
    $existing = alert_find_active($deviceId, $rule);

    if ($active) {
        if ($existing === null) {
            alert_open($deviceId, $rule);
            if ($notifyAllowed) {
                dispatch_alert($siteChannels, $subject, $message);
            }
            return 'neu';
        }
        $lastNotified = new DateTime((string) $existing['last_notified_at'], new DateTimeZone('UTC'));
        $elapsed = (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp() - $lastNotified->getTimestamp();
        if ($elapsed >= $repeatAfterSeconds) {
            if (!$notifyAllowed) {
                return 'unveraendert';
            }
            alert_touch((int) $existing['id']);
            dispatch_alert($siteChannels, $subject, $message . "\n\n(" . __('anhaltend seit %s', format_local((string) $existing['first_seen_at'], 'd.m.Y H:i')) . ')');
            return 'erinnerung';
        }
        return 'unveraendert';
    }

    if ($existing !== null) {
        alert_resolve((int) $existing['id']);
        if ($notifyAllowed) {
            dispatch_alert($siteChannels, __('Entwarnung') . ' – ' . $subject, __('Behoben:') . ' ' . $message);
        }
        return 'entwarnung';
    }
    return 'unveraendert';
}

// ---------------------------------------------------------------------
// Versandkanaele. Jede send_*-Funktion wirft nie, sondern liefert
// ['ok' => bool, 'error' => ?string] - ein einzelner kaputter Kanal darf
// weder den Lauf abbrechen noch die anderen Kanaele verhindern.
// ---------------------------------------------------------------------

/**
 * E-Mail-Header-Wert nach RFC 2047 kodieren (Base64/"B"-Form), falls er
 * Nicht-ASCII-Zeichen enthaelt - von Hand statt ueber mb_encode_mimeheader(),
 * das die optionale mbstring-Extension braucht (auf einem schlanken
 * PHP-Setup nicht garantiert vorhanden, siehe Telegram-Versand fuer
 * dasselbe Prinzip bei curl).
 */
function mime_encode_header(string $text): string
{
    if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Minimaler, selbstgeschriebener SMTP-Client (EHLO -> optional STARTTLS
 * -> optional AUTH LOGIN -> MAIL/RCPT/DATA -> QUIT) statt einer
 * Bibliothek wie PHPMailer: das Projekt hat bewusst keinen Composer/
 * vendor-Ordner (kein Build-Schritt, ein einzelnes handgepflegtes
 * Repo). Deckt die ueblichen Faelle ab (STARTTLS auf Port 587, implizites
 * TLS auf Port 465, Klartext/kein Auth fuer einen internen Relay) - kein
 * vollstaendiger RFC-5321-Client (z.B. keine Anhaenge, kein Pipelining).
 */
function send_alert_email(array $cfg, string $subject, string $body): array
{
    $host = trim((string) ($cfg['smtp_host'] ?? ''));
    $port = (int) ($cfg['smtp_port'] ?? 587);
    $user = (string) ($cfg['smtp_user'] ?? '');
    $pass = (string) ($cfg['smtp_pass'] ?? '');
    $secure = strtolower((string) ($cfg['smtp_secure'] ?? 'tls')); // "tls" (STARTTLS), "ssl" (implizit), "none"
    $from = trim((string) ($cfg['from'] ?? ''));
    $fromName = (string) ($cfg['from_name'] ?? 'WLANMON');
    $to = array_values(array_filter(array_map('trim', (array) ($cfg['to'] ?? []))));

    if ($host === '' || $from === '' || empty($to)) {
        return ['ok' => false, 'error' => __('E-Mail-Alarmierung unvollständig konfiguriert (smtp_host/from/to)')];
    }

    $transportPrefix = $secure === 'ssl' ? 'ssl://' : '';
    $fp = @fsockopen($transportPrefix . $host, $port, $errno, $errstr, 10);
    if ($fp === false) {
        return ['ok' => false, 'error' => __('Verbindung zu %s fehlgeschlagen:', "$host:$port") . ' ' . $errstr];
    }
    stream_set_timeout($fp, 10);

    // Liest eine (ggf. mehrzeilige) SMTP-Antwort vollstaendig ein: Folgezeilen
    // haben ein "-" an Position 3 (z.B. "250-STARTTLS"), die letzte Zeile ein
    // Leerzeichen (z.B. "250 OK").
    $readResponse = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $data;
    };
    $expectCode = function (string $resp, string $code) : ?string {
        // strpos()-Vergleich statt der neueren str_starts_with-Funktion
        // (die gibt es erst ab PHP 8.0) - Projekt zielt laut README auf
        // PHP 7.4 und aufwaerts.
        if (strpos($resp, $code) !== 0) {
            return __('unerwartete SMTP-Antwort (erwartet %s):', $code) . ' ' . trim($resp);
        }
        return null;
    };
    $sendLine = function (string $line) use ($fp): void {
        fwrite($fp, $line . "\r\n");
    };

    try {
        $resp = $readResponse();
        if (($err = $expectCode($resp, '220')) !== null) {
            return ['ok' => false, 'error' => $err];
        }

        $sendLine('EHLO wlanmon');
        $resp = $readResponse();
        if (($err = $expectCode($resp, '250')) !== null) {
            return ['ok' => false, 'error' => $err];
        }

        if ($secure === 'tls') {
            $sendLine('STARTTLS');
            $resp = $readResponse();
            if (($err = $expectCode($resp, '220')) !== null) {
                return ['ok' => false, 'error' => $err];
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return ['ok' => false, 'error' => __('STARTTLS-Handshake fehlgeschlagen')];
            }
            // Nach STARTTLS ist ein erneutes EHLO Pflicht (RFC 3207).
            $sendLine('EHLO wlanmon');
            $resp = $readResponse();
            if (($err = $expectCode($resp, '250')) !== null) {
                return ['ok' => false, 'error' => $err];
            }
        }

        if ($user !== '') {
            $sendLine('AUTH LOGIN');
            $resp = $readResponse();
            if (($err = $expectCode($resp, '334')) !== null) {
                return ['ok' => false, 'error' => $err];
            }
            $sendLine(base64_encode($user));
            $resp = $readResponse();
            if (($err = $expectCode($resp, '334')) !== null) {
                return ['ok' => false, 'error' => $err];
            }
            $sendLine(base64_encode($pass));
            $resp = $readResponse();
            if (($err = $expectCode($resp, '235')) !== null) {
                return ['ok' => false, 'error' => __('SMTP-Login fehlgeschlagen:') . ' ' . trim($resp)];
            }
        }

        $sendLine('MAIL FROM:<' . $from . '>');
        $resp = $readResponse();
        if (($err = $expectCode($resp, '250')) !== null) {
            return ['ok' => false, 'error' => $err];
        }

        foreach ($to as $rcpt) {
            $sendLine('RCPT TO:<' . $rcpt . '>');
            $resp = $readResponse();
            if (($err = $expectCode($resp, '250')) !== null) {
                return ['ok' => false, 'error' => "RCPT TO $rcpt: $err"];
            }
        }

        $sendLine('DATA');
        $resp = $readResponse();
        if (($err = $expectCode($resp, '354')) !== null) {
            return ['ok' => false, 'error' => $err];
        }

        $headers = [
            'From: ' . ($fromName !== '' ? mime_encode_header($fromName) . ' ' : '') . '<' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . mime_encode_header($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@wlanmon>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        // Dot-Stuffing: eine Zeile, die mit "." beginnt, wuerde vom Server
        // sonst als Ende von DATA (nur "." auf eigener Zeile) missverstanden.
        $bodyStuffed = preg_replace('/^\./m', '..', $body);
        $sendLine(implode("\r\n", $headers) . "\r\n\r\n" . $bodyStuffed . "\r\n.");
        $resp = $readResponse();
        if (($err = $expectCode($resp, '250')) !== null) {
            return ['ok' => false, 'error' => $err];
        }

        $sendLine('QUIT');
        return ['ok' => true, 'error' => null];
    } finally {
        fclose($fp);
    }
}

/** Telegram Bot API (https://core.telegram.org/bots/api#sendmessage). */
function send_alert_telegram(array $cfg, string $message): array
{
    $token = trim((string) ($cfg['bot_token'] ?? ''));
    $chatId = trim((string) ($cfg['chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'error' => __('Telegram-Alarmierung unvollständig konfiguriert (bot_token/chat_id)')];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = json_encode(['chat_id' => $chatId, 'text' => $message], JSON_UNESCAPED_UNICODE);

    $status = 0;
    $response = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($curlErrno !== 0) {
            return ['ok' => false, 'error' => __('curl-Fehler:') . ' ' . $curlError];
        }
    } else {
        // Fallback ohne curl-Extension: file_get_contents mit HTTP-Stream-Context.
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    }

    if ($response === false) {
        return ['ok' => false, 'error' => __('HTTP-Anfrage an Telegram fehlgeschlagen')];
    }
    $decoded = json_decode((string) $response, true);
    if ($status !== 200 || !is_array($decoded) || empty($decoded['ok'])) {
        $desc = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
        return ['ok' => false, 'error' => __('Telegram-API-Fehler (HTTP %s):', (string) $status) . ' ' . $desc];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Verteilt eine Meldung an alle aktivierten Kanaele von $siteChannels
 * (siehe site_alerting_channels() - email.enabled/telegram.enabled sind
 * dort bereits die Site-spezifischen Schalter). Ein fehlschlagender Kanal
 * wird geloggt (error_log), aber wirft nie - ein kaputtes SMTP-Passwort
 * soll Telegram nicht mit ausknocken und umgekehrt. Liest selbst keine
 * Config (auch nicht den Site-"enabled"-Hauptschalter oder das
 * Zeitfenster) - das entscheidet der Aufrufer (check_alerts.php bzw. der
 * "Testalarm senden"-Button, der bewusst auch bei ausgeschaltetem
 * Hauptschalter zustellen soll).
 *
 * @return array<string, array{ok: bool, error: ?string}> Ergebnis je
 *   tatsaechlich versuchtem Kanal ("email"/"telegram") - fuer
 *   check_alerts.php irrelevant (nur geloggt), fuer den
 *   "Testalarm senden"-Button die Grundlage der Erfolgs-/Fehleranzeige
 *   je Kanal.
 */
function dispatch_alert(array $siteChannels, string $subject, string $message): array
{
    $results = [];
    if (!empty($siteChannels['email']['enabled'] ?? false)) {
        $result = send_alert_email((array) ($siteChannels['email'] ?? []), $subject, $message);
        $results['email'] = $result;
        if (!$result['ok']) {
            error_log('[wlanmon-alert] E-Mail fehlgeschlagen: ' . $result['error']);
        }
    }
    if (!empty($siteChannels['telegram']['enabled'] ?? false)) {
        $result = send_alert_telegram((array) ($siteChannels['telegram'] ?? []), "$subject\n\n$message");
        $results['telegram'] = $result;
        if (!$result['ok']) {
            error_log('[wlanmon-alert] Telegram fehlgeschlagen: ' . $result['error']);
        }
    }
    return $results;
}
