# WLANMON – Server (PHP + MySQL)

Receives measurements from the wlanmon probe clients, stores them in
MySQL/MariaDB and shows them in a simple web dashboard. It also provides the
endpoint the clients use to fetch their central configuration (see
`config_manager.py` in the client).

Runs as a classic PHP application on any Apache + PHP server **or** as a
Docker stack (web, database, cron, TLS proxy), e.g. as a self-contained
site "island" under Portainer – see [Docker](#docker). The API contract
(JSON endpoints) is identical to the previous FastAPI version – clients that
are already configured need no changes.

## Docker

A complete, self-contained instance – e.g. for a site whose probes can only
reach an internal network (an "island"). None of this changes the classic
installation.

| Container | Purpose |
|---|---|
| `db` | MariaDB 11, data in the `db-data` volume |
| `web` | Apache + PHP 8.3 serving the dashboard (`Dockerfile`); applies `schema.sql` idempotently on start and adds columns introduced later (`docker/init_db.php`) |
| `cron` | same image; replaces the cron jobs: `check_alerts.php` every 5 min, `sync_cirrus_aps.php` hourly (`docker/cron.sh`) |
| `proxy` | Caddy: TLS to the outside, forwards to `web` |

Configuration comes from environment variables (`docker/config.php` builds
the same structure as `config.php` from them); `update_dashboard.sh` is not
used – updates arrive as a rebuilt image.

**Setting up under Portainer** (stack straight from this repo):

1. *Stacks → Add stack → Repository*: URL
   `https://github.com/dgawin/wlanmon`, Reference
   `refs/heads/stable`, Compose path `dashboard/docker-compose.yml`.
   Enable *GitOps updates*
   (polling, e.g. 5 min) – Portainer then rebuilds on every new commit on
   `stable`, just like `update_dashboard.sh` does.
2. *Environment variables*:

   | Variable | Value |
   |---|---|
   | `WLANMON_DOMAIN` | Name or IP under which probes and browsers reach the dashboard (must match the later `server.url` exactly), e.g. `wlanmon.intern` or `192.168.1.10` |
   | `WLANMON_CADDY_FLAGS` | `--internal-certs` for an internal island (own CA, see below); leave empty for a publicly resolvable name (Let's Encrypt, ports 80/443 reachable from the internet) |
   | `WLANMON_DB_PASSWORD` | long random value, e.g. `openssl rand -base64 32` |
   | `WLANMON_ADMIN_PASSWORD` | password for the admin API (`/api/v1/admin/*`); empty = admin API disabled |
   | optional | `WLANMON_ZABBIX_TOKEN`, `WLANMON_CIRRUS_ORG_ID`/`_APP_ID`/`_APP_SECRET`/`_EMAIL`/`_PASSWORD`, `WLANMON_HTTP_PORT`/`WLANMON_HTTPS_PORT` (default 80/443, if those are taken) |
3. *Deploy the stack*, then create the first web login (the container name
   depends on the stack name, see Portainer):

   ```bash
   docker exec -it <stack>-web-1 php create_admin.php <user> <email> <password>
   ```

**Connecting probes to an island with an internal CA:** Caddy issues the
certificate for `WLANMON_DOMAIN` from its own CA. Fetch its root certificate
from the server once and copy it to every probe at the site:

```bash
docker cp <stack>-proxy-1:/data/caddy/pki/authorities/local/root.crt ./wlanmon-ca.crt
```

Then point `verify_tls` in the probe's `/etc/wlanmon-probe/config.yaml` at
that file (the probe passes the value through to `requests`; a path instead
of `true` means "trust this CA"):

```yaml
server:
  url: "https://wlanmon.intern/api/v1"
  verify_tls: "/etc/wlanmon-probe/wlanmon-ca.crt"
```

The CA is kept in the `caddy-data` volume – do not delete that volume,
otherwise a new CA is created and every probe needs the new certificate.

**Backing up / migrating:**

```bash
docker exec <stack>-db-1 sh -c 'mariadb-dump -u wlanmon -p"$MARIADB_PASSWORD" wlanmon' > wlanmon-backup.sql
docker exec -i <stack>-db-1 sh -c 'mariadb -u wlanmon -p"$MARIADB_PASSWORD" wlanmon' < wlanmon-backup.sql
```

A dump of the classic installation can be imported the same way; on the
next start of `web`, `docker/init_db.php` adds any missing columns.

## Requirements

- A web server with PHP ≥ 7.4 (tested with 8.3) and the `pdo_mysql`
  extension; with Apache, `mod_rewrite` and `AllowOverride All` for the
  bundled `.htaccess`
- MySQL ≥ 5.7 or MariaDB ≥ 10.2
- HTTPS is strongly recommended – the probes fetch their configuration
  including Wi-Fi passwords from the dashboard (see "Server connection & TLS"
  in the [probe README](../probe/README.md#server-connection--tls))

## Project structure

```
config.example.php   -> copy to config.php (DB credentials + API admin token)
schema.sql            -> MySQL schema
create_admin.php      -> one-off: create the first admin account for the web login
update_dashboard.sh   -> automatic update from the Git repo (cron job)
Dockerfile, docker-compose.yml, docker/ -> Docker deployment (see "Docker")
check_alerts.php      -> alert evaluation, e-mail/Telegram (cron job)
backup_db.php         -> nightly DB backup, gzip, rotating (cron job)
cleanup_data.php      -> deletes measurements past their retention period (cron job)
lang/en.php           -> English UI texts (German text => English)
tools/i18n_check.php  -> checks lang/en.php for missing/unused entries
tools/secrets.php     -> generate key, show status, encrypt credentials
public/               -> DocumentRoot of the vhost
  index.php           -> front controller (routing + handlers)
  .htaccess           -> URL rewriting to index.php
  static/style.css
  static/theme.js     -> light/dark switch in the header (auto/light/dark, per browser)
  static/spectrum.js  -> spectrum view in the scan tab (trapezoid per BSS across the channel width)
  static/timeline.js  -> "Timeline" tab: utilization/latency over 6/24/72 h, iperf3 periods marked
src/                  -> outside public/, not directly reachable
  db.php, auth.php, admin_auth.php, Response.php, Device.php, Measurement.php
  Alerting.php        -> SMTP/Telegram delivery + alert state (alerts table)
  Settings.php        -> key-value store for settings maintained in the web UI (settings table)
  Session.php         -> web login/sessions/roles (users table, see "Users & roles")
  User.php            -> user management + invitation links (users/invites tables)
  Site.php            -> curated sites (sites table)
  Timeline.php        -> data for the "Timeline" tab (GET /devices/<id>/timeline, JSON)
  I18n.php            -> German/English: __()/te() translate, language selection
  Retention.php       -> data retention: retention per measurement type, cleanup (cleanup_data.php)
  Secrets.php         -> credential encryption (secret_key, see "Encrypting credentials")
  Audit.php           -> change log (config_audit table)
  templates/_nav.php  -> shared navigation, included in every page template
  templates/dashboard.php, templates/device_detail.php, templates/settings_alerting.php
```

## Installation

Setting up the web server, the vhost and a TLS certificate is not covered
here – use whatever your environment provides. What wlanmon needs:

1. **Copy the files** to the server, e.g. `/var/www/wlanmon` (ZIP or
   `git clone`).
2. **Point the vhost's DocumentRoot to `public/`**, not to the project root –
   otherwise `config.php` (with the credentials) and `src/` could be fetched
   through the browser.
3. **Create the database** and import the schema:

   ```sql
   CREATE DATABASE wlanmon CHARACTER SET utf8mb4;
   CREATE USER 'wlanmon'@'localhost' IDENTIFIED BY '<long random password>';
   GRANT ALL PRIVILEGES ON wlanmon.* TO 'wlanmon'@'localhost';
   ```

   ```bash
   mysql -u wlanmon -p wlanmon < schema.sql
   ```

4. **Create `config.php`** from `config.example.php` and enter the DB
   credentials. Keep it readable for the PHP user only (`chmod 600`). The
   `admin` block in there is **only** for the `/api/v1/admin/*` endpoints
   (curl automation) – the web login uses real user accounts.
5. **Create the first admin account** for the web login:

   ```bash
   php create_admin.php <username> <email> <password>
   ```

   Then log in at `/login`. Further users (roles admin/user/viewer) are
   invited through the web UI under `/users`, see "Users & roles".

## Users & roles

The web login (`/login`) uses real user accounts (`users` table) with three
roles:

| Role | View devices | Edit config, delete measurements | Manage sites/users |
|---|---|---|---|
| **Admin** | all | all | yes (all sites) |
| **User** | assigned sites only | assigned sites only | only alerting/notes of their own sites |
| **Viewer** | assigned sites only | no | no |

**Sites (`/sites`, admin + user):** a curated list of sites that
permissions are checked against – devices are **actually assigned** to them
via `/devices/<id>/config` (`devices.site_id`, admin only), not matched by
free text. A device without an assignment is invisible to `user`/`viewer`
and skipped by alerting. Admins can create/rename/delete sites; a `user`
only sees their assigned sites and can maintain the notes and the alerting
settings there (see "Alerting"). The probe client no longer reports a
site – `device_id` alone identifies the device.

**Inviting users (`/users`, admin only):** creates the account (without a
password) and sends an invitation e-mail with a link, valid for 7 days, to
set the password – using the same SMTP credentials as alerting
(`/settings/alerting`). If sending fails or SMTP is not configured, the link
is also shown directly in the web UI for manual copying.

**Deactivating users (`/users`, admin only):** "Deactivate" locks an account
without deleting it – role, sites and password are kept. A deactivated user can
no longer log in, a running session ends with the next request, and open
invitation links stop working. "Reactivate" restores access with one click.
Your own account cannot be deactivated, so there is always an admin left who
can undo it. Both actions are recorded in the change log. The status column
shows *active* (password set), *invited* (invitation not yet accepted) or
*deactivated*.

**Changing your own password:** via the key icon link in the header
(`/account/password`, all roles).

**Language (German/English):** in the user menu under "Language", on the
login page at the top right. The choice is stored in the user account
(`users.language`; on older installations the dashboard creates the column
itself on the first switch) and additionally in a cookie; without a choice
the browser language applies. The entire web UI is translated, including the
error messages of failed tests: probes from 1.0.1.40 on send structured error
codes (`error_codes`), which `src/ProbeError.php` turns into text in the UI or
alert language; older measurements show the probe's German text. The language
of alert messages (e-mail/Telegram) is chosen per site under
`/sites/<id>/alerting` (`site_alerting.language`, column created when
needed); invitation e-mails go out in the language of the inviting admin.
Texts are written in German in the code inside `__()`/`te()`/`teh()`, the
English version lives in `lang/en.php` – run `php tools/i18n_check.php`
after changes.

**Important for a stable rollout:** since this feature there is **no**
Basic Auth fallback for the web login any more – make sure to create a
working admin account with `create_admin.php` and test the login before
going live.

## Automatic updates from the Git repo

Optional: `update_dashboard.sh` checks via `git fetch` + `git rev-parse`
whether a newer version is available on `origin/<branch>` and installs it
automatically: `git pull --ff-only` in a separate checkout, then `rsync`
(without `--delete`, `config.php` excluded) into the actual docroot
directory. Unlike the probe, this needs **no** service restart – PHP files
take effect on the next request (with default settings, OPcache revalidates
the file `mtime` every few seconds).

A separate checkout instead of running `git` directly in the docroot is
deliberate: `config.php` stays untouched, and a failed `git pull` (e.g. due
to local changes in the checkout) can never leave the live files in an
inconsistent intermediate state.

**One-time setup** (the repo is public, no login or key needed):

```bash
git clone https://github.com/dgawin/wlanmon.git ~/wlanmon
chmod +x ~/wlanmon/dashboard/update_dashboard.sh
```

`LIVE_DIR` (script default: `/var/www/wlanmon`) and `SRC_DIR` (default:
`~/wlanmon/dashboard`) can be overridden via environment variables if your
paths differ:

```bash
WLANMON_DASHBOARD_LIVE_DIR=/var/www/vhosts/example.com/wlanmon.example.com ~/wlanmon/dashboard/update_dashboard.sh
```

**Enable via cron job** (every 15 minutes; switching on/off = adding/removing
the line, nothing else to configure):

```bash
crontab -e
```

```cron
*/15 * * * * /root/wlanmon/dashboard/update_dashboard.sh >> /var/log/wlanmon-dashboard-update.log 2>&1
```

**`stable` instead of `main`:** a push to `main` does not go live
immediately – `update_dashboard.sh` pulls from the `stable` branch by
default. A commit only lands there once it is deliberately released, after
[CI](../.github/workflows/ci.yml) (`php -l`/`bash -n` on every push) and
optionally a manual test:

```bash
git fetch origin
git checkout stable
git merge --ff-only origin/main
git push origin stable
```

If the `--ff-only` merge fails, `stable` has diverged (e.g. a hotfix made
directly there) – sort that out manually instead of forcing it. If the cron
job is still running an older script version (default `main`), the switch
only takes effect after the script itself has been updated – for an
immediate effect, set the variable explicitly in the cron line once:

```cron
*/15 * * * * WLANMON_DASHBOARD_BRANCH=stable /root/wlanmon/dashboard/update_dashboard.sh >> /var/log/wlanmon-dashboard-update.log 2>&1
```

**Security note:** this automatically deploys whatever is on `branch` –
only enable it if that branch really contains released code only.
`git pull --ff-only` refuses to run on local changes in the `SRC_DIR`
checkout instead of overwriting anything; a missing `SRC_DIR`/`LIVE_DIR`
also just aborts without side effects. Follow the log:

```bash
tail -f /var/log/wlanmon-dashboard-update.log
```

## Adding the first device

```bash
curl -u admin:<ADMIN_PASSWORD> -X POST https://wlanmon.example.com/api/v1/admin/devices \
  -H "Content-Type: application/json" \
  -d '{"device_id": "wlanmon-probe-01", "site_id": 1}'
```

`site_id` is optional (ID of an existing site, see `/sites`) – without it,
the device stays unassigned for now and can be assigned later via
`/devices/<id>/config` (admin only).

The response contains the `api_key` **in plain text, this one time only** –
copy it straight into the `config.yaml` of the respective probe:

```yaml
server:
  url: "https://wlanmon.example.com/api/v1"
  api_key: "<paste here>"
  verify_tls: true
```

The dashboard also shows this snippet after creating a device, with the URL
it is currently reached at. For internal servers with their own CA or
without HTTPS, see "Server connection & TLS" in the probe README.

## Optional: setting a central configuration for a device

```bash
curl -u admin:<ADMIN_PASSWORD> -X PUT \
  https://wlanmon.example.com/api/v1/admin/devices/wlanmon-probe-01/config \
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
        {"ssid": "Corp-Network", "psk": "...", "security": "wpa2-psk"},
        {"ssid": "Corp-Guest-VLAN20", "psk": "", "security": "open",
         "captive_portal_check": true, "iperf3_enabled": true,
         "iperf3_server": "192.168.1.251", "iperf3_duration_seconds": 5,
         "iperf3_port": 5202, "iperf3_download": true, "random_mac": true}
      ]
    }
  }'
```

On the client, also set `remote_config.enabled: true` in `config.yaml`.

## Rotating an API key / deleting a device

```bash
curl -u admin:<ADMIN_PASSWORD> -X POST \
  https://wlanmon.example.com/api/v1/admin/devices/wlanmon-probe-01/rotate-key

curl -u admin:<ADMIN_PASSWORD> -X DELETE \
  https://wlanmon.example.com/api/v1/admin/devices/wlanmon-probe-01
```

## Zabbix integration (monitoring/alerting)

Two read-only endpoints intended for Zabbix HTTP agent items. Separated from
the admin login by a dedicated bearer token (`zabbix.token` in
`config.php`), so Zabbix only gets read access to status data and no admin
rights (creating/deleting devices, setting config, seeing PSKs).

```php
'zabbix' => [
    'token' => 'change-me-too-long-and-random', // e.g. openssl rand -base64 32
],
```

### Endpoints

```bash
# LLD discovery: one row per registered device, for Zabbix host prototypes
curl -H "Authorization: Bearer <ZABBIX_TOKEN>" \
  https://wlanmon.example.com/api/v1/zabbix/discovery/devices
# -> {"data":[{"{#DEVICE_ID}":"wlanmon-probe-01","{#SITE}":"customer-acme-site-a"}]}

# Aggregated status of one device (master item for dependent items)
curl -H "Authorization: Bearer <ZABBIX_TOKEN>" \
  https://wlanmon.example.com/api/v1/zabbix/devices/wlanmon-probe-01/status
```

Response of `/status` (example):

```json
{
  "device_id": "wlanmon-probe-01",
  "site": "customer-acme-site-a",
  "last_seen_at": "2026-09-16 08:00:00",
  "seconds_since_last_seen": 42,
  "last_scan_at": "2026-09-16 07:59:00",
  "last_scan_network_count": 12,
  "connection_tests_total": 2,
  "connection_tests_ok": 1,
  "connection_tests_failed": 1,
  "connection_tests_failed_ssids": "Corp-Guest",
  "worst_ping_rtt_ms": 45.2,
  "max_ping_loss_percent": 20,
  "min_iperf3_mbps": 120.4,
  "connection_tests": [ { "ssid": "...", "connected": true, "...": "..." } ]
}
```

Important for Zabbix: fields without an available value (yet)
(`last_seen_at`, `seconds_since_last_seen`, `last_scan_at`,
`last_scan_network_count`, `worst_ping_rtt_ms`, `max_ping_loss_percent`,
`min_iperf3_mbps`) are omitted from the JSON entirely instead of being sent
as `null` – JSONPath reports a real error for a missing key ("path not
found"), which "Custom on fail" on the JSONPath preprocessing step catches
reliably. An explicit `null`, on the other hand, would be passed through by
the JSONPath step as the string `"null"` and only fail at the type
conversion to `Numeric (float)` – an error that "Custom on fail" on the
JSONPath step does **not** cover ("Value of type 'string' is not suitable
for value type 'Numeric (float)'. Value: 'null'.").

`connection_tests` also contains the full raw data per SSID – if more
granular per-SSID items are wanted later, a Zabbix item discovery rule with
JSONPath `$.connection_tests` and LLD macro `{#SSID}` → `$.ssid` can build
directly on it, without any change to the PHP endpoint.

### Setting up Zabbix 7.4 (discovery → host prototype → template)

1. Create a **placeholder host** (e.g. `WLANMON Discovery`); no agent needs
   to run on it – it only carries the discovery rule.
2. On that host, create a **discovery rule**: type `HTTP agent`, URL
   `{$WLANMON.URL}/zabbix/discovery/devices`, header
   `Authorization: Bearer {$WLANMON.TOKEN}`, type of information `Text`.
3. In the discovery rule, under **Host prototypes**, create a prototype:
   host name `{#DEVICE_ID}`, host group e.g. `WLANMON Probes`, link the
   template `WLANMON Probe` (see below), and set the host macros
   `{$DEVICE_ID}` = `{#DEVICE_ID}` and `{$SITE}` = `{#SITE}` – Zabbix then
   automatically creates a host per device and removes it again when the
   device disappears from wlanmon.
4. Create the **template `WLANMON Probe`** with the macros `{$WLANMON.URL}`
   (e.g. `https://wlanmon.example.com/api/v1`), `{$WLANMON.TOKEN}` (as
   *Secret text*), `{$WLANMON.OFFLINE_THRESHOLD}` (e.g. `1800` seconds),
   `{$WLANMON.RTT_THRESHOLD}` (e.g. `100` ms), `{$WLANMON.LOSS_THRESHOLD}`
   (e.g. `20` %), `{$WLANMON.MIN_THROUGHPUT}` (e.g. `50` Mbit/s).
5. **Master item** `wlanmon.status`: type `HTTP agent`, URL
   `{$WLANMON.URL}/zabbix/devices/{$DEVICE_ID}/status`, header
   `Authorization: Bearer {$WLANMON.TOKEN}`, type of information `Text`,
   interval e.g. `60s`, short history (only needed as a base).
6. **Dependent items** on the master item, each with a `JSONPath`
   preprocessing step and "Custom on fail → Discard value" **directly on
   that JSONPath step** (so a missing key in the JSON – e.g. no scan yet or
   no iperf3 configured – does not flip the item to "Not supported"; the PHP
   endpoint deliberately omits such fields instead of sending `null`, see
   above):

   | Key | JSONPath | Type |
   |---|---|---|
   | `wlanmon.seconds_since_last_seen` | `$.seconds_since_last_seen` | Numeric (unsigned) |
   | `wlanmon.last_scan_network_count` | `$.last_scan_network_count` | Numeric (unsigned) |
   | `wlanmon.connection_tests_failed` | `$.connection_tests_failed` | Numeric (unsigned) |
   | `wlanmon.connection_tests_failed_ssids` | `$.connection_tests_failed_ssids` | Text |
   | `wlanmon.worst_ping_rtt_ms` | `$.worst_ping_rtt_ms` | Numeric (float) |
   | `wlanmon.max_ping_loss_percent` | `$.max_ping_loss_percent` | Numeric (float) |
   | `wlanmon.min_iperf3_mbps` | `$.min_iperf3_mbps` | Numeric (float) |

7. **Triggers** (expressions in essence, item keys as above):
   - Device offline: `last(/WLANMON Probe/wlanmon.seconds_since_last_seen) > {$WLANMON.OFFLINE_THRESHOLD}`
   - Connection test failed: `last(/WLANMON Probe/wlanmon.connection_tests_failed) > 0`
     (the description can reference `{ITEM.LASTVALUE:wlanmon.connection_tests_failed_ssids}`)
   - High ping RTT: `last(/WLANMON Probe/wlanmon.worst_ping_rtt_ms) > {$WLANMON.RTT_THRESHOLD}`
   - High packet loss: `last(/WLANMON Probe/wlanmon.max_ping_loss_percent) > {$WLANMON.LOSS_THRESHOLD}`
   - Low throughput: `last(/WLANMON Probe/wlanmon.min_iperf3_mbps) > 0 and last(/WLANMON Probe/wlanmon.min_iperf3_mbps) < {$WLANMON.MIN_THROUGHPUT}`
     (the `> 0` condition prevents false alarms when no iperf3 server is
     configured for a device at all)

Do not attach the template directly to real hosts, only via the host
prototype from step 3 – Zabbix then automatically maintains one host,
including all items/triggers, per device registered in wlanmon.

## Alerting (e-mail/Telegram)

Complements the Zabbix integration above with native push alerting straight
from wlanmon: `check_alerts.php` periodically (cron job, no web server
trigger) checks every device against these rules and notifies via e-mail
and/or Telegram when needed:

- **`offline`**: not reported for longer than `offline_after_minutes` (or
  never reported – then measured against the device's creation time instead
  of `last_seen_at`).
- **`ssid_failing:<SSID>`**: the last `consecutive_test_failures`
  connection tests of this SSID were *all* unable to connect. Skipped while
  the device itself counts as offline (those would only be stale
  measurements). The message includes the probe's error text of the latest
  test (e.g. "no response from the RADIUS server").
- **`auth_slow:<SSID>`** (optional, only when "802.1X counts as slow from"
  is set for the site): the 802.1X login (EAP + 4-way handshake,
  `auth_seconds`) took longer than `auth_slow_seconds` in *each* of the last
  `consecutive_test_failures` tests that have an 802.1X time. "Each" rather
  than the average, so a single outlier does not trigger it. Useful for a
  slow or poorly reachable RADIUS server, e.g. a cloud RADIUS. All-clear as
  soon as the latest value is below the threshold again.

A **new** problem is notified immediately (if currently within the allowed
time window, see below). If it persists, the next notification only comes
after `repeat_after_minutes` (no spam on every cron tick). Once it clears,
an **all-clear** is sent. The state lives in the `alerts` table and is always
kept current regardless of the time window – only the actual delivery is
suppressed outside of it.

**Configuration is split in two:**

1. **SMTP/Telegram credentials** (`/settings/alerting`, admin only): one
   shared channel for all sites (`settings` table, key `alerting` – see
   `src/Settings.php`). E-mail needs SMTP credentials (custom SMTP client,
   no Composer/PHPMailer), Telegram only a bot token (create it via
   `@BotFather` in Telegram).
2. **Whether/to whom/when each site is alerted** (`/sites` →
   <i>Alerting</i>, admin for all sites, `user` only for their assigned
   ones): on/off, thresholds, recipients/chat ID and a **time window**
   (always, or only on certain weekdays/hours, e.g. Mon–Fri 8 am–8 pm). A
   test alert with the saved settings can also be sent from there at the
   push of a button (the result per channel is shown; it deliberately
   ignores the on/off switch and the time window).

Devices must be assigned to a site for this (`/devices/<id>/config`, admin
only) – without an assignment, `check_alerts.php` does not evaluate the
device at all.

Set up a cron job that calls `check_alerts.php` periodically:

```bash
crontab -e
```

```cron
# Deliberately its own, tighter schedule than the auto-update cron job (see
# above) - alerting should react faster than a code update check.
*/5 * * * * php /path/to/check_alerts.php >> /var/log/wlanmon-alerts.log 2>&1
```

Delivery for a specific site can also be tested from the command line
without waiting for a real problem (works regardless of the site's on/off
switch and time window, does not touch the `alerts` table):

```bash
php check_alerts.php --test <site-name>
```

**Finding the Telegram chat ID:** send a short message to your bot (or to a
group it has been invited to), then open
`https://api.telegram.org/bot<TOKEN>/getUpdates` in the browser and read
`"chat":{"id": ...}`.

**Known limitation:** an SSID removed from `connection_tests.targets` can
keep an already triggered `ssid_failing` problem active forever, because no
new connection tests will ever arrive for it to resolve the state – in that
case resolve the affected alert manually once:

```sql
UPDATE alerts SET resolved_at = UTC_TIMESTAMP()
WHERE device_id = '<device-id>' AND rule = 'ssid_failing:<SSID>' AND resolved_at IS NULL;
```

## Version

The version number lives in the `VERSION` file in the project root (shown
on the About page at `/about`), maintained by hand, no automatic bump.
Scheme (the same applies to the probe):

- **Release:** three digits, e.g. `1.0.1`.
- **Every commit on `main`** afterwards increments a fourth digit, in the
  same commit: `1.0.1.1`, `1.0.1.2`, ... That way the displayed version
  shows exactly which state is running on a device/server.
- **Next release:** the fourth digit is dropped, the third one goes up by 1
  (`1.0.1.7` -> `1.0.2`), then again `1.0.2.1`, ...

`stable` takes over the version number unchanged from `main` via
fast-forward. The probe client sends its own `VERSION` (see its README) as
`probe_version` with every measurement batch; the dashboard stores it in
`devices.probe_version` and shows it both in the device list and on the
device detail page, so outdated probe versions in the fleet can be spotted
at a glance.

## OmniVista Cirrus

**Timeline:** on every run, `sync_cirrus_aps.php` appends the channel
utilization of all radios to the `cirrus_radio_history` table (30 days,
created automatically when needed – no manual step). The "Timeline" tab of
the device page shows these values as circles next to the BSS load from the
probe scans. The resolution matches the cron interval (example below:
hourly).

Optional, read-only connection to the public REST API of OmniVista Cirrus
(Alcatel-Lucent Enterprise) – currently for one purpose only: resolving the
AP name/location for a BSSID seen in a scan, shown as a separate column in
the roaming candidates table on the device detail page (the AP name cannot
be read reliably from the beacon itself, but Cirrus as the controller knows
the mapping anyway).

In `config.php` (see `config.example.php`, block `cirrus`):

```php
'cirrus' => [
    'base_url' => 'https://eu.manage.ovcirrus.com',
    'org_id' => '...',      // from the Cirrus URL: .../organizations/<org_id>/...
    'app_id' => '...',      // Cirrus organization -> Settings -> API Applications
    'app_secret' => '...',
    'email' => '...',       // regular Cirrus login, no separate API account
    'password' => '...',
],
```

If the block is missing or a field is empty, the integration simply stays
inactive – no errors anywhere else in the dashboard.

The actual fetching is done by `sync_cirrus_aps.php` (via cron job, e.g.
hourly), which authenticates with `app_id`/`app_secret`/`email`/`password`
and writes the device inventory (base MAC -> name/location) into the local
`cirrus_aps` table – no live API call on every dashboard page view:

```cron
0 * * * * php /path/to/sync_cirrus_aps.php >> /var/log/wlanmon-cirrus-sync.log 2>&1
```

**Resolution works via the MAC prefix, not an exact BSSID match:** Cirrus'
device inventory (`.../sites/devices`) only returns one base MAC address per
AP – on multi-radio/multi-SSID APs, the BSSID actually transmitted per band/
SSID differs from it (confirmed in practice, e.g. `...:a1:80` as the base MAC
vs. `...:a1:81`/`...:a1:91`/`...:a1:a1`/etc. as the actual radio BSSIDs, see
`iwconfig` on the AP itself). An additional Cirrus endpoint listing
individual detected radio BSSIDs (WIPS "Friendly AP" list) was tried but
dropped: live, it demonstrably did not cover all BSSIDs actually transmitted
(missing coverage of individual bands/SSIDs), and its own per-entry `apName`
field was unreliable as well (BSSIDs of the same radio were partly assigned
to different APs).

Instead, `cirrus_lookup_ap()` in `src/Cirrus.php` matches **every BSSID
actually seen in a probe scan** by its first 5 octets against the base MACs
in `cirrus_aps` (`cirrus_mac_prefix5()`/`cirrus_build_prefix_map()`) – in
the Cirrus organization this was developed against, APs evidently derive
their radio/SSID BSSIDs from their base MAC by changing only the last octet.
This also covers BSSIDs that Cirrus never reported individually. Several
base MACs with the same prefix (only the last octet differing) make the
mapping ambiguous – in that case deliberately no name rather than a wrong
one.

**Access points, radio state:** `/access-points` (nav entry, only visible
when the Cirrus connection is configured, for all logged-in roles) shows
channel, channel utilization, noise floor and transmit power per known AP
and band – this helps judge whether a failed or slow connection test was
caused by the RF situation.

**Undocumented endpoint, on purpose:** the endpoint publicly described in
`/apidoc/swagger.json` for this
(`GET .../wlan-analytics/access-points/rf-details/{apMac}`, one AP per call)
demonstrably always returned `"radios": []` live, even though Cirrus itself
(web UI under Monitor → Network → Analytics → RF Details) has the data –
presumably a bug/limitation of that one documented endpoint. Using the
browser DevTools (network tab of the Cirrus UI while opening that view),
what the UI itself uses was found instead:
`POST {base_url}/api/organizations/{orgId}/wlan-analytics/access-points/rf-details/pagination`
(**no** `/ov/v1/` in the path, unlike all other endpoints here) with the
JSON body `{fields: null, filters: "{}", limit, offset, organizationId,
scope: "org", scopeId: [orgId], search: "", searchFields: "", sort:
'[{"apMac":"DESC"}]'}` – it returns all APs at once (paginated via
`limit`/`offset`) and demonstrably also works with the application bearer
token (not just the browser session). See `cirrus_fetch_all_ap_radios()` in
`src/Cirrus.php`. Being undocumented, this can change at any time without
notice – in that case `sync_cirrus_aps.php` aborts in a controlled way with
exit code 1 (the base MAC inventory stays untouched) instead of filling
`cirrus_ap_radios` with garbage.

This, too, is a pure cache (no history, completely refilled on every sync
run) – a history view would need the time-range based `wlan-analytics`
endpoints instead (`ap-health`, `channel-utilization-evolution`, …), not
implemented at the moment.

## Data retention & backup

**Retention:** how long measurements are kept is set by the admin in the
dashboard under user menu → "Data retention" (`/settings/retention`): Wi-Fi
scans (default 90 days – by far the largest data item, roughly 15–30 MB per
device and day at one scan per minute) and connection/LAN tests (default
365 days, also applies to resolved alerts). 0 = unlimited. The page also
shows the number and age of stored measurements as well as the status of
cleanup and backup.

**Cleanup:** `cleanup_data.php` permanently deletes everything older (in
batches, without blocking the probes) plus expired invitations. Without this
cron job the database grows without limit.

**Backup:** `backup_db.php` writes a full, gzip-compressed dump every night
(`mariadb-dump`/`mysqldump`, package `mariadb-client`) to `backup.dir` from
`config.php` (default `/var/backups/wlanmon`) and deletes backups older than
`backup.keep_days` (default 14 days) – but only after a successful new
backup. The dumps contain Wi-Fi passwords, 802.1X and SMTP credentials in
plain text (file mode 0600) unless credential encryption is enabled; for
disaster recovery, also copy them to another machine/a NAS.

Cron jobs (backup before cleanup, so deleted data is still in that night's
backup):

```cron
15 2 * * * php /path/to/backup_db.php >> /var/log/wlanmon-backup.log 2>&1
30 3 * * * php /path/to/cleanup_data.php >> /var/log/wlanmon-cleanup.log 2>&1
```

In the Docker deployment both run in the cron container (01:00 and 02:00
UTC), the backups are stored in the `backups` volume (`WLANMON_BACKUP_DIR`,
`WLANMON_BACKUP_KEEP_DAYS`).

Restoring:

```bash
gunzip < /var/backups/wlanmon/wlanmon-2026-09-30_0215.sql.gz | mysql -u wlanmon -p wlanmon
```

## Captures of failed tests

When a connection test fails, probes from 1.0.1.35 on upload a capture of the
failed connection attempt: a pcap (EAPOL, DHCP, ARP, DNS, ICMP) and the Wi-Fi
adapter's kernel events (authentication, association, deauth with status/reason
codes), and from probe 1.0.1.38 on the wpa_supplicant log. Details on what is
and is not included: probe README, "Capturing failed tests".

- **Download:** links "pcap", "Events" and "wpa_supplicant" in the error column of the test table
  on the device page – for admins and users with access to the device, not for
  viewers. The pcap opens in Wireshark.
- **Switch off** per device: device configuration → "Capture failed tests".
- **Storage:** table `captures` in the database (created automatically on the
  first capture), so database backups include them. Retention: user menu →
  "Data retention" → captures, default **30 days**. Deleting a device deletes
  its captures.
- **Limits:** up to 2 MB pcap, 256 KB events and 256 KB wpa_supplicant log per capture. PHP's default
  `upload_max_filesize` (2 MB) is enough; lower values reject captures with
  HTTP 413 and the probe drops them.
- **Privacy:** captures contain MAC addresses and possibly 802.1X identities.

## Encrypting credentials

PSKs, 802.1X passwords and private keys, portal passwords as well as the
SMTP password and the Telegram bot token are stored encrypted in the
database (libsodium, `src/Secrets.php`) once a key has been set up. The
forms never display stored values again ("stored – leave empty to keep",
checkbox "remove stored value").

1. Generate a key: `php tools/secrets.php generate-key`
2. Add it to `config.php`: `'secret_key' => '<key>',` (Docker:
   `WLANMON_SECRET_KEY`). `config.php` should only be readable by the PHP
   user (`chmod 600`).
3. Encrypt existing values: user menu → "Data retention" → "Encrypt all
   now", or `php tools/secrets.php encrypt-all`. From then on, new and
   changed values are stored encrypted automatically.
4. **Keep the key separate from the database backup** (e.g. in a password
   manager). Without it, the credentials can no longer be decrypted and have
   to be entered again – until then the affected devices keep their last
   loaded configuration.

Effect: database dumps, backups and an SQL leak only contain ciphertext. It
does not help against someone who takes over the whole server (key and
database). Status: `php tools/secrets.php status` or the data retention
page.

**Change log:** who changed what and when in device configuration, site
assignment, API keys, sites, users, alerting and data retention is listed
under user menu → "Change log" (admin) and per device on its configuration
page. Secret fields only appear as "changed", never with their value.
Retention is the same as for the tests (data retention).

## Security notes

- Choose `ADMIN_PASSWORD` and the DB password long and random
  (e.g. `openssl rand -base64 32`), never reuse the example values
- `config.php` lives outside `public/` and therefore cannot be fetched
  directly through the web server – **provided** the vhost really uses
  `public/` as its DocumentRoot
- Device API keys are valid indefinitely until rotated – rotate
  immediately if you suspect a compromise
- Do not commit `config.php` to the Git repo (keep `.gitignore` up to date)
- All web forms are protected by a CSRF token (one token per session, see
  `csrf_token()`/`require_csrf()` in `src/Session.php`) – this only concerns
  the session cookie UI, not the `/api/v1/*` endpoints (Bearer/Basic auth)

## Testing locally (without Apache)

With PHP's built-in development server:

```bash
cd public
php -S 127.0.0.1:8000
```

Note: the built-in server does not evaluate `.htaccess`, but all requests
end up at `index.php` anyway as long as you call paths like
`/api/v1/measurements` or `/devices/<id>` exactly like that (routing happens
in the PHP code itself, not in Apache). For a complete test including the
rewrite rules, a real Apache is still recommended.

## License

MIT – see [LICENSE](../LICENSE).
