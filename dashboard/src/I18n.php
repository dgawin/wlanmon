<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/*
 * Mehrsprachigkeit (Deutsch/Englisch) der Weboberfläche.
 *
 * Der deutsche Text ist der Schlüssel: __('Verlauf') liefert auf Englisch
 * die Übersetzung aus lang/en.php, sonst den Text selbst. Fehlt eine
 * Übersetzung, erscheint also der deutsche Text - es geht nie etwas kaputt,
 * und neue Texte werden weiter einfach auf Deutsch geschrieben.
 *
 * Welche Sprache gilt: Benutzerkonto (users.language) > Cookie
 * (wlanmon_lang, z.B. auf der Login-Seite gewählt) > Browsersprache
 * (Accept-Language) > Deutsch.
 */

/** Verfügbare Sprachen, Anzeige im Benutzermenü in der jeweils eigenen Sprache. */
const WLANMON_LANGS = ['de' => 'Deutsch', 'en' => 'English'];

const WLANMON_LANG_COOKIE = 'wlanmon_lang';

function current_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $userLang = user_lang_from_db();
    if ($userLang !== null) {
        return $lang = $userLang;
    }
    $cookie = (string) ($_COOKIE[WLANMON_LANG_COOKIE] ?? '');
    if (isset(WLANMON_LANGS[$cookie])) {
        return $lang = $cookie;
    }
    return $lang = lang_from_accept_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}

/** Für Tests und für den Wechsel innerhalb eines Requests. */
function current_lang_override(?string $lang): void
{
    // current_lang() hat einen statischen Cache - hier über einen eigenen
    // Speicher umgehen, den __() zuerst fragt.
    $GLOBALS['__wlanmon_lang_override'] = $lang !== null && isset(WLANMON_LANGS[$lang]) ? $lang : null;
}

/**
 * $fn in der Sprache $lang ausführen (z.B. Alert-Texte in der Sprache des
 * Standorts), danach gilt wieder die vorherige Sprache. Unbekannte oder
 * leere Sprache = Deutsch.
 *
 * @return mixed Rückgabewert von $fn
 */
function with_lang(?string $lang, callable $fn)
{
    $previous = $GLOBALS['__wlanmon_lang_override'] ?? null;
    current_lang_override($lang !== null && isset(WLANMON_LANGS[$lang]) ? $lang : 'de');
    try {
        return $fn();
    } finally {
        $GLOBALS['__wlanmon_lang_override'] = $previous;
    }
}

function effective_lang(): string
{
    return $GLOBALS['__wlanmon_lang_override'] ?? current_lang();
}

/** Erste von uns unterstützte Sprache aus dem Accept-Language-Header, sonst Deutsch. */
function lang_from_accept_header(string $header): string
{
    $best = null;
    $bestQ = -1.0;
    foreach (explode(',', $header) as $i => $part) {
        $bits = explode(';', trim($part));
        $code = strtolower(substr(trim($bits[0]), 0, 2));
        $q = 1.0;
        foreach (array_slice($bits, 1) as $b) {
            if (preg_match('/^\s*q=([0-9.]+)/', $b, $m)) {
                $q = (float) $m[1];
            }
        }
        // Bei gleicher Gewichtung gewinnt die frühere Angabe.
        if (isset(WLANMON_LANGS[$code]) && $q > $bestQ) {
            $best = $code;
            $bestQ = $q;
        }
    }
    return $best ?? 'de';
}

/**
 * Sprache des angemeldeten Benutzers aus users.language, oder null
 * (nicht angemeldet, nichts gewählt, Spalte in einer älteren Installation
 * noch nicht vorhanden).
 */
function user_lang_from_db(): ?string
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT language FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $lang = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    return is_string($lang) && isset(WLANMON_LANGS[$lang]) ? $lang : null;
}

