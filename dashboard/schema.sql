-- WLANMON Schema (MySQL 5.7+ / MariaDB 10.2+)
-- Einspielen z.B. mit: mysql -u wlanmon -p wlanmon < schema.sql

-- Kuratierte Liste bekannter Standortnamen (Verwaltung unter /sites).
-- Quelle der Wahrheit fuer die Geraete-Zuordnung (devices.site_id) und
-- fuer Berechtigungen (siehe user_sites). Steht bewusst vor "devices" in
-- dieser Datei, damit die FK von devices.site_id auf sites.id beim
-- erstmaligen Einspielen in der richtigen Reihenfolge angelegt wird.
CREATE TABLE IF NOT EXISTS sites (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,
    notes       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
    id            VARCHAR(191) NOT NULL PRIMARY KEY,
    -- Tatsaechliche Zuordnung (Admin-verwaltet, siehe /devices/<id>/config)
    -- statt Freitext - der Probe-Client meldet keinen Standort mehr, ein
    -- Geraet ohne Zuordnung (NULL) ist fuer Rollen user/viewer unsichtbar
    -- und wird vom Alerting uebersprungen (siehe check_alerts.php).
    site_id       INT UNSIGNED NULL,
    notes         TEXT NULL,
    api_key_hash  CHAR(64) NOT NULL,       -- SHA-256-Hash des Klartext-Keys
    -- Zentral verwaltete Client-Config (Keys: "scan", "connection_tests"),
    -- als JSON-Text gespeichert. NULL = Client nutzt lokale Fallback-Werte.
    -- LONGTEXT statt nativem JSON-Typ für maximale Kompatibilität über
    -- verschiedene MySQL-/MariaDB-Versionen hinweg.
    config        LONGTEXT NULL,
    -- Konfigurationsprofil (src/Profile.php); NULL = eigene Konfiguration oben.
    profile_id    INT UNSIGNED NULL,
    -- Vom Probe-Client bei jedem Messwert-Batch mitgeschickt (Feld
    -- "probe_version", siehe handle_ingest_measurements()) - NULL, bis das
    -- erste Mal ein Client mit VERSION-Datei gesendet hat.
    probe_version VARCHAR(32) NULL,
    -- Auto-Update-Stand laut Probe (JSON: enabled, branch, repo_url,
    -- repo_dir, reported_at), siehe device_store_auto_update(). NULL, bis
    -- ein Client ab 1.0.1.2 gesendet hat.
    auto_update   LONGTEXT NULL,
    -- Systemwerte laut letztem Heartbeat der Probe (JSON: CPU, RAM, Speicher,
    -- Temperatur, Uptime, Sende-Warteschlange; siehe device_store_health())
    -- und dessen Zeitpunkt. NULL bis zum ersten Heartbeat (Probe ab 1.0.1.54).
    -- Fehlen die Spalten, legt device_store_health() sie an.
    health        LONGTEXT NULL,
    heartbeat_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME NULL,
    CONSTRAINT fk_devices_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bestehende Installation (Spalte "site_id"/"notes"/"probe_version" fehlen
-- noch, ggf. eine alte Freitext-Spalte "site" ist noch vorhanden):
--   ALTER TABLE devices ADD COLUMN site_id INT UNSIGNED NULL AFTER site,
--       ADD COLUMN notes TEXT NULL,
--       ADD COLUMN probe_version VARCHAR(32) NULL,
--       ADD CONSTRAINT fk_devices_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL;
--   -- Bestehende Freitext-Zuordnung uebernehmen, wo der Name zu einer
--   -- kuratierten Site passt (Site vorher unter /sites anlegen):
--   UPDATE devices d JOIN sites s ON s.name = d.site SET d.site_id = s.id WHERE d.site_id IS NULL;
-- Die alte "site"-Spalte bleibt danach ungenutzt liegen (keine destruktive
-- Migration) - kann bei Bedarf manuell mit DROP COLUMN entfernt werden.
-- Bestehende Installation, Spalte "auto_update" fehlt noch (Dashboard
-- 1.0.1.2; ohne sie laeuft alles weiter, nur die Anzeige bleibt leer):
--   ALTER TABLE devices ADD COLUMN auto_update LONGTEXT NULL AFTER probe_version;

CREATE TABLE IF NOT EXISTS measurements (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id         VARCHAR(191) NOT NULL,
    -- 'scan', 'connection_test' oder 'lan_test'. VARCHAR statt ENUM, damit
    -- neue Messarten keine Schema-Migration mehr brauchen (erlaubte Werte
    -- prueft handle_ingest_measurements() in public/index.php).
    -- Bestehende Installation migrieren:
    --   ALTER TABLE measurements MODIFY kind VARCHAR(32) NOT NULL;
    kind              VARCHAR(32) NOT NULL,
    client_timestamp  DATETIME NULL,
    received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data              LONGTEXT NOT NULL,  -- vollständiger Messwert-Payload als JSON-Text
    -- Gerät + Art + Zeit: Verlauf, Geräteseite und Alarmprüfung fragen fast
    -- immer "Messungen eines Geräts dieser Art im Zeitraum bzw. die neuesten"
    -- ab. Ein Index nur auf (device_id, kind) zwang MySQL, dafür jede Zeile des
    -- Geräts zu lesen (bei ~20.000 Scans je Gerät mehrere Sekunden).
    -- Bestehende Installation (Tabelle bleibt dabei benutzbar):
    --   ALTER TABLE measurements ADD INDEX idx_device_kind_time (device_id, kind, received_at), ALGORITHM=INPLACE, LOCK=NONE;
    --   ALTER TABLE measurements DROP INDEX idx_device_kind;
    INDEX idx_device_kind_time (device_id, kind, received_at),
    INDEX idx_received_at (received_at),
    CONSTRAINT fk_measurements_device FOREIGN KEY (device_id)
        REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alerting (siehe check_alerts.php, src/Alerting.php). Ein Alert ist
-- "aktiv", solange resolved_at NULL ist; pro (device_id, rule) darf es
-- jeweils hoechstens eine aktive Zeile geben - das wird nicht per
-- UNIQUE-Constraint erzwungen (NULL gilt bei MySQL als "verschieden",
-- ein UNIQUE(device_id, rule, resolved_at) wuerde also mehrere aktive
-- Zeilen zulassen), sondern per Anwendungslogik in check_alerts.php
-- (erst SELECT ... WHERE resolved_at IS NULL, dann INSERT/UPDATE).
--
-- Bestehende Installation nachziehen:
--   mysql -u wlanmon -p wlanmon < schema.sql
-- (CREATE TABLE IF NOT EXISTS legt nur die neue Tabelle an, laesst den
-- Rest unangetastet.)
-- Generischer Key-Value-Speicher fuer server-weite Einstellungen, die
-- ueber das Web gepflegt werden statt in config.php zu stehen (Vorbild:
-- devices.config fuer die Geraete-Zentral-Config). Aktuell nur "alerting"
-- (siehe src/Settings.php, src/Alerting.php, /settings/alerting), spaeter
-- ohne Schema-Aenderung um weitere Einstellungen erweiterbar.
CREATE TABLE IF NOT EXISTS settings (
    name        VARCHAR(64) NOT NULL PRIMARY KEY,
    value       LONGTEXT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Benutzerkonten (siehe src/Session.php). password_hash ist NULL, bis
-- eine Einladung (siehe invites) angenommen wurde. role: 'admin',
-- 'user' oder 'viewer' - VARCHAR statt ENUM aus demselben Grund wie bei
-- measurements.kind (keine Schema-Migration fuer eine neue Rolle noetig,
-- erlaubte Werte prueft die Anwendung).
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(191) NOT NULL UNIQUE,
    -- Fuer den Einladungs-Mailversand (siehe invites) - Login selbst
    -- laeuft ueber username, nicht ueber email.
    email          VARCHAR(255) NOT NULL,
    password_hash  VARCHAR(255) NULL,
    role           VARCHAR(16) NOT NULL DEFAULT 'viewer',
    -- Sprache der Weboberfläche ('de'/'en', NULL = Browsersprache), siehe
    -- src/I18n.php. Bestehende Installation: wird beim ersten Umschalten
    -- automatisch angelegt, sonst: ALTER TABLE users ADD COLUMN language VARCHAR(5) NULL;
    language       VARCHAR(5) NULL,
    -- Konto deaktiviert seit (UTC), NULL = aktiv - siehe user_set_disabled()
    -- in src/User.php. Bestehende Installation: wird beim ersten Aufruf
    -- automatisch angelegt, sonst: ALTER TABLE users ADD COLUMN disabled_at DATETIME NULL;
    disabled_at    DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Site-Zuweisungen fuer Rolle 'user'/'viewer' (fuer 'admin' ignoriert,
-- der sieht alles). Abgleich gegen devices.site_id, siehe
-- user_can_access_site() in src/Session.php.
CREATE TABLE IF NOT EXISTS user_sites (
    user_id  INT UNSIGNED NOT NULL,
    site_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, site_id),
    CONSTRAINT fk_user_sites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_sites_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Einladungslinks zum Setzen des Erst-Passworts (siehe /invite/<token>
-- in public/index.php). token_hash wie die Geraete-API-Keys als SHA-256
-- gespeichert (hash_api_key()/verify_api_key() aus src/auth.php), der
-- Klartext-Token steht nur einmal in der Einladungs-Mail bzw. der
-- Fallback-Anzeige im /users-Formular.
CREATE TABLE IF NOT EXISTS invites (
    token_hash  CHAR(64) NOT NULL PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_id         VARCHAR(191) NOT NULL,
    -- z.B. "offline" oder "ssid_failing:<SSID>".
    rule              VARCHAR(191) NOT NULL,
    first_seen_at     DATETIME NOT NULL,
    last_notified_at  DATETIME NOT NULL,
    resolved_at       DATETIME NULL,
    INDEX idx_device_rule_active (device_id, rule, resolved_at),
    CONSTRAINT fk_alerts_device FOREIGN KEY (device_id)
        REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lokal zwischengespeichertes AP-Inventar aus OmniVista Cirrus (siehe
-- sync_cirrus_aps.php, src/Cirrus.php, README "OmniVista Cirrus") - dient
-- ausschliesslich der BSSID -> AP-Name/Standort-Aufloesung in der
-- Roaming-Kandidaten-Tabelle (device_detail.php). Wird bei jedem Sync-Lauf
-- komplett aus der aktuellen Cirrus-Antwort neu befuellt (siehe
-- sync_cirrus_aps.php) - keine Historie, reiner Cache.
CREATE TABLE IF NOT EXISTS cirrus_aps (
    mac_address  VARCHAR(17) NOT NULL PRIMARY KEY,  -- normalisiert: aa:bb:cc:dd:ee:ff
    ap_name      VARCHAR(191) NULL,
    site_name    VARCHAR(191) NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Live-Funkzustand je AP-Radio aus OmniVista Cirrus (undokumentierter
-- Pagination-Endpunkt, siehe cirrus_fetch_all_ap_radios() in
-- src/Cirrus.php, sync_cirrus_aps.php, README "OmniVista Cirrus") - fuer
-- die "Access Points"-Seite im Dashboard: Kanal/Kanalauslastung/Rauschen/
-- Sendeleistung je Funkband, damit sich ein fehlgeschlagener oder
-- langsamer Connection-Test gegen die Funklage am jeweiligen AP
-- einordnen laesst. Wird bei jedem Sync-Lauf komplett neu befuellt (wie
-- cirrus_aps) - keine Historie, reiner Cache des letzten bekannten
-- Zustands. mac_address referenziert bewusst NICHT per FOREIGN KEY auf
-- cirrus_aps.mac_address - Geraete ohne Radios (z.B. Switches im selben
-- Cirrus-Inventar) tauchen hier einfach nicht auf, ohne dass das
-- Basis-MAC-Inventar davon abhaengen muesste, in welcher Reihenfolge
-- beide Tabellen befuellt werden.
CREATE TABLE IF NOT EXISTS cirrus_ap_radios (
    mac_address          VARCHAR(17) NOT NULL,  -- normalisiert: aa:bb:cc:dd:ee:ff
    radio                VARCHAR(16) NOT NULL,  -- Cirrus-Radio-Kennung, z.B. "radio0"
    band                 VARCHAR(16) NULL,      -- "2.4GHz", "5GHz" oder "6GHz"
    channel              SMALLINT UNSIGNED NULL,
    channel_utilization  DECIMAL(5,2) NULL,     -- Prozent
    noise_floor_dbm      SMALLINT NULL,
    tx_power             DECIMAL(5,2) NULL,
    measured_at          DATETIME NULL,         -- Cirrus' eigener Messzeitpunkt
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (mac_address, radio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verlauf der Cirrus-Kanalauslastung je AP-Radio (Tab "Verlauf" der
-- Geraeteseite). sync_cirrus_aps.php haengt je Sync an und loescht nach 30
-- Tagen; legt die Tabelle bei Bedarf auch selbst an (CIRRUS_RADIO_HISTORY_DDL
-- in src/Cirrus.php, wortgleich).
CREATE TABLE IF NOT EXISTS cirrus_radio_history (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mac_address          VARCHAR(17) NOT NULL,
    band                 VARCHAR(16) NULL,
    channel              SMALLINT UNSIGNED NULL,
    channel_utilization  DECIMAL(5,2) NOT NULL,
    measured_at          DATETIME NOT NULL,
    UNIQUE KEY uq_radio_time (mac_address, band, measured_at),
    INDEX idx_measured_at (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alerting-Regeln je Site (Ein-/Ausgeloggt zu erreichen unter
-- /sites/<id>/alerting). Die SMTP-/Telegram-Zugangsdaten selbst bleiben
-- weiterhin EIN gemeinsamer, admin-verwalteter Kanal in der Tabelle
-- "settings" (Schluessel "alerting", siehe src/Settings.php) - hier steht
-- nur, OB/AN WEN/WANN je Site alarmiert wird (siehe src/Alerting.php,
-- site_alerting_get()/site_alerting_set()).
CREATE TABLE IF NOT EXISTS site_alerting (
    site_id                    INT UNSIGNED NOT NULL PRIMARY KEY,
    enabled                    TINYINT(1) NOT NULL DEFAULT 0,
    offline_after_minutes      INT UNSIGNED NOT NULL DEFAULT 20,
    consecutive_test_failures  INT UNSIGNED NOT NULL DEFAULT 3,
    repeat_after_minutes       INT UNSIGNED NOT NULL DEFAULT 240,
    email_enabled              TINYINT(1) NOT NULL DEFAULT 0,
    email_to                   TEXT NULL,        -- JSON-Array von Adressen
    telegram_enabled           TINYINT(1) NOT NULL DEFAULT 0,
    telegram_chat_id           VARCHAR(64) NULL,
    -- Zeitfenster: 'always' (durchgaengig) oder 'custom' (siehe
    -- alerting_schedule_allows_now() in src/Alerting.php). schedule_days
    -- = kommagetrennte ISO-Wochentage (1=Mo..7=So, z.B. "1,2,3,4,5"),
    -- Start-/Endstunde als 0-23 (Ende exklusiv, 20 = bis 19:59).
    schedule_mode               VARCHAR(16) NOT NULL DEFAULT 'always',
    schedule_days                VARCHAR(32) NULL,
    schedule_start_hour          TINYINT UNSIGNED NULL,
    schedule_end_hour            TINYINT UNSIGNED NULL,
    -- Sprache der Alert-Nachrichten ('de'/'en', NULL = Deutsch). Fehlt die
    -- Spalte in einer älteren Installation, legt site_alerting_set() sie an.
    language                    VARCHAR(5) NULL,
    -- Regel "auth_slow:<SSID>" (check_alerts.php): 802.1X-Anmeldung bei den
    -- letzten consecutive_test_failures Tests jeweils laenger als dieser
    -- Wert in Sekunden. NULL = Regel aus. Fehlt die Spalte, legt
    -- site_alerting_set() sie an.
    auth_slow_seconds           DECIMAL(5,1) NULL,
    -- Nur Anzeige (Geraeteliste, keine Alarmregel): Ø Assoziationszeit (inkl.
    -- Scan) bzw. Ø DHCP-Zeit ueber dieser Schwelle in Sekunden = orange.
    -- NULL = keine Faerbung. Fehlen die Spalten, legt site_alerting_set() sie an.
    assoc_slow_seconds          DECIMAL(5,1) NULL,
    dhcp_slow_seconds           DECIMAL(5,1) NULL,
    -- Regel "eap_abort_rate:<SSID>" (check_alerts.php): Anteil fehlgeschlagener
    -- Tests an einer 802.1X-SSID im Zeitfenster (Minuten) in Prozent, ab dem
    -- alarmiert wird (mind. 3 Fehlschlaege). NULL = Regel aus. Fehlen die
    -- Spalten, legt site_alerting_set() sie an.
    eap_abort_rate_pct          TINYINT UNSIGNED NULL,
    eap_abort_window_minutes    SMALLINT UNSIGNED NULL,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_site_alerting_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mitschnitte fehlgeschlagener Connection-Tests (src/Capture.php): pcap und
-- Adapter-Ereignisse ("iw event") je capture_id aus dem Testergebnis.
-- Aufbewahrung: capture_days unter /settings/retention (Standard 30 Tage).
-- Bestehende Installationen: wird beim ersten Mitschnitt automatisch angelegt.
CREATE TABLE IF NOT EXISTS captures (
    id          CHAR(32) NOT NULL PRIMARY KEY,
    device_id   VARCHAR(191) NOT NULL,
    created_at  DATETIME NOT NULL,
    pcap        MEDIUMBLOB NULL,
    pcap_size   INT UNSIGNED NOT NULL DEFAULT 0,
    events      MEDIUMTEXT NULL,
    -- Log von wpa_supplicant (ab Dashboard 1.0.1.49, wird in bestehenden
    -- Tabellen automatisch ergaenzt, siehe captures_ensure_table()).
    wpa_log     MEDIUMTEXT NULL,
    KEY idx_captures_device (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anmeldeversuche aus der Authentifizierungs-Historie von OmniVista Cirrus
-- zu einem Connection-Test (src/CirrusAuth.php), gespeichert nach dem ersten
-- Abruf; wird mit der Messung geloescht. Bestehende Installationen: wird
-- beim ersten Abruf automatisch angelegt.
CREATE TABLE IF NOT EXISTS cirrus_auth_lookups (
    measurement_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    fetched_at      DATETIME NOT NULL,
    records         MEDIUMTEXT NOT NULL,
    CONSTRAINT fk_cirrus_auth_measurement FOREIGN KEY (measurement_id)
        REFERENCES measurements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SSIDs und Konfigurationsprofile (src/Profile.php, Seiten /ssids und
-- /profiles). Eine SSID = ein Eintrag wie in connection_tests.targets
-- (Geheimnisse verschluesselt), ein Profil = Testeinstellungen + SSIDs;
-- devices.profile_id waehlt das Profil (NULL = eigene Konfiguration).
-- site_id NULL = global. Bestehende Installationen: wird beim ersten
-- Aufruf der Seiten automatisch angelegt.
CREATE TABLE IF NOT EXISTS ssids (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id     INT UNSIGNED NULL,
    label       VARCHAR(100) NULL,
    target      MEDIUMTEXT NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ssids_site (site_id),
    CONSTRAINT fk_ssids_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profiles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id     INT UNSIGNED NULL,
    name        VARCHAR(100) NOT NULL,
    settings    MEDIUMTEXT NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_profiles_site (site_id),
    CONSTRAINT fk_profiles_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profile_ssids (
    profile_id  INT UNSIGNED NOT NULL,
    ssid_id     INT UNSIGNED NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (profile_id, ssid_id),
    CONSTRAINT fk_profile_ssids_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_ssids_ssid FOREIGN KEY (ssid_id) REFERENCES ssids(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
