# WLANMON – Server (PHP + MySQL)

Nimmt Messwerte der `wlanpi-probe`-Clients entgegen, speichert sie in
MySQL/MariaDB und zeigt sie in einem einfachen Web-Dashboard an. Bietet
außerdem den Endpunkt, über den Clients ihre zentrale Konfiguration
abrufen (siehe `config_manager.py` im Client).

Läuft als klassisches PHP-Script auf jedem Apache+PHP-Root-Server **oder**
als Docker-Stack (Web, Datenbank, Cron, TLS-Proxy), z. B. als eigenständige
Standort-„Insel“ unter Portainer – siehe Abschnitt [Docker](#docker). Das
API-Vertrag (JSON-Endpunkte) ist identisch zur vorherigen FastAPI-Variante –
falls ihr den Client schon konfiguriert habt, muss dort nichts geändert
werden.

## Docker

Eine komplette, eigenständige Instanz – etwa für einen Standort, dessen
Probes nur ein internes Netz erreichen („Insel“). Nichts davon
ändert die klassische Installation.

| Container | Aufgabe |
|---|---|
| `db` | MariaDB 11, Daten im Volume `db-data` |
| `web` | Apache + PHP 8.3 mit dem Dashboard (`Dockerfile`); spielt beim Start `schema.sql` idempotent ein und ergänzt nachträglich hinzugekommene Spalten (`docker/init_db.php`) |
| `cron` | dasselbe Image; ersetzt die Cronjobs: `check_alerts.php` alle 5 min, `sync_cirrus_aps.php` stündlich (`docker/cron.sh`) |
| `proxy` | Caddy: TLS nach außen, Weiterleitung an `web` |

Die Konfiguration kommt aus Umgebungsvariablen (`docker/config.php`
bildet daraus dieselbe Struktur wie `config.php`); `update_dashboard.sh`
entfällt – Updates kommen über ein neu gebautes Image.

**Einrichten unter Portainer** (Stack direkt aus diesem Repo):

1. *Stacks → Add stack → Repository*: URL
   `https://github.com/dgawin/wlanmon`, Reference
   `refs/heads/stable`, Compose path `dashboard/docker-compose.yml`.
   *GitOps updates* einschalten
   (Polling, z. B. 5 min) – dann baut Portainer bei jedem neuen Commit auf
   `stable` neu, wie bisher `update_dashboard.sh`.
2. *Environment variables*:

   | Variable | Wert |
   |---|---|
   | `WLANMON_DOMAIN` | Name oder IP, unter der Probes und Browser das Dashboard erreichen (muss exakt zur späteren `server.url` passen), z. B. `wlanmon.intern` oder `192.168.1.10` |
   | `WLANMON_CADDY_FLAGS` | `--internal-certs` für eine interne Insel (eigene CA, siehe unten); leer lassen bei einem öffentlich auflösbaren Namen (Let's Encrypt, Ports 80/443 aus dem Internet erreichbar) |
   | `WLANMON_DB_PASSWORD` | langer Zufallswert, z. B. `openssl rand -base64 32` |
   | `WLANMON_ADMIN_PASSWORD` | Passwort für die Admin-API (`/api/v1/admin/*`); leer = Admin-API gesperrt |
   | optional | `WLANMON_ZABBIX_TOKEN`, `WLANMON_CIRRUS_ORG_ID`/`_APP_ID`/`_APP_SECRET`/`_EMAIL`/`_PASSWORD`, `WLANMON_HTTP_PORT`/`WLANMON_HTTPS_PORT` (Standard 80/443, falls belegt) |
3. *Deploy the stack*, danach den ersten Web-Login anlegen (Container-Name
   je nach Stack-Name, siehe Portainer):

   ```bash
   docker exec -it <stack>-web-1 php create_admin.php <user> <email> <passwort>
   ```

**Probes an eine Insel mit interner CA anbinden:** Caddy stellt das
Zertifikat für `WLANMON_DOMAIN` aus seiner eigenen CA aus. Deren
Root-Zertifikat einmal vom Server holen und auf jede Probe des Standorts
kopieren:

```bash
docker cp <stack>-proxy-1:/data/caddy/pki/authorities/local/root.crt ./wlanmon-ca.crt
```

In `/etc/wlanmon-probe/config.yaml` der Probe dann `verify_tls` auf diese
Datei zeigen lassen (die Probe reicht den Wert an `requests` durch, ein
Pfad statt `true` heißt „diese CA vertrauen“):

```yaml
server:
  url: "https://wlanmon.intern/api/v1"
  verify_tls: "/etc/wlanmon-probe/wlanmon-ca.crt"
```

Die CA bleibt im Volume `caddy-data` erhalten – das Volume nicht löschen,
sonst entsteht eine neue CA und alle Probes brauchen das neue Zertifikat.

**Sichern / umziehen:**

```bash
docker exec <stack>-db-1 sh -c 'mariadb-dump -u wlanmon -p"$MARIADB_PASSWORD" wlanmon' > wlanmon-backup.sql
docker exec -i <stack>-db-1 sh -c 'mariadb -u wlanmon -p"$MARIADB_PASSWORD" wlanmon' < wlanmon-backup.sql
```

Ein Dump der klassischen Installation lässt sich genauso einspielen; beim
nächsten Start von `web` ergänzt `docker/init_db.php` fehlende Spalten.

## Voraussetzungen

- Apache mit `mod_rewrite` (für die `.htaccess`-Routen) und `mod_php`
  **oder** PHP-FPM + `mod_proxy_fcgi`
- PHP ≥ 7.4 (getestet mit 8.3) mit Extension `pdo_mysql`
- MySQL ≥ 5.7 oder MariaDB ≥ 10.2
- Ein DNS-Eintrag `wlanmon.example.com` → Root-Server-IP
- TLS-Zertifikat (z. B. via `certbot`)

## Projektstruktur

```
config.example.php   -> nach config.php kopieren (DB-Zugangsdaten + API-Admin-Token)
schema.sql            -> MySQL-Schema
create_admin.php      -> einmalig: ersten Admin-Account für den Web-Login anlegen
update_dashboard.sh   -> automatisches Update aus dem Git-Repo (Cronjob)
Dockerfile, docker-compose.yml, docker/ -> Docker-Betrieb (siehe Abschnitt "Docker")
check_alerts.php      -> Alerting-Auswertung E-Mail/Telegram (Cronjob)
backup_db.php         -> nächtliches DB-Backup, gzip, rotierend (Cronjob)
cleanup_data.php      -> löscht Messungen nach der Aufbewahrung aus dem Dashboard (Cronjob)
lang/en.php           -> englische Texte der Weboberfläche (deutscher Text => englischer)
tools/i18n_check.php  -> prüft lang/en.php auf fehlende/ungenutzte Einträge
tools/secrets.php     -> Schlüssel erzeugen, Stand anzeigen, Zugangsdaten verschlüsseln
public/               -> DocumentRoot des vHosts
  index.php           -> Front-Controller (Routing + Handler)
  .htaccess           -> URL-Rewriting auf index.php
  static/style.css
  static/theme.js     -> Hell/Dunkel-Umschalter in der Kopfzeile (Auto/Hell/Dunkel, pro Browser)
  static/spectrum.js  -> Spektrumansicht im Scan-Tab (Trapez je BSS über die Kanalbreite)
  static/timeline.js  -> Tab "Verlauf": Auslastung/Latenz über 6/24/72 h, iperf3-Zeiträume markiert
src/                  -> außerhalb von public/, nicht direkt aufrufbar
  db.php, auth.php, admin_auth.php, Response.php, Device.php, Measurement.php
  Alerting.php        -> SMTP-/Telegram-Versand + Alert-Zustand (alerts-Tabelle)
  Settings.php        -> Key-Value-Speicher für Web-gepflegte Einstellungen (settings-Tabelle)
  Session.php         -> Web-Login/Sessions/Rollen (users-Tabelle, siehe "Benutzer & Rollen")
  User.php            -> Benutzerverwaltung + Einladungslinks (users/invites-Tabellen)
  Site.php            -> Kuratierte Standorte (sites-Tabelle)
  Timeline.php        -> Daten für den Tab "Verlauf" (GET /devices/<id>/timeline, JSON)
  I18n.php            -> Sprache Deutsch/Englisch: __()/te() übersetzen, Sprachwahl
  Retention.php       -> Datenhaltung: Aufbewahrung je Messart, Aufräumen (cleanup_data.php)
  Secrets.php         -> Verschlüsselung der Zugangsdaten (secret_key, siehe "Zugangsdaten verschlüsseln")
  Audit.php           -> Änderungsprotokoll (Tabelle config_audit)
  templates/_nav.php  -> gemeinsame Navigation, in jedem Seiten-Template eingebunden
  templates/dashboard.php, templates/device_detail.php, templates/settings_alerting.php
```

Wichtig: **`DocumentRoot` des vHosts muss auf `public/` zeigen**, nicht
auf das Projekt-Root – sonst wären `config.php` und die `src/`-Dateien
theoretisch direkt über den Browser abrufbar.

## Installation

### 1. Dateien auf den Server bringen

```bash
# z.B. unter /var/www/wlanmon
sudo mkdir -p /var/www/wlanmon
# ZIP hochladen und dort entpacken, oder per git clone
cd /var/www/wlanmon
cp config.example.php config.php
nano config.php   # DB-Zugangsdaten eintragen. 'admin' dort ist NUR für
                  # die /api/v1/admin/*-Endpunkte (curl-Automatisierung) -
                  # der Web-Login läuft über echte Benutzerkonten, siehe
                  # Schritt 6 und Abschnitt "Benutzer & Rollen".
```

### 2. MySQL-Datenbank anlegen

```bash
sudo mysql -u root -p << 'SQL'
CREATE DATABASE wlanmon CHARACTER SET utf8mb4;
CREATE USER 'wlanmon'@'localhost' IDENTIFIED BY 'HIER-LANGES-ZUFAELLIGES-PASSWORT';
GRANT ALL PRIVILEGES ON wlanmon.* TO 'wlanmon'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql -u wlanmon -p wlanmon < schema.sql
```

Passwort in `config.php` entsprechend eintragen.

### 3. Apache-vHost einrichten

```apache
<VirtualHost *:80>
    ServerName wlanmon.example.com
    DocumentRoot /var/www/wlanmon/public

    <Directory /var/www/wlanmon/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo a2enmod rewrite
sudo a2ensite wlanmon
sudo systemctl reload apache2
```

`AllowOverride All` ist nötig, damit die mitgelieferte `.htaccess`
(Routing + Authorization-Header-Durchreichung) greift.

### 4. TLS einrichten (certbot)

```bash
sudo certbot --apache -d wlanmon.example.com
```

Danach ist das Dashboard unter `https://wlanmon.example.com/` und die
API unter `https://wlanmon.example.com/api/v1/...` erreichbar.

### 5. Dateirechte

```bash
sudo chown -R www-data:www-data /var/www/wlanmon
sudo chmod 640 /var/www/wlanmon/config.php
```

### 6. Ersten Admin-Account für den Web-Login anlegen

```bash
php create_admin.php <username> <email> <passwort>
```

Danach unter `https://wlanmon.example.com/login` einloggen. Weitere Benutzer
(Rollen Admin/User/Viewer) werden danach über die Weboberfläche unter
`/users` eingeladen, siehe Abschnitt "Benutzer & Rollen".

## Benutzer & Rollen

Der Web-Login (`/login`) läuft über echte Benutzerkonten (Tabelle
`users`) mit drei Rollen:

| Rolle | Geräte ansehen | Config bearbeiten, Messungen löschen | Standorte/Benutzer verwalten |
|---|---|---|---|
| **Admin** | alle | alle | ja (alle Standorte) |
| **User** | nur zugewiesene Standorte | nur zugewiesene Standorte | nur Alerting/Bemerkung der eigenen Standorte |
| **Viewer** | nur zugewiesene Standorte | nein | nein |

**Standorte (`/sites`, Admin + User):** eine kuratierte Liste von
Standorten, gegen die Berechtigungen geprüft werden - Geräte werden ihnen
über `/devices/<id>/config` **tatsächlich zugeordnet** (`devices.site_id`,
nur Admin), nicht per Freitext-Abgleich. Ein Gerät ohne Zuordnung ist für
`User`/`Viewer` unsichtbar und wird vom Alerting übersprungen. Admin kann
Standorte anlegen/umbenennen/löschen; `User` sieht nur seine zugewiesenen
Standorte und kann dort die Bemerkung sowie die Alerting-Einstellungen
pflegen (siehe Abschnitt "Alerting"). Der Probe-Client meldet keinen
Standort mehr - `device_id` allein identifiziert das Gerät.

**Benutzer einladen (`/users`, nur Admin):** legt den Account an (ohne
Passwort) und verschickt eine Einladungs-Mail mit einem 7 Tage gültigen
Link zum Passwort-Setzen - über dieselben SMTP-Zugangsdaten wie das
Alerting (`/settings/alerting`). Schlägt der Mail-Versand fehl oder ist
SMTP nicht konfiguriert, wird der Link zusätzlich direkt in der
Weboberfläche angezeigt, zum manuellen Kopieren.

**Eigenes Passwort ändern:** über den Schlüssel-Icon-Link in der
Kopfzeile (`/account/password`, alle Rollen).

**Sprache (Deutsch/Englisch):** im Benutzermenü unter "Sprache", auf der
Login-Seite oben rechts. Die Wahl wird im Benutzerkonto gespeichert
(`users.language`; die Spalte legt das Dashboard bei älteren Installationen
beim ersten Umschalten selbst an) und zusätzlich per Cookie, ohne Wahl gilt
die Browsersprache. Übersetzt ist die gesamte Weboberfläche. Die Sprache
der Alert-Nachrichten (E-Mail/Telegram) wird je Standort unter
`/sites/<id>/alerting` gewählt (`site_alerting.language`, Spalte wird bei
Bedarf angelegt), Einladungs-Mails gehen in der Sprache des einladenden
Admins raus. Texte stehen im Code auf
Deutsch in `__()`/`te()`/`teh()`, die englische Fassung in `lang/en.php` - nach Änderungen
`php tools/i18n_check.php` laufen lassen.

**Wichtig bei einem Stable-Rollout:** Ab diesem Feature gibt es für den
Web-Login **keinen** Basic-Auth-Fallback mehr - unbedingt vor dem
Live-Schalten mit `create_admin.php` einen funktionierenden Admin-Account
anlegen und den Login testen.

## Automatische Updates aus dem Git-Repo

Optional: `update_dashboard.sh` prüft per `git fetch` + `git rev-parse`, ob
im Repo eine neuere Version auf `origin/<branch>` liegt, und installiert sie
automatisch: `git pull --ff-only` in einem separaten Checkout, danach per
`rsync` (ohne `--delete`, `config.php` bleibt ausgenommen) ins tatsächliche
Docroot-Verzeichnis. Anders als bei der Probe braucht es dafür **keinen**
Dienst-Neustart – PHP-Dateien wirken beim nächsten Request sofort (OPcache
validiert bei Standardeinstellungen die Datei-`mtime` alle paar Sekunden
automatisch neu).

Bewusst ein separater Checkout statt `git`-Operationen direkt im Docroot:
so bleibt `config.php` unberührt, und ein fehlgeschlagener `git pull`
(z. B. bei lokalen Änderungen im Checkout) kann nie die live ausgelieferten
Dateien in einen inkonsistenten Zwischenzustand bringen.

**Einmalig einrichten:**

Bei einem öffentlichen Repo reicht ein normaler HTTPS-Klon ohne Login:

```bash
git clone https://github.com/dgawin/wlanmon.git ~/wlanmon
chmod +x ~/wlanmon/dashboard/update_dashboard.sh
```

Bei einem **privaten** Repo fragt `git clone` interaktiv nach einem
Passwort – GitHub akzeptiert dort seit 2021 aber keine Konto-Passwörter
mehr für `git`-Operationen über HTTPS ("Invalid username or token.
Password authentication is not supported"). Für einen unbeaufsichtigten
Cronjob eignet sich ein read-only SSH-Deploy-Key besser als ein
Personal-Access-Token (kein manuelles Neu-Eintragen bei Ablauf):

```bash
# Eigenen, nur fuer dieses Repo bestimmten Key anlegen:
ssh-keygen -t ed25519 -C "wlanmon-dashboard-deploy" -f ~/.ssh/wlanmon_dashboard_deploy -N ""
cat ~/.ssh/wlanmon_dashboard_deploy.pub
```

Den Public Key bei GitHub hinterlegen: Repo → **Settings** → **Deploy
keys** → **Add deploy key** → einfügen, **"Allow write access" NICHT
anhaken** (read-only reicht, der Server soll nur pullen, nie pushen).
Danach einen SSH-Host-Alias anlegen, damit `git` genau diesen Key
verwendet (falls auf dem Server noch weitere GitHub-Keys existieren):

```bash
cat >> ~/.ssh/config << 'EOF'
Host github.com-wlanmon-dashboard
    HostName github.com
    User git
    IdentityFile ~/.ssh/wlanmon_dashboard_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
ssh-keyscan github.com >> ~/.ssh/known_hosts   # Host-Key vorab bekannt machen

git clone git@github.com-wlanmon-dashboard:dgawin/wlanmon.git ~/wlanmon
chmod +x ~/wlanmon/dashboard/update_dashboard.sh
```

`LIVE_DIR` (Standard im Skript: `/var/www/wlanmon`)
und `SRC_DIR` (Standard: `~/wlanmon/dashboard`) lassen sich per
Umgebungsvariable überschreiben, falls der Pfad bei euch abweicht:

```bash
WLANMON_DASHBOARD_LIVE_DIR=/var/www/vhosts/example.com/wlanmon.example.com ~/wlanmon/dashboard/update_dashboard.sh
```

**Per Cronjob aktivieren** (alle 15 Minuten; Ein-/Ausschalten = Zeile
eintragen/entfernen, keine weitere Konfiguration nötig):

```bash
crontab -e
```

```cron
*/15 * * * * /root/wlanmon/dashboard/update_dashboard.sh >> /var/log/wlanmon-dashboard-update.log 2>&1
```

**`stable` statt `main`:** Ein Push auf `main` geht nicht sofort live –
`update_dashboard.sh` zieht standardmäßig vom Branch `stable`. Ein Commit
landet dort erst, wenn er bewusst freigegeben wird, nach
[CI](../.github/workflows/ci.yml) (`php -l`/`bash -n` auf jeden Push) und
optional einem manuellen Test:

```bash
git fetch origin
git checkout stable
git merge --ff-only origin/main
git push origin stable
```

Schlägt der `--ff-only`-Merge fehl, ist `stable` divergiert (z. B. ein
Hotfix direkt dort) – dann erst manuell klären statt zu erzwingen. Läuft
der Cronjob schon mit einer älteren Skriptversion (Standard `main`), wirkt
die Umstellung erst nach der nächsten Aktualisierung des Skripts selbst –
für sofortige Wirkung die Variable einmalig explizit in der Cron-Zeile
setzen:

```cron
*/15 * * * * WLANMON_DASHBOARD_BRANCH=stable /root/wlanmon/dashboard/update_dashboard.sh >> /var/log/wlanmon-dashboard-update.log 2>&1
```

**Sicherheitshinweis:** Das führt automatisch aus, was auf `branch` liegt –
nur aktivieren, wenn dieser Branch tatsächlich nur Freigegebenes enthält.
`git pull --ff-only` verweigert sich bei lokalen Änderungen im
`SRC_DIR`-Checkout statt etwas zu überschreiben; ein fehlendes
`SRC_DIR`/`LIVE_DIR` bricht ebenfalls nur folgenlos ab. Log verfolgen:

```bash
tail -f /var/log/wlanmon-dashboard-update.log
```

## Erstes Gerät anlegen

```bash
curl -u admin:<ADMIN_PASSWORD> -X POST https://wlanmon.example.com/api/v1/admin/devices \
  -H "Content-Type: application/json" \
  -d '{"device_id": "wlanpi-probe-01", "site_id": 1}'
```

`site_id` ist optional (ID einer bestehenden Site, siehe `/sites`) - ohne
Angabe bleibt das Gerät zunächst unzugeordnet und lässt sich später über
`/devices/<id>/config` zuordnen (nur Admin).

Antwort enthält den `api_key` **im Klartext, nur dieses eine Mal** –
direkt in die `config.yaml` des jeweiligen NanoPi übernehmen:

```yaml
server:
  url: "https://wlanmon.example.com/api/v1"
  api_key: "<hier einfügen>"
  verify_tls: true
```

## Optional: zentrale Konfiguration für ein Gerät setzen

```bash
curl -u admin:<ADMIN_PASSWORD> -X PUT \
  https://wlanmon.example.com/api/v1/admin/devices/wlanpi-probe-01/config \
  -H "Content-Type: application/json" \
  -d '{
    "scan": {"interval_seconds": 60},
    "connection_tests": {
      "interval_seconds": 900,
      "connect_timeout_seconds": 30,
      "ping_target": "1.1.1.1",
      "ping_count": 5,
      "captive_portal_url": "http://connectivitycheck.gstatic.com/generate_204",
      "iperf3_server": "",
      "iperf3_duration_seconds": 5,
      "iperf3_port": 5201,
      "targets": [
        {"ssid": "Firmennetz-Corp", "psk": "...", "security": "wpa2-psk"},
        {"ssid": "Firmennetz-Gast-VLAN20", "psk": "", "security": "open",
         "captive_portal_check": true, "iperf3_enabled": true,
         "iperf3_server": "192.168.1.251", "iperf3_duration_seconds": 5,
         "iperf3_port": 5202, "iperf3_download": true, "random_mac": true}
      ]
    }
  }'
```

Auf dem Client dazu `remote_config.enabled: true` in der `config.yaml`
setzen.

## API-Key rotieren / Gerät löschen

```bash
curl -u admin:<ADMIN_PASSWORD> -X POST \
  https://wlanmon.example.com/api/v1/admin/devices/wlanpi-probe-01/rotate-key

curl -u admin:<ADMIN_PASSWORD> -X DELETE \
  https://wlanmon.example.com/api/v1/admin/devices/wlanpi-probe-01
```

## Zabbix-Integration (Monitoring/Alerting)

Zwei schreibgeschützte Endpunkte, gedacht für Zabbix-HTTP-Agent-Items.
Getrennt vom Admin-Login über einen eigenen Bearer-Token (`zabbix.token`
in `config.php`), damit Zabbix nur Lesezugriff auf Status-Daten hat,
keine Admin-Rechte (Geräte anlegen/löschen, Config setzen, PSKs sehen).

```php
'zabbix' => [
    'token' => 'change-me-too-long-and-random', // z.B. openssl rand -base64 32
],
```

### Endpunkte

```bash
# LLD-Discovery: eine Zeile je registriertem Gerät, für Zabbix-Host-Prototypen
curl -H "Authorization: Bearer <ZABBIX_TOKEN>" \
  https://wlanmon.example.com/api/v1/zabbix/discovery/devices
# -> {"data":[{"{#DEVICE_ID}":"wlanpi-probe-01","{#SITE}":"kunde-musterfirma-standort-a"}]}

# Aggregierter Status eines Geräts (Master-Item für Dependent Items)
curl -H "Authorization: Bearer <ZABBIX_TOKEN>" \
  https://wlanmon.example.com/api/v1/zabbix/devices/wlanpi-probe-01/status
```

Antwort von `/status` (Beispiel):

```json
{
  "device_id": "wlanpi-probe-01",
  "site": "kunde-musterfirma-standort-a",
  "last_seen_at": "2026-09-16 08:00:00",
  "seconds_since_last_seen": 42,
  "last_scan_at": "2026-09-16 07:59:00",
  "last_scan_network_count": 12,
  "connection_tests_total": 2,
  "connection_tests_ok": 1,
  "connection_tests_failed": 1,
  "connection_tests_failed_ssids": "Firmennetz-Guest",
  "worst_ping_rtt_ms": 45.2,
  "max_ping_loss_percent": 20,
  "min_iperf3_mbps": 120.4,
  "connection_tests": [ { "ssid": "...", "connected": true, "...": "..." } ]
}
```

Wichtig für Zabbix: Felder ohne (noch) verfügbaren Wert (`last_seen_at`,
`seconds_since_last_seen`, `last_scan_at`, `last_scan_network_count`,
`worst_ping_rtt_ms`, `max_ping_loss_percent`, `min_iperf3_mbps`) fehlen
im JSON komplett, statt als `null` gesendet zu werden – JSONPath meldet
für einen fehlenden Schlüssel einen echten Fehler ("Pfad nicht
gefunden"), den "Custom on fail" bei der JSONPath-Preprocessing-Stufe
zuverlässig abfängt. Ein explizites `null` würde dagegen vom
JSONPath-Schritt anstandslos als String `"null"` durchgereicht und erst
bei der Typkonvertierung in `Numeric (float)` scheitern – ein Fehler,
der von "Custom on fail" auf dem JSONPath-Schritt **nicht** abgedeckt
wird ("Value of type 'string' is not suitable for value type 'Numeric
(float)'. Value: 'null'.").

`connection_tests` enthält zusätzlich die vollen Rohdaten je SSID – falls
später granularere Per-SSID-Items gewünscht sind, kann eine Zabbix
Item-Discovery-Regel mit JSONPath `$.connection_tests` und LLD-Makro
`{#SSID}` → `$.ssid` direkt darauf aufsetzen, ohne dass sich am
PHP-Endpunkt etwas ändern muss.

### Zabbix 7.4 einrichten (Discovery → Host-Prototyp → Template)

1. **Platzhalter-Host** anlegen (z.B. `WLANMON Discovery`), darauf muss
   kein Agent laufen – er trägt nur die Discovery-Regel.
2. Auf diesem Host eine **Discovery-Regel** anlegen: Typ `HTTP agent`,
   URL `{$WLANMON.URL}/zabbix/discovery/devices`, Header
   `Authorization: Bearer {$WLANMON.TOKEN}`, Type of information `Text`.
3. In der Discovery-Regel unter **Host-Prototypen** einen Prototyp
   anlegen: Hostname `{#DEVICE_ID}`, Hostgruppe z.B. `WLANMON Probes`,
   Template `WLANMON Probe` (siehe unten) verknüpfen, und als
   Host-Makros `{$DEVICE_ID}` = `{#DEVICE_ID}` sowie `{$SITE}` =
   `{#SITE}` setzen – Zabbix legt darüber automatisch je Gerät einen
   eigenen Host an/löscht ihn wieder, wenn es aus wlanmon verschwindet.
4. **Template `WLANMON Probe`** anlegen mit Makros `{$WLANMON.URL}`
   (z.B. `https://wlanmon.example.com/api/v1`), `{$WLANMON.TOKEN}` (als
   *Secret text*), `{$WLANMON.OFFLINE_THRESHOLD}` (z.B. `1800` Sekunden),
   `{$WLANMON.RTT_THRESHOLD}` (z.B. `100` ms), `{$WLANMON.LOSS_THRESHOLD}`
   (z.B. `20` %), `{$WLANMON.MIN_THROUGHPUT}` (z.B. `50` Mbit/s).
5. **Master-Item** `wlanmon.status`: Typ `HTTP agent`, URL
   `{$WLANMON.URL}/zabbix/devices/{$DEVICE_ID}/status`, Header
   `Authorization: Bearer {$WLANMON.TOKEN}`, Type of information `Text`,
   Intervall z.B. `60s`, kurze History (wird nur als Basis gebraucht).
6. **Dependent Items** auf dem Master-Item, je mit Preprocessing-Schritt
   `JSONPath` und „Custom on fail → Discard value“ **direkt auf diesem
   JSONPath-Schritt** (damit ein fehlender Schlüssel im JSON – z.B. noch
   kein Scan oder kein iperf3 konfiguriert – das Item nicht auf „Not
   supported“ kippen lässt; der PHP-Endpunkt lässt solche Felder bewusst
   ganz weg statt `null` zu senden, siehe oben):

   | Key | JSONPath | Typ |
   |---|---|---|
   | `wlanmon.seconds_since_last_seen` | `$.seconds_since_last_seen` | Numeric (unsigned) |
   | `wlanmon.last_scan_network_count` | `$.last_scan_network_count` | Numeric (unsigned) |
   | `wlanmon.connection_tests_failed` | `$.connection_tests_failed` | Numeric (unsigned) |
   | `wlanmon.connection_tests_failed_ssids` | `$.connection_tests_failed_ssids` | Text |
   | `wlanmon.worst_ping_rtt_ms` | `$.worst_ping_rtt_ms` | Numeric (float) |
   | `wlanmon.max_ping_loss_percent` | `$.max_ping_loss_percent` | Numeric (float) |
   | `wlanmon.min_iperf3_mbps` | `$.min_iperf3_mbps` | Numeric (float) |

7. **Trigger** (Ausdrücke sinngemäß, Item-Keys wie oben):
   - Gerät offline: `last(/WLANMON Probe/wlanmon.seconds_since_last_seen) > {$WLANMON.OFFLINE_THRESHOLD}`
   - Connection-Test fehlgeschlagen: `last(/WLANMON Probe/wlanmon.connection_tests_failed) > 0`
     (Beschreibung kann `{ITEM.LASTVALUE:wlanmon.connection_tests_failed_ssids}` referenzieren)
   - Hohe Ping-RTT: `last(/WLANMON Probe/wlanmon.worst_ping_rtt_ms) > {$WLANMON.RTT_THRESHOLD}`
   - Hoher Paketverlust: `last(/WLANMON Probe/wlanmon.max_ping_loss_percent) > {$WLANMON.LOSS_THRESHOLD}`
   - Niedriger Durchsatz: `last(/WLANMON Probe/wlanmon.min_iperf3_mbps) > 0 and last(/WLANMON Probe/wlanmon.min_iperf3_mbps) < {$WLANMON.MIN_THROUGHPUT}`
     (die `> 0`-Bedingung verhindert Fehlalarme, falls für ein Gerät gar
     kein iperf3-Server konfiguriert ist)

Das Template selbst nicht direkt an echte Hosts hängen, sondern nur über
den Host-Prototyp aus Schritt 3 – Zabbix pflegt darüber automatisch je
in wlanmon registriertem Gerät einen eigenen Host samt alle Items/Triggern.

## Alerting (E-Mail/Telegram)

Ergänzt die Zabbix-Integration oben um natives Push-Alerting direkt aus
wlanmon: `check_alerts.php` prüft periodisch (Cronjob, kein Webserver-
Trigger) jedes Gerät auf zwei Regeln und meldet bei Bedarf per E-Mail
und/oder Telegram:

- **`offline`**: länger als `offline_after_minutes` nicht gemeldet (bzw.
  noch nie gemeldet – dann gegen die Anlagezeit des Geräts statt
  `last_seen_at`).
- **`ssid_failing:<SSID>`**: Die letzten `consecutive_test_failures`
  Connection-Tests dieser SSID waren *alle* nicht verbunden. Wird
  übersprungen, solange das Gerät selbst als offline gilt (dann sind das
  nur veraltete Messwerte).

Bei einer **neuen** Störung wird sofort benachrichtigt (sofern gerade im
erlaubten Zeitfenster, siehe unten). Bleibt sie bestehen, erst wieder
nach `repeat_after_minutes` (kein Spam bei jedem Cron-Tick). Klärt sie
sich, gibt es eine **Entwarnung**. Der Zustand liegt in der Tabelle
`alerts` und wird immer aktuell gehalten, unabhängig vom Zeitfenster -
nur der tatsächliche Versand wird außerhalb unterdrückt.

**Konfiguration ist zweigeteilt:**

1. **SMTP-/Telegram-Zugangsdaten** (`/settings/alerting`, nur Admin): ein
   gemeinsamer Kanal für alle Standorte (Tabelle `settings`, Schlüssel
   `alerting` – siehe `src/Settings.php`). E-Mail braucht SMTP-
   Zugangsdaten (selbst geschriebener SMTP-Client, kein Composer/
   PHPMailer), Telegram nur einen Bot-Token (über `@BotFather` in
   Telegram anlegen).
2. **Ob/an wen/wann je Standort alarmiert wird** (`/sites` →
   <i>Alerting</i>, Admin für alle Standorte, `User` nur für seine
   zugewiesenen): Ein/Aus, Schwellwerte, Empfänger/Chat-ID und ein
   **Zeitfenster** (durchgängig oder nur an bestimmten Wochentagen/
   Uhrzeiten, z. B. Mo-Fr 8-20 Uhr). Dort auch direkt per Knopfdruck ein
   Testalarm mit den gespeicherten Einstellungen verschickbar (Ergebnis
   je Kanal wird angezeigt, ignoriert dabei bewusst den Ein/Aus-Schalter
   und das Zeitfenster).

Geräte müssen dafür einem Standort zugeordnet sein (`/devices/<id>/config`,
nur Admin) - ohne Zuordnung wertet `check_alerts.php` das Gerät gar nicht
aus.

Cronjob einrichten, der `check_alerts.php` periodisch aufruft:

```bash
crontab -e
```

```cron
# Bewusst eigener, engmaschigerer Takt als der Auto-Update-Cronjob (siehe
# oben) - Alerting soll zügiger reagieren als ein Code-Update-Check.
*/5 * * * * php /pfad/zu/check_alerts.php >> /var/log/wlanmon-alerts.log 2>&1
```

Zustellung für einen bestimmten Standort auch von der Kommandozeile aus
testen, ohne auf eine echte Störung zu warten (funktioniert unabhängig
vom Ein/Aus-Schalter und Zeitfenster der Site, rührt die `alerts`-Tabelle
nicht an):

```bash
php check_alerts.php --test <site-name>
```

**Telegram-Chat-ID ermitteln:** dem eigenen Bot (oder einer Gruppe, in
die er eingeladen wurde) kurz schreiben, danach
`https://api.telegram.org/bot<TOKEN>/getUpdates` im Browser öffnen und
`"chat":{"id": ...}` ablesen.

**Bekannte Einschränkung:** Eine aus `connection_tests.targets` entfernte
SSID kann eine bereits ausgelöste `ssid_failing`-Störung dauerhaft aktiv
lassen, da für sie nie wieder neue Connection-Tests eintreffen, die den
Zustand auflösen könnten – in diesem Fall den betroffenen Alert einmalig
manuell auflösen:

```sql
UPDATE alerts SET resolved_at = UTC_TIMESTAMP()
WHERE device_id = '<device-id>' AND rule = 'ssid_failing:<SSID>' AND resolved_at IS NULL;
```

## Version

Die eigene Versionsnummer steht in der Datei `VERSION` im Projekt-Root
(angezeigt auf der Über-Seite unter `/about`), von Hand gepflegt, kein
automatischer Bump. Schema (gilt genauso für die Probe):

- **Release:** drei Stellen, z. B. `1.0.1`.
- **Jeder Commit auf `main`** danach zählt eine vierte Stelle hoch, im
  selben Commit: `1.0.1.1`, `1.0.1.2`, ... So ist an der angezeigten
  Version erkennbar, welcher Stand auf einem Gerät/Server läuft.
- **Nächstes Release:** vierte Stelle entfällt, dritte Stelle +1
  (`1.0.1.7` -> `1.0.2`), danach wieder `1.0.2.1`, ...

`stable` übernimmt die Versionsnummer per Fast-Forward unverändert von
`main`. Der Probe-Client
schickt seine eigene `VERSION` (siehe dortiges README) bei jedem
Messwert-Batch als `probe_version` mit; das Dashboard speichert sie in
`devices.probe_version` und zeigt sie sowohl in der Geräteliste als auch
auf der Geräte-Detailseite an, damit sich veraltete Probe-Versionen im
Gerätepark auf einen Blick erkennen lassen.

## OmniVista Cirrus

**Verlauf:** `sync_cirrus_aps.php` hängt bei jedem Lauf die Kanalauslastung
aller Radios an die Tabelle `cirrus_radio_history` an (30 Tage, legt sie bei
Bedarf selbst an – kein manueller Schritt). Der Tab „Verlauf“ der
Geräteseite zeigt diese Werte als Kreise neben dem BSS Load aus den
Probe-Scans. Die Auflösung entspricht dem Cron-Intervall (Beispiel unten:
stündlich).

Optionale, rein lesende Anbindung an die öffentliche REST-API von
OmniVista Cirrus (Alcatel-Lucent Enterprise) - aktuell nur für einen
Zweck: AP-Name/Standort zu einer im Scan gesehenen BSSID auflösen,
angezeigt als eigene Spalte in der Roaming-Kandidaten-Tabelle der
Geräte-Detailseite (aus dem Beacon selbst ist der AP-Name nicht
zuverlässig auslesbar, Cirrus kennt die Zuordnung als Controller aber
ohnehin).

In `config.php` (siehe `config.example.php`, Block `cirrus`):

```php
'cirrus' => [
    'base_url' => 'https://eu.manage.ovcirrus.com',
    'org_id' => '...',      // aus der Cirrus-URL: .../organizations/<org_id>/...
    'app_id' => '...',      // Cirrus-Organisation -> Settings -> API Applications
    'app_secret' => '...',
    'email' => '...',       // normaler Cirrus-Login, kein separates API-Konto
    'password' => '...',
],
```

Fehlt der Block oder ist ein Feld leer, bleibt die Integration einfach
inaktiv - kein Fehler an anderer Stelle im Dashboard.

Das eigentliche Abrufen läuft über `sync_cirrus_aps.php` (per Cronjob,
z. B. stündlich), das sich per `app_id`/`app_secret`/`email`/`password`
authentifiziert und das Geräte-Inventar (Basis-MAC -> Name/Standort) in
die lokale Tabelle `cirrus_aps` schreibt - kein Live-API-Aufruf bei jedem
Seitenaufruf im Dashboard:

```cron
0 * * * * php /pfad/zu/sync_cirrus_aps.php >> /var/log/wlanmon-cirrus-sync.log 2>&1
```

**Aufgelöst wird über den MAC-Präfix, nicht über eine exakte BSSID-
Übereinstimmung:** Cirrus' Geräte-Inventar (`.../sites/devices`) liefert
pro AP nur eine Basis-MAC-Adresse - bei Multi-Radio-/Multi-SSID-APs weicht
die tatsächlich gesendete BSSID je Funkband/SSID davon ab (in der Praxis
bestätigt, z. B. `...:a1:80` als Basis-MAC vs. `...:a1:81`/`...:a1:91`/
`...:a1:a1`/etc. als tatsächliche Radio-BSSIDs, siehe `iwconfig` auf dem
AP selbst). Ein zusätzlicher Cirrus-Endpunkt, der einzelne erkannte
Radio-BSSIDs auflistet (WIPS „Friendly AP"-Liste), wurde erprobt, aber
verworfen: er deckte live nachweislich nicht alle tatsächlich gesendeten
BSSIDs ab (fehlende Coverage einzelner Funkbänder/SSIDs), und sein
eigenes `apName`-Feld je Eintrag war zusätzlich unzuverlässig (BSSIDs
desselben Radios wurden teils unterschiedlichen APs zugeordnet).

Stattdessen matcht `cirrus_lookup_ap()` in `src/Cirrus.php` **jede
tatsächlich im Probe-Scan gesehene BSSID** über die ersten 5 Oktette
gegen die Basis-MACs in `cirrus_aps` (`cirrus_mac_prefix5()`/
`cirrus_build_prefix_map()`) - in dieser Cirrus-Organisation leiten APs
ihre Radio-/SSID-BSSIDs offenbar durch reines Ändern des letzten Oktetts
von ihrer Basis-MAC ab. Das deckt auch BSSIDs ab, die Cirrus nie einzeln
gemeldet hat. Mehrere Basis-MACs mit demselben Präfix (nur letztes
Oktett unterschiedlich) ergeben eine nicht eindeutige Zuordnung - dann
bewusst kein Name statt einer Falschzuordnung.

**Access Points, Funkzustand:** `/access-points` (Nav-Eintrag, nur
sichtbar bei konfigurierter Cirrus-Anbindung, für alle eingeloggten
Rollen) zeigt je bekanntem AP und Funkband Kanal, Kanalauslastung,
Rauschpegel und Sendeleistung - hilft einzuordnen, ob ein
fehlgeschlagener oder langsamer Connection-Test an der Funklage lag.

**Undokumentierter Endpunkt, bewusst so:** Der öffentlich in
`/apidoc/swagger.json` beschriebene Endpunkt dafür
(`GET .../wlan-analytics/access-points/rf-details/{apMac}`, ein AP pro
Aufruf) lieferte live nachweislich immer `"radios": []`, obwohl Cirrus
selbst (Web-UI unter Monitor → Network → Analytics → RF Details) die
Daten hat - vermutlich ein Bug/eine Einschränkung dieses einen
dokumentierten Endpunkts. Per Browser-DevTools (Network-Tab der
Cirrus-UI beim Aufruf dieser Ansicht) wurde stattdessen gefunden, was
die UI selbst benutzt: `POST {base_url}/api/organizations/{orgId}/wlan-analytics/access-points/rf-details/pagination`
(**kein** `/ov/v1/` im Pfad, anders als alle übrigen Endpunkte hier) mit
JSON-Body `{fields: null, filters: "{}", limit, offset, organizationId,
scope: "org", scopeId: [orgId], search: "", searchFields: "", sort:
'[{"apMac":"DESC"}]'}` - liefert alle APs auf einmal (paginiert über
`limit`/`offset`), funktioniert nachweislich auch mit dem
Application-Bearer-Token (nicht nur der Browser-Session). Siehe
`cirrus_fetch_all_ap_radios()` in `src/Cirrus.php`. Als undokumentiert
kann sich das jederzeit ohne Ankündigung ändern - `sync_cirrus_aps.php`
bricht in dem Fall kontrolliert mit Exit 1 ab (Basis-MAC-Inventar bleibt
unangetastet), statt `cirrus_ap_radios` mit Müll zu befüllen.

Auch das ist ein reiner Cache (keine Historie, bei jedem Sync-Lauf
komplett neu befüllt) - für eine Verlaufsansicht bräuchte es
stattdessen die zeitraumbasierten `wlan-analytics`-Endpunkte
(`ap-health`, `channel-utilization-evolution`, …), aktuell nicht
umgesetzt.

## Datenhaltung & Backup

**Aufbewahrung:** Wie lange Messungen gespeichert bleiben, stellt der Admin
im Dashboard unter Benutzermenü → „Datenhaltung“ (`/settings/retention`)
ein: WLAN-Scans (Standard 90 Tage - mit Abstand der größte Datenposten,
grob 15-30 MB pro Gerät und Tag bei einem Scan pro Minute) und
Connection-/LAN-Tests (Standard 365 Tage, gilt auch für behobene Alarme).
0 = unbegrenzt. Die Seite zeigt auch Anzahl und Alter der gespeicherten
Messungen sowie den Stand von Aufräumen und Backup.

**Aufräumen:** `cleanup_data.php` löscht alles, was älter ist, endgültig
(in Portionen, ohne die Probes zu blockieren) und dazu abgelaufene
Einladungen. Ohne diesen Cronjob wächst die Datenbank unbegrenzt.

**Backup:** `backup_db.php` schreibt jede Nacht einen vollständigen,
gzip-komprimierten Dump (`mariadb-dump`/`mysqldump`, Paket
`mariadb-client`) nach `backup.dir` aus `config.php` (Standard
`/var/backups/wlanmon`) und löscht Backups älter als `backup.keep_days`
(Standard 14 Tage) - aber nur nach einem erfolgreichen neuen Backup. Die
Dumps enthalten WLAN-Passwörter, 802.1X- und SMTP-Zugangsdaten im
Klartext (Dateirechte 0600); für den Ernstfall zusätzlich auf einen
anderen Rechner/ein NAS kopieren.

Cronjobs (Backup vor dem Aufräumen, damit Gelöschtes noch im Backup der
Nacht steckt):

```cron
15 2 * * * php /pfad/zu/backup_db.php >> /var/log/wlanmon-backup.log 2>&1
30 3 * * * php /pfad/zu/cleanup_data.php >> /var/log/wlanmon-cleanup.log 2>&1
```

Im Docker-Betrieb laufen beide im Cron-Container (01 bzw. 02 Uhr UTC), die
Backups liegen im Volume `backups` (`WLANMON_BACKUP_DIR`,
`WLANMON_BACKUP_KEEP_DAYS`).

Wiederherstellen:

```bash
gunzip < /var/backups/wlanmon/wlanmon-2026-09-30_0215.sql.gz | mysql -u wlanmon -p wlanmon
```

## Zugangsdaten verschlüsseln

PSKs, 802.1X-Passwörter und private Schlüssel, Portal-Passwörter sowie
SMTP-Passwort und Telegram-Bot-Token liegen in der Datenbank verschlüsselt
(libsodium, `src/Secrets.php`), sobald ein Schlüssel eingerichtet ist. Die
Formulare zeigen gespeicherte Werte nie wieder an („gespeichert – leer
lassen = unverändert“, Häkchen „gespeicherten Wert entfernen“).

1. Schlüssel erzeugen: `php tools/secrets.php generate-key`
2. In `config.php` eintragen: `'secret_key' => '<Schlüssel>',` (Docker:
   `WLANMON_SECRET_KEY`). `config.php` sollte nur für den PHP-Benutzer
   lesbar sein (`chmod 600`).
3. Bestehende Werte verschlüsseln: Benutzermenü → „Datenhaltung“ → „Jetzt
   alle verschlüsseln“ oder `php tools/secrets.php encrypt-all`. Neue und
   geänderte Werte werden ab dann automatisch verschlüsselt gespeichert.
4. **Schlüssel getrennt vom Datenbank-Backup aufbewahren** (z. B.
   Passwort-Manager). Ohne ihn lassen sich die Zugangsdaten nicht mehr
   entschlüsseln und müssen neu eingegeben werden - die betroffenen Geräte
   behalten bis dahin ihre zuletzt geladene Konfiguration.

Wirkung: Datenbank-Dumps, Backups und ein SQL-Leck enthalten nur noch
Chiffretext. Gegen jemanden, der den ganzen Server übernimmt (Schlüssel und
Datenbank), hilft es nicht. Stand: `php tools/secrets.php status` bzw. die
Datenhaltungs-Seite.

**Änderungsprotokoll:** Wer wann was an Geräte-Konfiguration,
Standort-Zuordnung, API-Keys, Standorten, Benutzern, Alerting und
Datenhaltung geändert hat, steht unter Benutzermenü →
„Änderungsprotokoll“ (Admin) und je Gerät auf dessen
Konfigurationsseite. Geheime Felder erscheinen nur als „geändert“, nie mit
Wert. Aufbewahrung wie die Tests (Datenhaltung).

## Sicherheitshinweise

- `ADMIN_PASSWORD` und DB-Passwort lang und zufällig wählen
  (z. B. `openssl rand -base64 32`), niemals die Beispielwerte übernehmen
- `config.php` liegt außerhalb von `public/` und ist damit über den
  Webserver nicht direkt abrufbar – **vorausgesetzt** der vHost zeigt
  wirklich auf `public/` als DocumentRoot
- API-Keys der Geräte gelten unbegrenzt, bis sie rotiert werden – bei
  Verdacht auf Kompromittierung sofort rotieren
- `config.php` nicht ins Git-Repo committen (bzw. `.gitignore` pflegen)
- Alle Web-Formulare sind per CSRF-Token abgesichert (ein Token je
  Session, siehe `csrf_token()`/`require_csrf()` in `src/Session.php`) -
  betrifft nur die Session-Cookie-Oberfläche, nicht die `/api/v1/*`-
  Endpunkte (Bearer-/Basic-Auth)

## Lokal testen (ohne Apache)

Mit dem eingebauten PHP-Entwicklungsserver:

```bash
cd public
php -S 127.0.0.1:8000
```

Hinweis: Der eingebaute Server wertet `.htaccess` nicht aus, alle
Requests landen aber ohnehin direkt bei `index.php`, solange ihr die
Pfade wie `/api/v1/measurements` oder `/devices/<id>` exakt so aufruft
(Routing passiert im PHP-Code selbst, nicht in Apache). Für einen
vollständigen Test inkl. Rewrite-Regeln empfiehlt sich trotzdem ein
echter Apache.

## Lizenz

MIT – siehe [LICENSE](../LICENSE).