/**
 * Sprache wählen: Cookie immer (gilt auch vor dem Login), beim angemeldeten
 * Benutzer zusätzlich im Konto. Fehlt die Spalte users.language noch
 * (ältere Installation), wird sie angelegt; klappt das nicht (fehlende
 * ALTER-Rechte), bleibt es beim Cookie.
 */
function lang_set(string $lang): void
{
    if (!isset(WLANMON_LANGS[$lang])) {
        return;
    }
    setcookie(WLANMON_LANG_COOKIE, $lang, [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[WLANMON_LANG_COOKIE] = $lang;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return;
    }
    $update = static function () use ($lang, $userId): void {
        db()->prepare('UPDATE users SET language = ? WHERE id = ?')->execute([$lang, $userId]);
    };
    try {
        $update();
    } catch (PDOException $e) {
        try {
            db()->exec('ALTER TABLE users ADD COLUMN language VARCHAR(5) NULL');
            $update();
        } catch (PDOException $e2) {
            error_log('lang_set: users.language nicht verfügbar (' . $e2->getMessage() . ') - nur Cookie gesetzt');
        }
    }
}

/**
 * Übersetzung des deutschen Texts $de in die aktuelle Sprache; weitere
 * Argumente werden per sprintf eingesetzt (__('%d Netze', $n)). Liefert
 * Rohtext - in HTML immer mit e() bzw. te() ausgeben.
 */
function __(string $de, ...$args): string
{
    static $en = null;
    $text = $de;
    if (effective_lang() === 'en') {
        $en ??= require __DIR__ . '/../lang/en.php';
        $text = $en[$de] ?? $de;
    }
    return $args ? vsprintf($text, $args) : $text;
}

/** __() und direkt HTML-escaped - Kurzform für Templates. */
function te(string $de, ...$args): string
{
    return htmlspecialchars(__($de, ...$args), ENT_QUOTES, 'UTF-8');
}

/**
 * Wie te(), setzt aber fertiges HTML für die %s ein - für Sätze mit Link
 * oder <code> mittendrin, die als Ganzes übersetzt werden sollen:
 * z.B. Satz „Siehe %s.“ mit einem Link als Argument.
 * Die Argumente werden NICHT escaped, also nur eigenes Markup übergeben.
 */
function teh(string $de, string ...$html): string
{
    return vsprintf(te($de), $html);
}

/**
 * Übersetzter Text als JavaScript-String-Literal für ein HTML-Attribut,
 * z.B. onsubmit="return confirm(<?= tjs('Diese Messung löschen?') ?>);".
 * json_encode liefert das JS-Literal, e() macht es attributsicher (der
 * Browser dekodiert &quot; vor dem Ausführen wieder zu ").
 */
function tjs(string $de, ...$args): string
{
    return htmlspecialchars(
        (string) json_encode(__($de, ...$args), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Übersetzter Text als JavaScript-String-Literal innerhalb eines
 * <script>-Blocks (dort wird nicht HTML-dekodiert, daher kein e()).
 */
function tjson(string $de, ...$args): string
{
    return (string) json_encode(__($de, ...$args), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

/**
 * Übersetzungen für JavaScript (Spektrum/Verlauf): deutscher Text => Text in
 * der aktuellen Sprache, als JSON-Objekt für ein <script>-Tag.
 *
 * @param array<int, string> $keys
 */
function i18n_js(array $keys): string
{
    $map = [];
    foreach ($keys as $k) {
        $map[$k] = __($k);
    }
    return json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/**
 * Datumsformat für die aktuelle Sprache: die deutschen Muster aus den
 * Templates (d.m.Y ...) werden auf Englisch zu Y-m-d bzw. m/d. Unbekannte
 * Muster bleiben unverändert.
 */
function lang_date_format(string $format): string
{
    if (effective_lang() !== 'en') {
        return $format;
    }
    return strtr($format, [
        'd.m.Y' => 'Y-m-d',
        'd.m.' => 'm/d',
    ]);
}
