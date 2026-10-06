# WLANMON – Server (PHP + MySQL)

The dashboard is where the measurements of all your probes come together. It
stores them in MySQL/MariaDB, shows them per site and device, raises alerts
when something goes wrong and hands each probe its test configuration.

It runs as a classic PHP application on any Apache + PHP server, **or** as a
self-contained Docker stack (web, database, cron, TLS proxy) – handy for a
site whose probes can only reach an internal network. See [Docker](#docker).

**What you get:**

- **Device list** with the latest result per SSID, colour-coded against your
  thresholds, with filters, sorting and automatic refresh
- **Device page** with one tab per SSID (connection tests, timings, iperf3),
  LAN test, scans with a spectrum view and roaming candidates, a timeline of
  channel load and latency, and the probe's system values (CPU, memory,
  temperature, power supply)
- **Central configuration:** target SSIDs, passwords, 802.1X, captive portals,
  intervals – maintained in the browser, picked up by the probes on their own;
  with SSIDs and profiles, many probes share one configuration
- **Alerts** by e-mail and Telegram, plus endpoints for Zabbix
- **Captures** of failed tests (pcap, adapter events, wpa_supplicant log)
- Users with roles and sites, change log, data retention and backups,
  encrypted credentials, German and English

## Requirements

- A web server with PHP ≥ 7.4 (tested with 8.3) and the `pdo_mysql`
  extension; with Apache, `mod_rewrite` and `AllowOverride All` for the
  bundled `.htaccess`
