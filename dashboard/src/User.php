<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Alerting.php';
require_once __DIR__ . '/Session.php';

// ---------------------------------------------------------------------
// Benutzerverwaltung (Tabelle "users") + Einladungslinks (Tabelle
// "invites"), siehe /users in public/index.php. Login-Mechanik selbst
// liegt in Session.php.
// ---------------------------------------------------------------------

const USER_ROLES = ['admin', 'user', 'viewer'];

/**
 * Spaltenliste fuer user_list()/user_find(): "active" = Passwort gesetzt
 * (Einladung angenommen), "disabled" = Konto deaktiviert (siehe
 * user_set_disabled()) - zwei unabhaengige Zustaende.
 */
function user_columns(): string
{
    return 'id, username, email, role, password_hash IS NOT NULL AS active, '
        . (users_disabled_supported() ? 'disabled_at IS NOT NULL' : '0') . ' AS disabled, created_at';
}

/** @return array<int, array<string, mixed>> Nach Username sortiert, ohne password_hash. */
function user_list(): array
{
    return db()->query('SELECT ' . user_columns() . ' FROM users ORDER BY username')->fetchAll();
}

function user_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT ' . user_columns() . ' FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function user_find_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT id, username, email, role FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return int Neue User-ID. password_hash bleibt NULL bis zur Einladungs-Annahme. */
function user_create(string $username, string $email, string $role): int
{
    $stmt = db()->prepare('INSERT INTO users (username, email, role) VALUES (?, ?, ?)');
    $stmt->execute([$username, $email, $role]);
    return (int) db()->lastInsertId();
}

function user_set_role(int $id, string $role): void
{
    $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
    $stmt->execute([$role, $id]);
}

