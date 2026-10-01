<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/I18n.php';

// ---------------------------------------------------------------------
// Login/Sessions fuer die Dashboard-Weboberflaeche (Tabelle "users",
// siehe schema.sql). Getrennt von der Basic-Auth in src/admin_auth.php,
// die weiterhin die /api/v1/admin/*-Endpunkte fuer curl-Automatisierung
// absichert (siehe README) - das hier ist nur fuer den interaktiven
// Browser-Login mit Rollen (admin/user/viewer).
// ---------------------------------------------------------------------

/** Einmal ganz am Anfang von public/index.php aufrufen, vor jeder Ausgabe. */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------
// CSRF-Schutz (Synchronizer-Token, ein Token pro Session). Betrifft nur
// die Session-Cookie-authentifizierte Web-Oberflaeche - die /api/v1/*-
// Endpunkte (Bearer-Token bzw. Basic-Auth, kein Cookie) sind davon
// ausgenommen (siehe zentraler Check in public/index.php).
// ---------------------------------------------------------------------

/** Generiert bei Bedarf einmalig einen Token fuer diese Session (auch vor dem Login gueltig). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Fuer <?= csrf_field() ?> direkt in jedem <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Bricht mit 403 ab, wenn $_POST['csrf_token'] fehlt oder nicht zur Session passt. */
function require_csrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    if ($submitted === '' || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden - ' . __('ungültiges oder fehlendes CSRF-Token (Formular evtl. zu alt - Seite neu laden und erneut versuchen)') . "\n";
        exit;
    }
}

/** @return array<string, mixed>|null Eingeloggter User (ohne password_hash) oder null. */
function current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $user = $row ?: null;
    return $user;
}

/**
 * Prueft Username/Passwort, setzt bei Erfolg die Session. Regeneriert die
 * Session-ID (gegen Session-Fixation) statt nur den Inhalt zu aendern.
 */
function login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row === false || $row['password_hash'] === null) {
        return false;
    }
    if (!password_verify($password, (string) $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** 302 zu /login, wenn nicht eingeloggt; gibt sonst den User zurueck. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    return $user;
}

/** Wie require_login(), zusaetzlich 403, wenn die Rolle nicht passt. */
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden - ' . __('fehlende Berechtigung') . "\n";
        exit;
    }
    return $user;
}

/** @return int[] IDs der Sites, auf die $user (Rolle user/viewer) beschraenkt ist. */
function user_site_ids(int $userId): array
{
    $stmt = db()->prepare('SELECT site_id FROM user_sites WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'site_id'));
}

/**
 * Admin darf immer, sonst muss $siteId (devices.site_id) unter den dem
 * User zugewiesenen Sites sein. Ein Geraet ohne Standort (site_id = NULL)
 * ist fuer user/viewer nie sichtbar - es liesse sich sonst keinem
 * zugewiesenen Standort zuordnen.
 */
function user_can_access_site(array $user, ?int $siteId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    if ($siteId === null) {
        return false;
    }
    return in_array($siteId, user_site_ids((int) $user['id']), true);
}

/** require_login() + Site-Check fuer ein konkretes Geraet; 403 bei Verstoss. */
function require_device_access(array $device): array
{
    $user = require_login();
    if (!user_can_access_site($user, isset($device['site_id']) ? (int) $device['site_id'] : null)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden - ' . __('kein Zugriff auf dieses Gerät') . "\n";
        exit;
    }
    return $user;
}

/**
 * require_role('admin', 'user') + Site-Check fuer /sites/<id>/alerting:
 * Admin darf jede Site, 'user' nur seine zugewiesenen. 'viewer' scheitert
 * schon an require_role().
 */
function require_site_access(int $siteId): array
{
    $user = require_role('admin', 'user');
    if (!user_can_access_site($user, $siteId)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden - ' . __('kein Zugriff auf diese Site') . "\n";
        exit;
    }
    return $user;
}

/** Rolle 'user' oder 'admin' duerfen schreiben, 'viewer' ist rein lesend. */
function require_write_access(array $user): void
{
    if ($user['role'] === 'viewer') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden - ' . __('Viewer-Rolle ist nur lesend') . "\n";
        exit;
    }
}
