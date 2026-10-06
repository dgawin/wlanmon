<?php
/**
 * Gemeinsame Kopfzeile mit zentrierter Navigation (Geräte/Standorte/
 * Benutzer/Über), eingebunden in jedem Seiten-Template statt der
 * früheren einzelnen <header>-Zeile. Holt sich den eingeloggten User
 * selbst über current_user() (src/Session.php) statt ihn durch jede
 * render_*()-Funktion durchreichen zu müssen.
 */
$__navUser = current_user();
$__navPath = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'), '/');
if ($__navPath === '') {
    $__navPath = '/';
}
/** Aktiv, wenn der Pfad exakt passt oder ein Unterpfad davon ist (nicht per str_starts_with - Projekt zielt auf PHP >= 7.4). */
$__navIsActive = function (string $prefix) use ($__navPath): bool {
    if ($prefix === '/') {
        return $__navPath === '/';
    }
    return $__navPath === $prefix || strpos($__navPath, $prefix . '/') === 0;
};
$__navIsAdmin = ($__navUser['role'] ?? null) === 'admin';
$__navIsAdminOrUser = in_array($__navUser['role'] ?? null, ['admin', 'user'], true);
?>
<header class="nav-header">
    <?php /* Logo inline statt <img>, damit der Schriftzug über --heading/--accent
             dem Hell/Dunkel-Design folgt. Vorlage: branding/wlanmon-logo.svg im Projekt-Root. */ ?>
    <a href="/" class="brand" aria-label="wlanmon">
        <svg class="brand-logo" viewBox="0 0 1004 240" aria-hidden="true" focusable="false">
            <g transform="scale(0.46875)">
                <rect width="512" height="512" rx="112" fill="#2563eb"/>
                <g transform="translate(0 -44)">
                    <g fill="none" stroke="#fff" stroke-width="34" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M206.5 310.5 A70 70 0 0 1 305.5 310.5"/>
                        <path d="M160.5 264.5 A135 135 0 0 1 351.5 264.5"/>
                        <path d="M114.6 218.6 A200 200 0 0 1 397.4 218.6"/>
                        <path d="M88 404 H186 L218 446 L256 362 L294 446 L326 404 H424"/>
                    </g>
                    <circle cx="256" cy="362" r="26" fill="#4ade80" stroke="#fff" stroke-width="10"/>
                </g>
            </g>
            <g transform="translate(292 24) scale(0.8)" fill="none" stroke-width="24" stroke-linecap="round" stroke-linejoin="round">
                <g class="brand-logo-wlan">
                    <path d="M0 100 L22 200 L45 122 L68 200 L90 100"/>
                    <path d="M126 40 V200"/>
                    <circle cx="212" cy="150" r="50"/>
                    <path d="M262 100 V200"/>
                    <path d="M298 200 V100 M298 150 A50 50 0 0 1 398 150 V200"/>
                </g>
                <g class="brand-logo-mon">
                    <path d="M434 200 V100 M434 140 A40 40 0 0 1 514 140 V200 M514 140 A40 40 0 0 1 594 140 V200"/>
                    <circle cx="680" cy="150" r="50"/>
                    <path d="M766 200 V100 M766 150 A50 50 0 0 1 866 150 V200"/>
                </g>
            </g>
        </svg>
    </a>
    <nav class="main-nav">
        <?php // Ohne Anmeldung (Login, Einladung) nur "Über" - alles andere führte ohnehin zum Login. ?>
        <?php if ($__navUser !== null): ?>
        <a href="/" class="<?= $__navIsActive('/') || $__navIsActive('/devices') ? 'active' : '' ?>">
            <i class="fa-solid fa-tower-cell"></i> <?= te('Geräte') ?>
        </a>
        <?php endif; ?>
        <?php if ($__navIsAdminOrUser): ?>
            <a href="/sites" class="<?= $__navIsActive('/sites') ? 'active' : '' ?>">
                <i class="fa-solid fa-map-location-dot"></i> <?= te('Standorte') ?>
            </a>
            <a href="/profiles" class="<?= $__navIsActive('/profiles') ? 'active' : '' ?>">
                <i class="fa-solid fa-layer-group"></i> <?= te('Profile') ?>
            </a>
            <a href="/ssids" class="<?= $__navIsActive('/ssids') ? 'active' : '' ?>">
                <i class="fa-solid fa-wifi"></i> <?= te('SSIDs') ?>
            </a>
        <?php endif; ?>
        <?php if ($__navUser !== null && cirrus_config() !== null): ?>
            <a href="/access-points" class="<?= $__navIsActive('/access-points') ? 'active' : '' ?>">
                <i class="fa-solid fa-wifi"></i> <?= te('Access Points') ?>
            </a>
        <?php endif; ?>
        <?php if ($__navIsAdmin): ?>
            <a href="/users" class="<?= $__navIsActive('/users') ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> <?= te('Benutzer') ?>
            </a>
        <?php endif; ?>
        <?php if ($__navUser === null): ?>
            <?php // Angemeldet stehen "Über" und der Design-Umschalter im Benutzermenü. ?>
            <a href="/about" class="<?= $__navIsActive('/about') ? 'active' : '' ?>">
                <i class="fa-solid fa-circle-info"></i> <?= te('Über') ?>
            </a>
        <?php endif; ?>
    </nav>
    <?php
    // Texte für static/theme.js (Design-Umschalter) in der aktuellen Sprache.
    $__navThemeLabels = 'data-label-auto="' . te('Design: automatisch (System)') . '" data-label-light="' . te('Design: hell')
        . '" data-label-dark="' . te('Design: dunkel') . '"';
    // Sprachumschalter: zwei Buttons, POST an /account/language, danach zurück hierher.
    $__navLangForm = function (string $class) use ($__navPath): string {
        $out = '<form method="post" action="/account/language" class="' . $class . '">' . csrf_field()
            . '<input type="hidden" name="back" value="' . e($_SERVER['REQUEST_URI'] ?? $__navPath) . '">';
        foreach (WLANMON_LANGS as $code => $name) {
            $out .= '<button type="submit" name="lang" value="' . e($code) . '" title="' . e($name) . '"'
                . (effective_lang() === $code ? ' class="active" aria-pressed="true"' : ' aria-pressed="false"') . '>'
                . e(strtoupper($code)) . '</button>';
        }
        return $out . '</form>';
    };
    ?>
    <?php if ($__navUser === null): ?>
    <?= $__navLangForm('lang-switch lang-switch-header') ?>
    <button type="button" id="theme-toggle" class="header-link theme-toggle" title="<?= te('Design umschalten') ?>" aria-label="<?= te('Design umschalten') ?>" <?= $__navThemeLabels ?>>
        <i class="fa-solid fa-circle-half-stroke"></i>
    </button>
    <?php endif; ?>
    <?php if ($__navUser !== null): ?>
    <?php /* Benutzermenü: öffnet bei Hover (Maus), per Klick/Tipp (Touch) und
             per Tastatur (Fokus, Enter/Leertaste, Escape schließt) - ein reines
             Hover-Menü wäre auf dem Handy nicht erreichbar. */ ?>
    <div class="nav-user user-menu" id="user-menu">
        <button type="button" class="user-menu-trigger" id="user-menu-trigger"
                aria-haspopup="true" aria-expanded="false" aria-controls="user-menu-list">
            <i class="fa-solid fa-circle-user"></i>
            <span><?= e($__navUser['username']) ?></span>
            <span class="pill pill-muted"><?= e($__navUser['role']) ?></span>
            <i class="fa-solid fa-chevron-down user-menu-caret"></i>
        </button>
        <div class="user-menu-list" id="user-menu-list" role="menu">
            <?php if ($__navIsAdmin): ?>
                <a href="/settings/alerting" class="user-menu-item" role="menuitem"><i class="fa-solid fa-bell"></i> <?= te('Alarmierungs-Einstellungen') ?></a>
                <a href="/settings/retention" class="user-menu-item" role="menuitem"><i class="fa-solid fa-database"></i> <?= te('Datenhaltung') ?></a>
                <a href="/settings/audit" class="user-menu-item" role="menuitem"><i class="fa-solid fa-clock-rotate-left"></i> <?= te('Änderungsprotokoll') ?></a>
            <?php endif; ?>
            <a href="/account/password" class="user-menu-item" role="menuitem"><i class="fa-solid fa-key"></i> <?= te('Passwort ändern') ?></a>
            <?php // Wechselt Auto -> Hell -> Dunkel; Text und Symbol setzt static/theme.js. ?>
            <button type="button" id="theme-toggle" class="user-menu-item" role="menuitem" <?= $__navThemeLabels ?>>
                <i class="fa-solid fa-circle-half-stroke"></i> <span class="theme-toggle-label"><?= te('Design: automatisch (System)') ?></span>
            </button>
            <div class="user-menu-item user-menu-lang" role="menuitem">
                <i class="fa-solid fa-language"></i> <?= te('Sprache') ?>
                <?= $__navLangForm('lang-switch') ?>
            </div>
            <a href="/about" class="user-menu-item<?= $__navIsActive('/about') ? ' active' : '' ?>" role="menuitem"><i class="fa-solid fa-circle-info"></i> <?= te('Über') ?></a>
            <div class="user-menu-sep" role="separator"></div>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit" class="user-menu-item" role="menuitem"><i class="fa-solid fa-right-from-bracket"></i> <?= te('Abmelden') ?></button>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var menu = document.getElementById('user-menu');
        var trigger = document.getElementById('user-menu-trigger');
        function setOpen(open) {
            menu.classList.toggle('open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        trigger.addEventListener('click', function () { setOpen(!menu.classList.contains('open')); });
        document.addEventListener('click', function (e) { if (!menu.contains(e.target)) { setOpen(false); } });
        menu.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setOpen(false); trigger.focus(); }
        });
    })();
    </script>
    <?php endif; ?>
</header>