/** @param int[] $siteIds */
function user_set_sites(int $userId, array $siteIds): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM user_sites WHERE user_id = ?');
        $del->execute([$userId]);
        if ($siteIds !== []) {
            $ins = $pdo->prepare('INSERT INTO user_sites (user_id, site_id) VALUES (?, ?)');
            foreach (array_unique($siteIds) as $siteId) {
                $ins->execute([$userId, $siteId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @return array<int, array<string, mixed>> Sites, denen dieser User zugewiesen ist. */
function user_sites_for(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT s.* FROM sites s
         INNER JOIN user_sites us ON us.site_id = s.id
         WHERE us.user_id = ? ORDER BY s.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Deaktiviert ein Konto bzw. hebt das wieder auf. Rolle, Standorte und
 * Passwort bleiben erhalten; deaktiviert ist kein Login moeglich, eine
 * laufende Sitzung endet bei der naechsten Anfrage (current_user()) und
 * offene Einladungslinks gelten nicht (invite_find_valid()).
 */
function user_set_disabled(int $id, bool $disabled): void
{
    $stmt = db()->prepare('UPDATE users SET disabled_at = ' . ($disabled ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id = ?');
    $stmt->execute([$id]);
}

function user_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
}

function user_set_password(int $id, string $password): void
{
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

/** Für die Passwort-ändern-Seite (/account/password) - prüft das aktuelle Passwort vor dem Setzen eines neuen. */
function user_verify_password(int $id, string $password): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false && $row['password_hash'] !== null && password_verify($password, (string) $row['password_hash']);
}

/**
 * Grobes Fangnetz gegen offensichtlich schwache/vorhersehbare Passwörter
 * (allen voran "admin"/"admin") - kein vollständiger Passwort-Stärke-
 * Check, nur eine Blockliste der naheliegendsten Fälle plus "Passwort
 * gleich Benutzername". Wird an jeder Stelle geprüft, an der ein User
 * sein Passwort selbst setzt: create_admin.php, Einladung annehmen
 * (handle_invite_submit()) und Passwort ändern (handle_change_password()).
 */
function is_weak_password(string $username, string $password): bool
{
    $normalized = strtolower(trim($password));
    if ($normalized === strtolower(trim($username))) {
        return true;
    }
    $blocklist = [
        'admin', 'administrator', 'admin123', 'adminadmin', 'admin1234',
        'password', 'passwort', 'passwort1', 'changeme', 'change-me',
        '12345678', '123456789', 'qwertyui', 'letmein', 'wlanmon', 'wlanmon123',
    ];
    return in_array($normalized, $blocklist, true);
}

// ---------------------------------------------------------------------
// Einladungslinks. Token wie die Geraete-API-Keys als SHA-256-Hash
// gespeichert (hash_api_key()/verify_api_key() aus auth.php) - der
// Klartext-Token existiert nur einmal, direkt nach dem Erzeugen.
// ---------------------------------------------------------------------

/** @return string Der Klartext-Token (fuer Mail/Fallback-Anzeige - wird selbst nicht gespeichert). */
function invite_create(int $userId, int $ttlSeconds = 604800): string
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))
        ->modify("+{$ttlSeconds} seconds")
        ->format('Y-m-d H:i:s');
    // Alte, noch offene Einladungen fuer denselben User verwerfen - es
    // soll immer nur ein gueltiger Link gleichzeitig existieren, sonst
    // koennte ein alter (evtl. schon weitergeleiteter) Link ueberraschend
    // noch funktionieren.
    $del = db()->prepare('DELETE FROM invites WHERE user_id = ?');
    $del->execute([$userId]);
    $stmt = db()->prepare('INSERT INTO invites (token_hash, user_id, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([hash_api_key($token), $userId, $expiresAt]);
    return $token;
}

/** @return array<string, mixed>|null Invite-Zeile (inkl. user_id), null bei ungueltigem/abgelaufenem Token. */
function invite_find_valid(string $token): ?array
{
    // Hash-Vergleich statt direktem WHERE token_hash = hash(...): so bleibt
    // die Lookup-Logik an einer Stelle (verify_api_key()) konsistent mit
    // den Geraete-API-Keys, auch wenn hier (anders als dort) der Hash
    // selbst der Primary Key ist und ein direktes WHERE ebenso ginge.
    $stmt = db()->prepare('SELECT * FROM invites WHERE token_hash = ?');
    $stmt->execute([hash_api_key($token)]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $expires = new DateTime((string) $row['expires_at'], new DateTimeZone('UTC'));
    if ($expires < new DateTime('now', new DateTimeZone('UTC'))) {
        return null;
    }
    // Einladung eines inzwischen deaktivierten Kontos nicht mehr einloesen lassen.
    $user = user_find((int) $row['user_id']);
    if ($user === null || !empty($user['disabled'])) {
        return null;
    }
    return $row;
}

/** Setzt das Passwort des Invite-Users und verbraucht die Einladung. */
function invite_consume(array $invite, string $password): void
{
    user_set_password((int) $invite['user_id'], $password);
    $stmt = db()->prepare('DELETE FROM invites WHERE token_hash = ?');
    $stmt->execute([$invite['token_hash']]);
}

/**
 * Basis-URL, unter der das Dashboard gerade aufgerufen wird (z.B.
 * "https://wlanmon.example.com"), für Einladungslinks und den
 * Konfig-Ausschnitt neuer Geräte. Das Schema kommt aus der Anfrage statt fest
 * "https://", damit auch Installationen ohne TLS passende Links bekommen;
 * hinter einem TLS-Proxy zählt zusätzlich X-Forwarded-Proto (im Docker-Betrieb
 * setzt docker/apache-vhost.conf das ohnehin in HTTPS=on um). Ohne Anfrage
 * (CLI) bleibt es beim bisherigen https-Platzhalter.
 */
function request_base_url(): string
{
    if (!isset($_SERVER['HTTP_HOST'])) {
        return 'https://wlanmon.example.com';
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
}

function invite_url(string $token): string
{
    return request_base_url() . "/invite/{$token}";
}

/**
 * Verschickt die Einladungs-Mail ueber dieselben SMTP-Zugangsdaten wie
 * das Alerting (alerting_config()['email']) - bewusst per direktem
 * send_alert_email()-Aufruf statt ueber dispatch_alert(): dessen
 * enabled-Flags sind alerting-spezifisch (Stoerungsbenachrichtigung an/
 * aus) und sollen den Einladungsversand nicht mit blockieren. Wirft nie
 * (wie send_alert_email() selbst) - der Aufrufer zeigt den Link bei
 * einem Fehler zusaetzlich zum manuellen Kopieren an.
 *
 * @return array{ok: bool, error: ?string}
 */
function invite_send_email(array $user, string $token): array
{
    $emailCfg = (array) (alerting_config()['email'] ?? []);
    $emailCfg['to'] = [$user['email']];
    $url = invite_url($token);
    return send_alert_email(
        $emailCfg,
        __('WLANMON: Einladung zum Dashboard'),
        __('Hallo %s,', (string) $user['username']) . "\n\n" .
        __('du wurdest für das WLANMON-Dashboard eingeladen. Über den folgenden Link kannst du dein Passwort setzen (Link ist 7 Tage gültig):') . "\n\n" .
        "{$url}\n\n" .
        __('Falls du diese Einladung nicht erwartet hast, kannst du diese E-Mail ignorieren.')
    );
}