- MySQL ≥ 5.7 or MariaDB ≥ 10.2
- HTTPS is strongly recommended – the probes fetch their configuration
  including Wi-Fi passwords from the dashboard (see "Server connection & TLS"
  in the [probe README](../probe/README.md#server-connection--tls))

Or simply Docker – then the stack brings all of this along.

## Docker

A complete instance in four containers. The classic installation below stays
untouched by it.

| Container | Purpose |
|---|---|
| `db` | MariaDB 11, data in the `db-data` volume |
| `web` | Apache + PHP 8.3 with the dashboard; sets up and updates the database schema on every start (`docker/init_db.php`) |
| `cron` | same image; runs alert checks every 5 minutes, the Cirrus sync hourly, and backup and cleanup at night (`docker/cron.sh`) |
| `proxy` | Caddy: TLS to the outside, forwards to `web` |

Configuration comes from environment variables (`docker/config.php` turns
them into the usual `config.php`). Updates arrive as a rebuilt image, so
`update_dashboard.sh` is not needed.

**Setting up under Portainer** (stack straight from this repo):

1. *Stacks → Add stack → Repository*: URL
   `https://github.com/dgawin/wlanmon`, Reference
   `refs/heads/stable`, Compose path `dashboard/docker-compose.yml`.
   Enable *GitOps updates*
   (polling, e.g. 5 min) – Portainer then rebuilds whenever `stable` moves on.
2. *Environment variables*:

   | Variable | Value |
   |---|---|
   | `WLANMON_DOMAIN` | Name or IP under which probes and browsers reach the dashboard (must match the probes' `server.url` exactly), e.g. `wlanmon.intern` or `192.168.1.10` |
   | `WLANMON_CADDY_FLAGS` | `--internal-certs` for an internal network (own CA, see below); leave empty for a public name (Let's Encrypt, ports 80/443 reachable from the internet) |
   | `WLANMON_DB_PASSWORD` | long random value, e.g. `openssl rand -base64 32` |
   | `WLANMON_ADMIN_PASSWORD` | password for the admin API (`/api/v1/admin/*`); empty = admin API off |
   | optional | `WLANMON_ZABBIX_TOKEN`, `WLANMON_CIRRUS_ORG_ID`/`_APP_ID`/`_APP_SECRET`/`_EMAIL`/`_PASSWORD`, `WLANMON_SECRET_KEY`, `WLANMON_BACKUP_DIR`/`_KEEP_DAYS`, `WLANMON_HTTP_PORT`/`WLANMON_HTTPS_PORT` (default 80/443) |
3. *Deploy the stack*, then create the first login (the container name
   depends on the stack name, see Portainer):

   ```bash
   docker exec -it <stack>-web-1 php create_admin.php <user> <email> <password>
   ```

**Probes and the internal CA:** with `--internal-certs`, Caddy issues the
certificate from its own CA. Copy its root certificate once to every probe of
the site:

```bash
docker cp <stack>-proxy-1:/data/caddy/pki/authorities/local/root.crt ./wlanmon-ca.crt
```

In the probe's setup wizard choose "Internal CA" and enter the path, or set it
in `/etc/wlanmon-probe/config.yaml`:

```yaml
server:
  url: "https://wlanmon.intern/api/v1"
  verify_tls: "/etc/wlanmon-probe/wlanmon-ca.crt"
```

The CA lives in the `caddy-data` volume. Keep that volume – otherwise Caddy
creates a new CA and every probe needs the new certificate.

**Backing up and moving:**

```bash
docker exec <stack>-db-1 sh -c 'mariadb-dump -u wlanmon -p"$MARIADB_PASSWORD" wlanmon' > wlanmon-backup.sql
docker exec -i <stack>-db-1 sh -c 'mariadb -u wlanmon -p"$MARIADB_PASSWORD" wlanmon' < wlanmon-backup.sql
```

A dump of a classic installation can be imported the same way; `web` adds any
missing columns on its next start.

## Classic installation

How you set up the web server, vhost and TLS certificate is up to your
environment. What wlanmon needs:

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

4. **Create `config.php`** from `config.example.php`, enter the database
   credentials and make it readable for the PHP user only (`chmod 600`). The
   `admin` block in there is **only** for the `/api/v1/admin/*` endpoints
   (scripting with curl) – people log in with their own user accounts.
5. **Create the first admin account:**

   ```bash
   php create_admin.php <username> <email> <password>
   ```

   Then log in at `/login` and test it before going live – there is no other
   way in. Invite everyone else under `/users`.
6. **Set up the cron jobs** for [alerting](#alerting-e-mailtelegram),
   [backup and cleanup](#data-retention--backup) and, if you use it, the
   [Cirrus sync](#omnivista-cirrus).

## Adding a probe

1. In the dashboard: **Devices → Add device**. Enter the device ID and
   optionally the site. The API key is shown **this one time only**, together
   with the dashboard URL the probe should use.
2. On the probe: install it and enter device ID, URL and key in the setup
   wizard – see the probe README. The wizard tests the connection right away.
3. Back in the dashboard: open the device's **configuration** and either
   choose a [profile](#ssids-and-profiles) or add the target SSIDs directly.
   The probe picks up the change by itself within a minute.

A probe without a site is only visible to admins and is not checked by
alerting, so assign one.

**Lost the key, or a probe was stolen?** Device configuration → rotate the
API key. The old key stops working immediately; enter the new one with
`sudo wlanmon setup` on the probe.

The same is possible without the browser, through the admin API (password
from `config.php`, block `admin`):

```bash
# Create a device - the response contains the api_key, only this once
curl -u admin:<ADMIN_PASSWORD> -X POST https://wlanmon.example.com/api/v1/admin/devices \
  -H "Content-Type: application/json" \
  -d '{"device_id": "wlanmon-probe-01", "site_id": 1}'

# Set its configuration (same structure as in the probe's config.example.yaml)
curl -u admin:<ADMIN_PASSWORD> -X PUT \
  https://wlanmon.example.com/api/v1/admin/devices/wlanmon-probe-01/config \
  -H "Content-Type: application/json" \
  -d '{
    "scan": {"interval_seconds": 60},
    "connection_tests": {
      "interval_seconds": 900,
      "targets": [
        {"ssid": "Corp-Network", "psk": "...", "security": "wpa2-psk"},
        {"ssid": "Corp-Guest", "psk": "", "security": "open",
         "captive_portal_check": true, "iperf3_server": "192.168.1.251"}
      ]
    }
  }'

# Rotate the key / delete the device
curl -u admin:<ADMIN_PASSWORD> -X POST \
  https://wlanmon.example.com/api/v1/admin/devices/wlanmon-probe-01/rotate-key
curl -u admin:<ADMIN_PASSWORD> -X DELETE \
  https://wlanmon.example.com/api/v1/admin/devices/wlanmon-probe-01
```

## SSIDs and profiles

With more than a handful of probes, maintaining every device on its own gets
tedious – and a changed Wi-Fi password means editing all of them. Two pages
take care of that:

- **SSIDs** (`/ssids`): each network once, with everything the test needs –
  security, password or 802.1X, captive portal, iperf3, ping target, random
  MAC. Change a password here and every profile using it gets the new one.
- **Profiles** (`/profiles`): a set of SSIDs in test order, plus the test
  settings (scan and test intervals, ping, iperf3 defaults, LAN test,
  captures, heartbeat).

In a device's configuration, **"This device uses"** picks a profile or the
device's own settings. The own settings stay saved while a profile is active,
so switching back loses nothing. **"Turn into a profile"** creates SSIDs and a
profile from a device's current settings and assigns it – the quickest way to
start. The probe notices none of this: it gets the same configuration as
before and picks up every change with its next heartbeat.

Who maintains what follows the sites: SSIDs and profiles are either global
(admins only, usable everywhere) or belong to a site (maintained by that
site's users, usable only there). A site profile may contain global SSIDs and
those of its site; a device may use global profiles and those of its site. A
profile still in use cannot be deleted.

## Users & roles

Everyone logs in with their own account and one of three roles:

| Role | Sees devices | Edits configuration, deletes measurements | Manages sites and users |
|---|---|---|---|
| **Admin** | all | all | yes (all sites) |
| **User** | of their sites | of their sites | only alerting and notes of their sites |
| **Viewer** | of their sites | no | no |

**Sites** (`/sites`) decide who sees what. Admins create them and assign each
device to one (device configuration). Users can keep notes and the alerting
settings of their own sites up to date.

**Inviting** (`/users`, admins): creates the account and e-mails a link, valid
for 7 days, to set a password. It uses the SMTP settings from alerting; if
sending fails, the link is shown on screen to copy.

**Deactivating** locks an account without deleting it – role, sites and
password are kept, a running session ends with the next click, and open
invitations stop working. "Reactivate" undoes it. You cannot deactivate
yourself, so there is always an admin left.

Everyone can change their own password via the key icon in the header.

**Language:** German or English, chosen in the user menu (or at the top right
of the login page) and remembered in the account; otherwise the browser
language applies. Alert messages use the language set per site, invitation
e-mails the language of the inviting admin.

## Alerting (e-mail/Telegram)

`check_alerts.php` checks all devices every few minutes and reports:

- **offline** – the probe has not reported for longer than
  `offline_after_minutes`
- **SSID failing** – the last `consecutive_test_failures` tests of an SSID
  could not connect. The message includes the error of the latest test (e.g.
  "no response from the RADIUS server"). Not checked while the probe itself is
  offline.
- **802.1X slow** (optional) – the 802.1X login took longer than
  `auth_slow_seconds` in *each* of the last tests, so a single outlier does
  not trigger it. Useful to spot a slow or badly reachable RADIUS server.
- **802.1X fails often** (optional) – at least `eap_abort_rate_pct` percent of
  the tests on an 802.1X SSID failed within the time window (default 60
  minutes), and at least 3 of them. This catches logins that fail on and off
  long before the SSID goes down completely. The message names the most
  common cause. All-clear once the rate drops below half the threshold; a
  complete outage is left to "SSID failing".

A new problem is reported right away, a lasting one again after
`repeat_after_minutes`, and you get an all-clear once it is over.

**Two places to configure:**

1. **Channels** (`/settings/alerting`, admins): SMTP server for e-mail and a
   bot token for Telegram (create the bot with `@BotFather`). One set for all
   sites.
2. **Per site** (`/sites` → *Alerting*): on/off, thresholds, recipients or
   Telegram chat ID, language and a **time window** (e.g. Mon–Fri 8 am–8 pm;
   outside it problems are tracked but not sent). A button sends a test
   message with the saved settings. The thresholds for slow association, DHCP
   and 802.1X here also set the colours in the device list.

Only devices assigned to a site are checked. The cron job:

```cron
*/5 * * * * php /path/to/check_alerts.php >> /var/log/wlanmon-alerts.log 2>&1
```

Test delivery for a site from the command line (ignores on/off and time
window, changes no alert state): `php check_alerts.php --test <site-name>`.

**Telegram chat ID:** send your bot a message (or add it to a group), then
open `https://api.telegram.org/bot<TOKEN>/getUpdates` and read
`"chat":{"id": ...}`.

**Removed SSIDs:** once an SSID is no longer part of a device's configuration
(own settings or profile), its old test results no longer trigger alerts, and
an alert still open for it is closed quietly.

## Zabbix integration

If you monitor with Zabbix, two read-only endpoints feed it. They use their
own token (`zabbix.token` in `config.php`), so Zabbix can read status data but
cannot change anything or see passwords.

```php
'zabbix' => [
    'token' => 'change-me-too-long-and-random', // e.g. openssl rand -base64 32
],
```

```bash
# Discovery: one row per device, for Zabbix host prototypes
curl -H "Authorization: Bearer <ZABBIX_TOKEN>" \
  https://wlanmon.example.com/api/v1/zabbix/discovery/devices
# -> {"data":[{"{#DEVICE_ID}":"wlanmon-probe-01","{#SITE}":"customer-acme-site-a"}]}

# Status of one device (master item for dependent items)
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

Fields without a value yet (no scan, no iperf3, ...) are left out instead of
being sent as `null`. That way "Custom on fail" on the JSONPath step catches
them; an explicit `null` would only fail later at the conversion to a number,
where "Custom on fail" does not help.

`connection_tests` carries the full data per SSID. For per-SSID items later,
an item discovery rule with JSONPath `$.connection_tests` and `{#SSID}` →
`$.ssid` can build on it without changes here.

### Setting up Zabbix 7.4

1. Create a **placeholder host** (e.g. `WLANMON Discovery`). It needs no
   agent; it only carries the discovery rule.
2. On it, a **discovery rule**: type `HTTP agent`, URL
   `{$WLANMON.URL}/zabbix/discovery/devices`, header
   `Authorization: Bearer {$WLANMON.TOKEN}`, type of information `Text`.
3. In the rule, a **host prototype**: host name `{#DEVICE_ID}`, a host group
   (e.g. `WLANMON Probes`), the template `WLANMON Probe` (below), and the host
   macros `{$DEVICE_ID}` = `{#DEVICE_ID}` and `{$SITE}` = `{#SITE}`. Zabbix
   then creates a host for every probe and removes it when the probe is
   deleted in wlanmon.
4. The **template `WLANMON Probe`** with the macros `{$WLANMON.URL}` (e.g.
   `https://wlanmon.example.com/api/v1`), `{$WLANMON.TOKEN}` (as *Secret
   text*), `{$WLANMON.OFFLINE_THRESHOLD}` (e.g. `1800` s),
   `{$WLANMON.RTT_THRESHOLD}` (e.g. `100` ms), `{$WLANMON.LOSS_THRESHOLD}`
   (e.g. `20` %) and `{$WLANMON.MIN_THROUGHPUT}` (e.g. `50` Mbit/s).
5. **Master item** `wlanmon.status`: type `HTTP agent`, URL
   `{$WLANMON.URL}/zabbix/devices/{$DEVICE_ID}/status`, header
   `Authorization: Bearer {$WLANMON.TOKEN}`, type `Text`, interval e.g. `60s`,
   short history.
6. **Dependent items**, each with a `JSONPath` step and "Custom on fail →
   Discard value" **on that same step**:

   | Key | JSONPath | Type |
   |---|---|---|
   | `wlanmon.seconds_since_last_seen` | `$.seconds_since_last_seen` | Numeric (unsigned) |
   | `wlanmon.last_scan_network_count` | `$.last_scan_network_count` | Numeric (unsigned) |
   | `wlanmon.connection_tests_failed` | `$.connection_tests_failed` | Numeric (unsigned) |
   | `wlanmon.connection_tests_failed_ssids` | `$.connection_tests_failed_ssids` | Text |
   | `wlanmon.worst_ping_rtt_ms` | `$.worst_ping_rtt_ms` | Numeric (float) |
   | `wlanmon.max_ping_loss_percent` | `$.max_ping_loss_percent` | Numeric (float) |
   | `wlanmon.min_iperf3_mbps` | `$.min_iperf3_mbps` | Numeric (float) |

7. **Triggers:**
   - Device offline: `last(/WLANMON Probe/wlanmon.seconds_since_last_seen) > {$WLANMON.OFFLINE_THRESHOLD}`
   - Connection test failed: `last(/WLANMON Probe/wlanmon.connection_tests_failed) > 0`
     (the description can show `{ITEM.LASTVALUE:wlanmon.connection_tests_failed_ssids}`)
   - High ping RTT: `last(/WLANMON Probe/wlanmon.worst_ping_rtt_ms) > {$WLANMON.RTT_THRESHOLD}`
   - High packet loss: `last(/WLANMON Probe/wlanmon.max_ping_loss_percent) > {$WLANMON.LOSS_THRESHOLD}`
   - Low throughput: `last(/WLANMON Probe/wlanmon.min_iperf3_mbps) > 0 and last(/WLANMON Probe/wlanmon.min_iperf3_mbps) < {$WLANMON.MIN_THROUGHPUT}`
     (`> 0` avoids alarms for probes without an iperf3 server)

Link the template only through the host prototype, never to hosts directly.

## OmniVista Cirrus

If your access points are managed by Alcatel-Lucent OmniVista Cirrus, the
dashboard can read from its REST API (read-only) and show:

- the **AP name and location** for every BSSID a probe sees, in the roaming
  candidates on the device page
- the **radio state** of all APs under `/access-points`: channel, channel
  utilisation, noise floor and transmit power per band – useful to judge
  whether a failed or slow test was down to the RF situation
- the **channel utilisation over time** in the device's "Timeline" tab, next
  to the values from the probe's own scans (kept 30 days)
- the **login attempts behind a failed 802.1X test**: click "Cirrus login" next
  to the test to see what Cirrus recorded for the probe's MAC around that time
  – accepted or rejected, the reject reason, AP, policy, role and VLAN.
  Logins that break off in the middle of EAP (e.g. during the TLS setup) may
  not appear there at all. Fetched on click only and then stored with the
  test (admins and users).

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

Without this block the feature simply stays off. The data is fetched by a cron
job and cached locally, so page views never wait for Cirrus:

```cron
0 * * * * php /path/to/sync_cirrus_aps.php >> /var/log/wlanmon-cirrus-sync.log 2>&1
```

Good to know:

- **Name lookup by MAC prefix.** Cirrus lists only one base MAC per AP, while
  the AP sends a different BSSID per band and SSID. wlanmon therefore matches
  the first five bytes of a BSSID against the base MACs – in the organisation
  this was developed with, APs only change the last byte. If two APs share a
  prefix, it shows no name rather than a wrong one.
- **Radio state uses an undocumented endpoint**
  (`POST {base_url}/api/organizations/{orgId}/wlan-analytics/access-points/rf-details/pagination`,
  the one the Cirrus web UI uses itself), because the documented one always
  returned empty radio lists. It may change without notice; the sync then
  stops cleanly and leaves the AP names alone. Details in `src/Cirrus.php`.

## Data retention & backup

**Retention** (user menu → "Data retention", admins): how long to keep scans
(default 90 days – by far the largest part, roughly 15–30 MB per device and
day at one scan per minute), tests and resolved alerts (default 365 days),
captures (30 days) and the probes' system values (like scans). 0 = keep
forever. The page also shows how much is stored and when cleanup and backup
last ran.

**Cleanup:** `cleanup_data.php` deletes everything older, in small batches so
the probes are not blocked. Without this cron job the database grows forever.

**Backup:** `backup_db.php` writes a gzip-compressed full dump every night
(needs `mariadb-dump` or `mysqldump`, package `mariadb-client`) to
`backup.dir` (default `/var/backups/wlanmon`) and deletes backups older than
`backup.keep_days` (default 14) – only after a successful new one. Unless
credentials are encrypted, the dumps contain Wi-Fi, 802.1X and SMTP passwords
in plain text (file mode 0600). Copy them to another machine as well.

Backup runs before cleanup, so deleted data is still in that night's backup:

```cron
15 2 * * * php /path/to/backup_db.php >> /var/log/wlanmon-backup.log 2>&1
30 3 * * * php /path/to/cleanup_data.php >> /var/log/wlanmon-cleanup.log 2>&1
```

With Docker, both run in the `cron` container (01:00 and 02:00 UTC) and the
backups go to the `backups` volume.

Restoring:

```bash
gunzip < /var/backups/wlanmon/wlanmon-2026-09-30_0215.sql.gz | mysql -u wlanmon -p wlanmon
```

## Captures of failed tests

When a connection test fails, the probe uploads what it recorded: a pcap
(EAPOL, DHCP, ARP, DNS, ICMP), the adapter's events (authentication,
association, deauth with status and reason codes) and the wpa_supplicant log.
What is and is not included: probe README, "Capturing failed tests".

- **Download:** links "pcap", "Events" and "wpa_supplicant" next to the failed
  test on the device page – for admins and users, not viewers. The pcap opens
  in Wireshark.
- **Switch off** per device: device configuration → "Capture failed tests".
- **Storage:** in the database (so backups include them), kept 30 days by
  default. Deleting a device deletes its captures.
- **Limits:** up to 2 MB pcap and 256 KB each for events and log. PHP's
  default `upload_max_filesize` (2 MB) is enough; with a lower value captures
  are rejected and the probe drops them.
- **Privacy:** captures contain MAC addresses and possibly 802.1X identities.

## Encrypting credentials

Once a key is set up, Wi-Fi and 802.1X passwords, private keys, portal
passwords, the SMTP password and the Telegram token are stored encrypted
(libsodium). Forms never show stored values again ("stored – leave empty to
keep").

1. Generate a key: `php tools/secrets.php generate-key`
2. Add it to `config.php` as `'secret_key' => '<key>',` (Docker:
   `WLANMON_SECRET_KEY`).
3. Encrypt existing values: user menu → "Data retention" → "Encrypt all
   now", or `php tools/secrets.php encrypt-all`. New values are encrypted
   automatically from then on.
4. **Keep the key apart from the database backups** (e.g. in a password
   manager). Without it the credentials cannot be decrypted and must be
   entered again; until then the probes keep their last configuration.

Dumps, backups and a leaked database then only contain ciphertext. It does not
protect against someone who takes over the whole server (key and database).
Status: `php tools/secrets.php status` or the data retention page.

**Change log:** who changed what and when – device configuration, site
assignment, API keys, sites, users, alerting, data retention – is listed under
user menu → "Change log" (admins) and on each device's configuration page.
Passwords only appear as "changed", never with their value.

## Automatic updates from the Git repo

For the classic installation: `update_dashboard.sh` checks whether the branch
on GitHub has moved on and, if so, fetches it into a separate checkout and
copies it into the web directory with `rsync` (`config.php` is left alone).
No restart is needed – PHP picks up the new files on the next request.

The separate checkout is deliberate: `config.php` stays untouched, and a
failed `git pull` can never leave the live files half updated.

**One-time setup** (the repo is public, no login or key needed):

```bash
git clone https://github.com/dgawin/wlanmon.git ~/wlanmon
chmod +x ~/wlanmon/dashboard/update_dashboard.sh
```

`LIVE_DIR` (script default: `/var/www/wlanmon`) and `SRC_DIR` (default:
`~/wlanmon/dashboard`) can be changed with environment variables if your
paths differ:

```bash
WLANMON_DASHBOARD_LIVE_DIR=/var/www/vhosts/example.com/wlanmon.example.com ~/wlanmon/dashboard/update_dashboard.sh
```

**Switch it on** with a cron job (every 15 minutes; to switch it off, remove
the line):

```cron
*/15 * * * * /root/wlanmon/dashboard/update_dashboard.sh >> /var/log/wlanmon-dashboard-update.log 2>&1
```

**`stable` instead of `main`:** the script pulls from `stable`, so a push to
`main` does not go live by itself. A commit reaches `stable` only when it is
released – after [CI](../.github/workflows/ci.yml) (`php -l` and `bash -n` on
every push) and ideally a quick test:

```bash
git fetch origin
git checkout stable
git merge --ff-only origin/main
git push origin stable
```

If `--ff-only` fails, `stable` has diverged (e.g. a hotfix made directly
there). Sort that out by hand instead of forcing it.

**Security note:** the script deploys whatever is on the branch – only use a
branch that contains released code. Local changes in the checkout make it stop
instead of overwriting them, and a missing directory only aborts. To follow
it:

```bash
tail -f /var/log/wlanmon-dashboard-update.log
```

## Security notes

- Choose the admin API password and the database password long and random
  (e.g. `openssl rand -base64 32`), never the example values.
- `config.php` is safe from the browser only if the vhost really uses
  `public/` as its DocumentRoot. Never commit it.
- Device API keys are valid until rotated – rotate one at once if you suspect
  it leaked.
- All web forms are protected against CSRF. The `/api/v1/*` endpoints do not
  use cookies, so CSRF does not apply to them.
- `/api/v1/measurements` has limits per device: at most 500 measurements per
  request and 3,000 in 5 minutes (HTTP 413 / 429). A normal probe sends far
  less, even while it catches up after an outage. If a probe hits the limit,
  its data stays in the probe's queue and is sent later; nothing is lost.
  The values are in `src/Measurement.php` (`INGEST_*`).

## Version

The version is in the `VERSION` file and shown at `/about`. It is maintained
by hand; the probe uses the same scheme:

- **Release:** three digits, e.g. `1.0.1`.
- **Every commit on `main`** after that raises a fourth digit in the same
  commit: `1.0.1.1`, `1.0.1.2`, ... so you always know exactly what is
  running.
- **Next release:** the fourth digit is dropped and the third goes up
  (`1.0.1.7` → `1.0.2`).

`stable` takes over the number from `main` unchanged. Each probe reports its
own version, which the device list and device page show – outdated probes
stand out at a glance.

## For developers

### Project structure

```
config.example.php   -> copy to config.php (DB credentials, admin API, optional blocks)
schema.sql            -> database schema
create_admin.php      -> one-off: first admin account
update_dashboard.sh   -> automatic update from the Git repo (cron job)
Dockerfile, docker-compose.yml, docker/ -> Docker deployment
check_alerts.php      -> alerting, e-mail/Telegram (cron job)
sync_cirrus_aps.php   -> OmniVista Cirrus sync (cron job)
backup_db.php         -> nightly database backup (cron job)
cleanup_data.php      -> deletes data past its retention (cron job)
lang/en.php           -> English UI texts (German text => English)
tools/i18n_check.php  -> checks lang/en.php for missing or unused entries
tools/secrets.php     -> generate key, show status, encrypt credentials
public/               -> DocumentRoot of the vhost
  index.php           -> front controller (routing + handlers)
  .htaccess           -> URL rewriting to index.php
  static/             -> style.css, theme.js (light/dark), spectrum.js, timeline.js
src/                  -> outside public/, not reachable from the browser
  db.php, auth.php, admin_auth.php, Response.php, Device.php, Measurement.php
  Alerting.php, Settings.php, Session.php, User.php, Site.php, Timeline.php
  I18n.php, Retention.php, Secrets.php, Audit.php, Capture.php, Cirrus.php, CirrusAuth.php
  Profile.php         -> SSIDs and profiles, the configuration that applies to a device
  ProbeError.php      -> turns the probes' error codes into readable text
  templates/          -> page templates (_nav.php is shared by all pages)
```

### Translations

UI texts are written in German inside `__()`, `te()` and `teh()`; the English
version lives in `lang/en.php`. Run `php tools/i18n_check.php` after changes.
When the probe gets a new error code, add it to `src/ProbeError.php`.

### Database changes

New columns are added by the dashboard itself where needed (and by
`docker/init_db.php` on start). Keep `schema.sql` up to date for fresh
installs.

### Testing locally (without Apache)

With PHP's built-in server:

```bash
cd public
php -S 127.0.0.1:8000
```

It ignores `.htaccess`, but routing happens in `index.php` anyway, so paths
like `/api/v1/measurements` or `/devices/<id>` work as they are. For a test
including the rewrite rules, use a real Apache.

## License

MIT – see [LICENSE](../LICENSE).
