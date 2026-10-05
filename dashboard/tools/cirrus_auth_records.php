#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Authentifizierungs-Historie aus OmniVista Cirrus abrufen (Diagnose, nur lesend):
 *
 *   php tools/cirrus_auth_records.php [minuten] [--mac=aa:bb:cc:dd:ee:ff] [--raw]
 *
 *   minuten   Zeitraum bis jetzt (Standard 60)
 *   --mac     nur Eintraege, in denen diese MAC vorkommt
 *   --raw     die ersten Eintraege vollstaendig als JSON statt nur der Feldstruktur
 *
 * Nutzt denselben Login wie sync_cirrus_aps.php (Block "cirrus" in config.php)
 * und denselben, nicht dokumentierten Endpunkt wie die Cirrus-Oberflaeche
 * (Access -> Authentication History). Die Ausgabe kann Benutzernamen und
 * MAC-Adressen enthalten.
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Cirrus.php';

/** array_is_list() gibt es erst ab PHP 8.1. */
function is_list(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

$minutes = 60;
$mac = null;
$raw = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--raw') {
        $raw = true;
    } elseif (strpos($arg, '--mac=') === 0) {
        $mac = cirrus_normalize_mac(substr($arg, 6));
    } elseif (ctype_digit($arg)) {
        $minutes = max(1, (int) $arg);
    } else {
        fwrite(STDERR, "Unbekanntes Argument: $arg\n");
        exit(2);
    }
}

$cfg = cirrus_config();
if ($cfg === null) {
    fwrite(STDERR, "Kein vollstaendiger \"cirrus\"-Block in config.php.\n");
    exit(1);
}
$token = cirrus_authenticate($cfg);
if ($token === null) {
    fwrite(STDERR, "Anmeldung bei Cirrus fehlgeschlagen (Details im PHP-Fehlerlog).\n");
    exit(1);
}

$end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$start = $end->modify("-{$minutes} minutes");
$query = http_build_query([
    'limit' => 50,
    'offset' => 0,
    'startDate' => $start->format(DATE_ATOM),
    'endDate' => $end->format(DATE_ATOM),
    'sort' => '[{"sessionStart":"DESC"}]',
    'filters' => '{}',
], '', '&', PHP_QUERY_RFC3986);
$url = $cfg['base_url'] . '/api/organizations/' . rawurlencode((string) $cfg['org_id'])
    . '/am/access-records/authentication-history-records?' . $query;

$result = cirrus_http_request($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, 30);
if ($result === null) {
    fwrite(STDERR, "HTTP-Anfrage fehlgeschlagen (Netzwerk/Timeout).\n");
    exit(1);
}
[$status, $body] = $result;
echo "HTTP $status, " . strlen($body) . " Byte, Zeitraum {$minutes} min\n";
$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    echo "Keine JSON-Antwort. Anfang:\n" . substr($body, 0, 500) . "\n";
    exit(1);
}

// Die Liste der Eintraege steckt je nach Endpunkt unter data, data.result o.ae.
$records = null;
foreach ([['data', 'list'], ['data', 'result'], ['data', 'records'], ['data'], ['result'], ['records']] as $path) {
    $node = $decoded;
    foreach ($path as $key) {
        $node = is_array($node) ? ($node[$key] ?? null) : null;
    }
    if (is_array($node) && is_list($node)) {
        $records = $node;
        echo 'Eintraege unter: ' . implode('.', $path) . "\n";
        break;
    }
}
if ($records === null) {
    echo "Keine Liste gefunden. Oberste Ebene: " . implode(', ', array_keys($decoded)) . "\n";
    echo substr(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 1500) . "\n";
    exit(1);
}
$total = $decoded['data']['total'] ?? $decoded['total'] ?? null;
echo 'Anzahl: ' . count($records) . ($total !== null ? " (total $total)" : '') . "\n";

if ($mac !== null) {
    $records = array_values(array_filter($records, function ($r) use ($mac): bool {
        $flat = strtolower((string) json_encode($r));
        $plain = str_replace(':', '', $mac);
        return strpos($flat, $mac) !== false || strpos(str_replace(['-', ':'], '', $flat), $plain) !== false;
    }));
    echo "Davon mit MAC $mac: " . count($records) . "\n";
}
if ($records === []) {
    exit(0);
}

if ($raw) {
    echo json_encode(array_slice($records, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

/** Feldstruktur eines Eintrags: Schluessel, Typ, gekuerzter Beispielwert. */
function describe($value, string $prefix = ''): void
{
    foreach ($value as $key => $v) {
        $name = $prefix . $key;
        if (is_array($v) && $v !== [] && !is_list($v)) {
            describe($v, $name . '.');
            continue;
        }
        $shown = is_array($v) ? json_encode($v) : var_export($v, true);
        printf("  %-40s %-7s %s\n", $name, gettype($v), (strlen((string) $shown) > 60 ? substr((string) $shown, 0, 59) . '…' : (string) $shown));
    }
}

echo "\nFelder des neuesten Eintrags:\n";
describe($records[0]);
