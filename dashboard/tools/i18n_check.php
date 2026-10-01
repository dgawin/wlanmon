<?php
declare(strict_types=1);

/*
 * Prüft lang/en.php gegen die Texte im Code: php tools/i18n_check.php
 *
 * - fehlend: __()/te()/teh()/tjs()/tjson() in PHP bzw. tt() in static/*.js ohne
 *   englischen Eintrag (erscheint dann auf Deutsch)
 * - ungenutzt: Einträge in en.php, die im Code nicht mehr vorkommen
 * - Platzhalter: %s/%d/%% in Übersetzung und Original unterschiedlich
 *
 * Exit-Code 1, wenn etwas fehlt oder Platzhalter nicht passen.
 */

$root = dirname(__DIR__);
$en = require $root . '/lang/en.php';

// Skripte im Projekt-Root (z.B. check_alerts.php mit den Alert-Texten).
$files = glob($root . '/*.php') ?: [];
foreach (['src', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (preg_match('/\.(php|js)$/', $f->getFilename())) {
            $files[] = $f->getPathname();
        }
    }
}

$used = [];
foreach ($files as $path) {
    $src = (string) file_get_contents($path);
    $fn = substr($path, -3) === '.js' ? 'tt' : '(?:__|te|teh|tjs|tjson)';
    preg_match_all('/(?<![\w$>])' . $fn . "\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $src, $m);
    foreach ($m[1] as $key) {
        $used[stripcslashes($key)][] = substr($path, strlen($root) + 1);
    }
}

// Argumentnummer => Typ. Nummerierte Platzhalter (%5$s) zählen wie ihre
// Position im Original - eine Übersetzung darf die Reihenfolge im Satz ändern.
$placeholders = static function (string $s): array {
    preg_match_all('/%(?:(\d+)\$)?([sd%])/', $s, $m, PREG_SET_ORDER);
    $args = [];
    $next = 1;
    foreach ($m as $p) {
        if ($p[2] === '%') {
            continue;
        }
        $args[$p[1] !== '' ? (int) $p[1] : $next++] = $p[2];
    }
    ksort($args);
    return $args;
};

$missing = array_diff_key($used, $en);
$unused = array_diff_key($en, $used);
$badPh = [];
foreach ($en as $de => $text) {
    if ($placeholders($de) !== $placeholders($text)) {
        $badPh[] = $de;
    }
}

printf("%d Texte im Code, %d Übersetzungen\n", count($used), count($en));
foreach ($missing as $key => $where) {
    printf("fehlt:      %s  (%s)\n", $key, implode(', ', array_unique($where)));
}
foreach ($unused as $key => $_) {
    printf("ungenutzt:  %s\n", $key);
}
foreach ($badPh as $key) {
    printf("Platzhalter passen nicht: %s\n", $key);
}
exit($missing || $badPh ? 1 : 0);
