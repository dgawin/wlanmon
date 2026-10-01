# WLANMON Probe – Client

Scans Wi-Fi networks and runs periodic connection tests against configured
SSIDs. Results are buffered locally (SQLite) and sent to a central server
over HTTPS.

## Requirements

- Debian-based system, e.g. a minimal Armbian on a NanoPi NEO2/NEO3 or
  Raspberry Pi OS. The probe controls Wi-Fi directly via
  `wpa_supplicant`/`iw` – other network managers must not manage the test
  interface (see [`setup_wlanmon_probe.sh`](setup_wlanmon_probe.sh))
- USB Wi-Fi adapter (e.g. Comfast CF-953AX, driver `mt7921u`, kernel ≥ 5.19)
- Root privileges (for `iw`, `wpa_supplicant`, `dhclient`)
- Packages: `iw`, `wpasupplicant`, `isc-dhcp-client` (or `dhcpcd5`), `iputils-ping`,
  `python3-venv`, optionally `iperf3`. `_run_dhcp()` in `wifi_ops.py` prefers
  `dhclient` and automatically falls back to `dhcpcd` if `dhclient` is not
  on the PATH (`shutil.which()`) – `_cleanup_connection()` mirrors the same
  choice, so the lease is always released by the client that was actually
  used (`dhclient -r` or `dhcpcd -k`).

  **Known DHCP quirks, observed live on several devices and now mitigated:**
  - With an old lease stored in the lease file, `dhclient` tries to reuse it
    via "INIT-REBOOT" (a direct DHCPREQUEST instead of a fresh DISCOVER) –
    after an AP change (roaming to the same SSID, different BSSID) this
    reliably led to a DHCP timeout (dashboard history: a device ran cleanly
    connected and failed right after the next roaming event). `_run_dhcp()`
    therefore uses its own lease file (`-lf`), emptied before every attempt,
    instead of the system lease file, so every connection test really starts
    with a fresh DISCOVER – `-r` during cleanup deliberately points to the
    same file.
  - Without `-L`/`--noipv4ll`, `dhcpcd` falls back to a self-assigned IPv4LL
    address (169.254.x.x, RFC 3927 "Zeroconf") after a few seconds without a
    DHCP answer, and `_run_dhcp()` would have wrongly counted that as a
    success (observed live: `connected=True` with `ip=169.254.x.x`; the
    following iperf3 test promptly timed out, because 169.254.x.x cannot
    route into the actual network) – in addition to `-L`, `_run_dhcp()` also
    checks explicitly against the prefix, in case an IPv4LL address comes
    about some other way.
  - **Raspberry Pi 3 and 5 (Raspberry Pi OS / Debian Trixie): uninstall
    `dhclient`.** With `isc-dhcp-client` (`dhclient 4.4.3`), connection tests
    there consistently failed with `DHCPDECLINE` right after the `DHCPACK`
    (the freshly assigned IP is rejected immediately), sometimes also with
    "Network is down" during the DHCP phase – even on a quiet network with a
    strong signal, with the internal Wi-Fi chip just as with USB adapters.
    Without `dhclient`, the probe automatically falls back to `dhcpcd` (see
    above) and runs cleanly:

    ```bash
    sudo apt remove -y isc-dhcp-client
    sudo systemctl restart wlanmon-probe
    ```

    On the NanoPi devices (Armbian), on the other hand, `dhclient` works
    flawlessly. The actual cause of the `DHCPDECLINE` on the Raspberry Pi
    images is unknown (all that is known: `dhclient` declines, `dhcpcd` on
    the same network does not) – so on a new Raspberry Pi remove it right
    after installation instead of waiting for the failures. Do not confuse
    this with a different, unrelated symptom: "Failed to allocate an IPv4
    address" in pfSense's Kea log means the DHCP pool is too small (enlarge
    the address range there), not this `dhclient` effect.
- Python ≥ 3.10

## Installation

Automated (recommended, see [`setup_wlanmon_probe.sh`](setup_wlanmon_probe.sh)
for details and prerequisites):

```bash
sudo ./setup_wlanmon_probe.sh
```

The script installs everything and then starts a **setup wizard**
([`config_wizard.py`](config_wizard.py)). Have the device ID and its API key
from the dashboard at hand (Devices -> add device). The wizard asks for:

- device ID, dashboard URL and API key – and **tests the connection** right
  away (a wrong key or an untrusted certificate is reported immediately)
- how to verify the server certificate (public, internal CA file, or not at
  all – see [Server connection & TLS](#server-connection--tls))
- the Wi-Fi adapter for the tests (lists all adapters and warns if one of
  them carries the network uplink), country code
- central configuration from the dashboard, auto-update channel, USB
  watchdog, OLED display

It only changes these values in `/etc/wlanmon-probe/config.yaml`; comments and
all other settings stay as they are, and a backup is kept. Run it again any
time with `sudo wlanmon setup` – current values are offered as defaults.
`--no-wizard` skips it for unattended installs; on the very first run, which
switches to classic interface names (`net.ifnames=0`), the wizard follows
after the required reboot.

Manually:

```bash
sudo mkdir -p /opt/wlanmon-probe /etc/wlanmon-probe /var/lib/wlanmon-probe /var/log/wlanmon-probe
sudo cp main.py wifi_ops.py sender.py queue_store.py config_manager.py \
        probe_status.py display.py bound_http.py requirements.txt /opt/wlanmon-probe/
sudo mkdir -p /opt/wlanmon-probe/portals
sudo cp portals/*.py /opt/wlanmon-probe/portals/

# A dedicated venv instead of a system-wide pip install - recent
# Debian/Ubuntu versions (PEP 668, "externally-managed-environment")
# refuse the installation otherwise.
sudo python3 -m venv /opt/wlanmon-probe/venv
sudo /opt/wlanmon-probe/venv/bin/pip install -r /opt/wlanmon-probe/requirements.txt

sudo cp config.example.yaml /etc/wlanmon-probe/config.yaml
sudo nano /etc/wlanmon-probe/config.yaml   # adjust interface, server URL, API key, target SSIDs

sudo cp wlanmon-probe.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wlanmon-probe
sudo journalctl -u wlanmon-probe -f
```

## Server connection & TLS

`server.url` and `server.verify_tls` in `config.yaml` control how the probe
talks to the dashboard. Besides the measurements, this connection carries the
API key and – with `remote_config` – the central configuration including
**Wi-Fi passwords (PSKs), 802.1X and portal credentials**. Choose the
weakest option only if a stronger one is not possible:

| Setting | When |
|---|---|
| `verify_tls: true` (default) | the server has a certificate from a public CA (e.g. Let's Encrypt) |
| `verify_tls: "/etc/wlanmon-probe/ca.crt"` | **recommended for internal servers:** trust exactly this CA (e.g. the internal CA of the dashboard's Docker setup, see the dashboard README) |
| `verify_tls: false` | self-signed certificate without a CA file: encrypted, but the server is not verified – anyone who can intercept the traffic can read the credentials |
| `url: "http://..."` | no encryption at all – only in an isolated test network |

With `verify_tls: false` or an `http://` URL, the probe logs one warning on
startup.

## Toolbox (`sudo wlanmon`)

A menu for the commands you keep needing on a probe:

- **Status:** version (installed/checkout), branch, services, last update run,
  Wi-Fi interface/MAC/country, IP addresses, measurements not yet sent
- **Logs:** probe log live or the last lines, warnings/errors only,
  connection test results, updater and watchdog log
- **Services:** restart/stop/start the probe, status of all services and timers
- **Update:** run now, last result, compare version and Git state, repair
  with the `update_probe.py` from the checkout (in case the installed copy is
  broken)
- **Wi-Fi diagnostics:** adapter, country setting, bands/channels, scan as a
  table (all networks, 2.4 GHz active/passive), search for an SSID, channel
  occupancy, `diagnose_wifi.sh`
- **Configuration:** setup wizard, show config.yaml (keys masked) and edit it
  (with YAML validation and restart), central configuration from the dashboard
  (passwords masked)
- **Network/server:** addresses/routes, server reachable, queue, time/NTP
- **System:** memory/temperature, reboot, shutdown

Directly, without the menu: `sudo wlanmon status`, `log` (live), `update-log`,
`restart`, `update`, `setup` (wizard), `help`.

The tool lives in the Git checkout (`auto_update.repo_dir`) and is kept up to
date by the auto-update with every `git pull`. `setup_wlanmon_probe.sh` sets up
the `wlanmon` command; on devices that are already installed, a first call from
the checkout is enough, and it creates the command itself:

```bash
cd <repo_dir>          # e.g. ~/wlanmon/probe, see auto_update.repo_dir
sudo ./wlanmon-tool.sh
```

## Identifying the interface

`setup_wlanmon_probe.sh` does both automatically: it sets `net.ifnames=0` in
`/boot/armbianEnv.txt` (classic names like `wlan0` instead of the MAC-based
form `wlx<mac>`, takes effect only after a reboot) and prints the detected
Wi-Fi interfaces at the end of the run. Manually:

```bash
iw dev
# or
ip link show | grep wl
```

Enter the name you found (e.g. `wlan1`) in `config.yaml` under `interface.name`.

**Country setting:** `interface.country` (default `DE` if the entry is
missing) sets the regulatory domain – allowed channels and transmit power.
Without a country, `iw reg get` reports "country 00": the adapter may then only
scan passively on 5 GHz and cannot connect on channels 12/13. The probe sets
the country on startup and checks it before every scan (`iw reg set`).
Outside Germany, enter your own country code (e.g. `"AT"`, `"CH"`, `"US"`, in
quotes); `""` leaves the system setting unchanged.

## USB hotplug limitation & watchdog

**Reproduced on real hardware (NanoPi NEO2 + mt7921u USB adapter):**
if the USB Wi-Fi adapter is unplugged and plugged back in while running, it
sometimes ends up on the wrong USB companion controller when replugged
(OHCI/full speed instead of EHCI/high speed – `dmesg`: `usb X-Y: not running
at top speed`). The mt7921u firmware upload needs the full high-speed
bandwidth; if it fails, the driver crashes (`Failed to get patch semaphore`,
`hardware init failed`) and the chip stays **dead even after replugging** – an
unbind/bind via `/sys/bus/usb/drivers/usb/` demonstrably does not bring it back
in this state, only a full reboot does. This is a limitation of the Allwinner
H5 USB controllers during hotplug (EHCI/OHCI companion negotiation), not a
software bug.

**Practical consequence:** the adapter should already be plugged in at boot;
avoid hotplugging during operation if possible.

**In case it happens anyway** (e.g. a connector accidentally wiggled in the
field), there is `wlan_watchdog.sh` + `wlanmon-wifi-watchdog.timer`: every
2 minutes it checks whether `interface.name` still exists as a network
interface and automatically reboots the device if it has been missing
continuously for longer than `watchdog.missing_threshold_minutes`. It runs as
its own systemd timer, independent of the `wlanmon-probe` service itself – the
watchdog also works if the main process hangs. Disabled by default, enable it
in `config.yaml`:

```yaml
watchdog:
  enabled: true
  missing_threshold_minutes: 10
```

`setup_wlanmon_probe.sh` always installs and enables the timer
(`systemctl enable --now wlanmon-wifi-watchdog.timer`) – but the watchdog
itself only acts once `watchdog.enabled: true` is set; until then each check
run simply does nothing.

## Automatic updates from the Git repo

Optional, off by default: every 15 minutes (`wlanmon-probe-update.timer`),
`update_probe.py` checks whether a newer version is available on
`origin/<branch>` in the local Git checkout (`git fetch` + `git rev-parse`).
If so: `git pull --ff-only`, copy the known files (the same list as in
`setup_wlanmon_probe.sh`) to `/opt/wlanmon-probe/`, `pip install -r
requirements.txt` (in case dependencies changed), `systemctl restart
wlanmon-probe`. Runs as its own systemd timer, independent of the
`wlanmon-probe` service itself.

```yaml
auto_update:
  enabled: true
  repo_dir: "/home/pi/wlanmon/probe"   # local Git checkout
  branch: "stable"
```

`setup_wlanmon_probe.sh` also always installs and enables this timer
(`systemctl enable --now wlanmon-probe-update.timer`) and automatically sets
`repo_dir` to the directory the script itself was called from – it still only
becomes active with `auto_update.enabled: true`.

`update_probe.py` is part of its own `FILES` list, so it copies itself to
`/opt/wlanmon-probe/` on every run – exactly that copy is what the systemd
timer runs (`ExecStart` in `wlanmon-probe-update.service`), not the one in the
Git checkout under `repo_dir`. **On devices that were already running before
this entry existed, precisely this one self-update mechanism is frozen by
definition** (the old copy does not know the new `FILES` list yet) – update it
manually once there:

```bash
sudo cp /home/pi/wlanmon/probe/update_probe.py /opt/wlanmon-probe/update_probe.py
```

(Adjust the path to `repo_dir`.) From then on, the self-update works like for
any other file in `FILES`.

**`stable` instead of `main`:** devices in the field deliberately do not pull
directly from `main` (every commit lands there immediately) but from the
`stable` branch. A commit only goes live once it is deliberately merged there –
after [CI](../.github/workflows/ci.yml) (`php -l`/`py_compile`/`bash -n` on every
push, see the badge/checks on GitHub) and optionally a manual test on a single
device. Releasing (fast-forward, no merge commit):

```bash
git fetch origin
git checkout stable
git merge --ff-only origin/main
git push origin stable
```

If the `--ff-only` merge fails, `stable` has diverged (e.g. a hotfix made
directly on `stable`) – sort that out manually instead of forcing it. Devices
that still have `branch: "main"` configured keep pulling from `main`; switch
them to `stable` by changing `auto_update.branch` in
`/etc/wlanmon-probe/config.yaml` once (takes effect from the next timer tick,
no code change or manual restart needed).

The checkout under `repo_dir` usually belongs to the user who created it with
`git clone` (e.g. `/home/pi/wlanmon/probe`), but `update_probe.py` runs as
`root`. Since CVE-2022-24765, `git` refuses any access in that case by default
("detected dubious ownership in repository at …") – so on every run
`update_probe.py` automatically (and idempotently) adds the checkout to
`root`'s global `safe.directory` list; no manual step needed.

`repo_dir` may also be a subdirectory of a checkout (e.g. `probe/` in the
combined wlanmon repo): if `.git` is not in `repo_dir` itself but in a parent
directory, that is accepted as long as `repo_dir` contains the probe
(`update_probe.py`).

**Security note:** this automatically runs whatever is on `branch` – only
enable it if that branch really contains released code only (no test/feature
branch) and if access to the GitHub account is secured accordingly.
`git pull --ff-only` refuses to run on local changes in the checkout instead of
overwriting anything; a missing/invalid `repo_dir` also just aborts without
side effects (log line: `[update_probe] repo_dir '…' ist kein Git-Checkout …`).
Follow the log:

```bash
journalctl -u wlanmon-probe-update -f
```

## How it works

- **Scan loop** (`scan.interval_seconds`): runs `iw dev <iface> scan` and
  parses SSID, BSSID, signal strength, frequency/channel and – if the AP sends
  a BSS Load element (QBSS) – the number of associated clients
  (`station_count`) and the channel utilization measured by the AP
  (`channel_utilization_pct`, converted from `x/255`). Since 1.0.1.9 also the
  capabilities from the information elements: `wifi_generation` (4–7 from
  HT/VHT/HE/EHT capabilities, `null` = a/b/g only), `channel_width_mhz`
  (VHT/HT operation; VHT on 2.4 GHz counts as Wi-Fi 4), since 1.0.1.11
  `center_freq_mhz` (center of the occupied channel block for the spectrum
  view), `security` (e.g. `WPA2/WPA3-Personal`, `WPA3-Enterprise`, `OWE`,
  `WEP`, `Open`) with `pmf` (`required`/`optional`) and the raw `akm` suites,
  as well as roaming support `rrm_11k`, `neighbor_report`, `btm_11v` (BSS
  Transition) and `ft_11r` (FT AKM in the RSN). Right afterwards,
  `iw dev <iface> survey dump` reads the channel occupancy as seen by the
  probe's own radio (`channels`: measurement time, busy time, `busy_pct`,
  noise per channel) – this also works with APs without BSS Load. Note: mt76
  drivers (e.g. mt7921u) reset these counters on every channel change, so
  during a scan this is only a short snapshot (dwell time per channel, see
  `active_ms`); other drivers accumulate since the interface came up. If the
  driver does not support `survey dump`, `channels` stays empty.
  Since 1.0.1.16 the probe also measures the occupancy of the **connected**
  channel during the connection test (`channel_load`): counter readings after
  DHCP, after the ping and after iperf3; `baseline` = the period from DHCP to
  the end of the ping (hardly any own traffic, corresponds to the everyday
  load), `iperf3` = during the throughput measurement, each with `busy_pct`,
  own transmission `tx_pct`, reception `rx_pct` and the remainder `other_pct`
  (neighbors/interference). If the driver does not count (0 ms), the channel
  changes or the counters are reset, the value is missing.
- **Connection test loop** (`connection_tests.interval_seconds`): connects to
  each configured target SSID in turn (`wpa_supplicant` + `dhclient`),
  measures association time, DHCP time, ping RTT/loss and optionally iperf3
  throughput, then disconnects cleanly. `assoc_seconds` runs from the start of
  `wpa_supplicant` to the 802.11 association and includes its scan for the
  target network; since 1.0.1.34 `scan_seconds` reports that scan part
  separately (until `wpa_state` leaves `SCANNING`, i.e. an AP was found and the
  login starts), so the pure association is `assoc_seconds - scan_seconds`. Right before the DHCP attempt,
  promiscuous mode is explicitly switched off on the interface
  (`ip link set <iface> promisc off`) – some drivers (observed: `brcmfmac` on
  the onboard Wi-Fi of a Raspberry Pi 5) occasionally leave the interface in
  that mode after the preceding scan, so during the DHCP phase it receives
  every unicast frame in the air instead of only its own – on a busy network
  this can make dhclient's duplicate address detection wrongly suspect an IP
  conflict (symptom: DHCPDECLINE right after DHCPACK, sometimes also "Network
  is down" under the additional packet load). Purely defensive, it does not
  interfere if the mode is already off. Right after that, Wi-Fi power
  management is switched off as well (`iw dev <iface> set power_save off`) – a
  known problem with Broadcom/Cypress chips (also observed on the Pi 5 onboard
  Wi-Fi): periodic sleep/wake cycles in power save (visible in `dmesg` as a
  recurring `brcmf_cfg80211_set_power_mgmt: power save enabled`, even outside
  active tests) can cause packet loss or a brief disconnect in the middle of a
  DHCP request. Also purely defensive.
  iperf3 is configured entirely per target SSID: `iperf3_enabled: false`
  disables it for that SSID, `iperf3_download` (on by default) controls the
  additional download measurement, and `iperf3_server`/
  `iperf3_duration_seconds`/`iperf3_port` per target override the defaults
  from `connection_tests` where needed – necessary when an SSID leads into a
  different VLAN with its own iperf3 server/port. Empty or 0 per target = use
  the default from `connection_tests`.
  Since 1.0.1.13, `iperf3_bitrate_mbps` per SSID (iperf3 `-b`, 0 = unlimited):
  an unlimited test saturates the channel for its duration – exactly the
  effect that skews a speed test in everyday use –, a limited one checks
  whether a defined rate is reached stably. Start and end of the iperf3 load
  are reported as `iperf3_started_at`/`iperf3_ended_at`; the dashboard marks
  these periods in the "Timeline" tab. Since 1.0.1.14,
  `connection_tests.iperf3_min_interval_minutes` (0 = on every test) limits
  iperf3 to at most one run per SSID within that interval; the other tests run
  without iperf3 (`iperf3_deferred: true`). A test triggered via button 3
  always measures. Since 1.0.1.17 the timestamps are kept in
  `iperf3_last_run.json` next to the queue (`/var/lib/wlanmon-probe/`), so a
  restart (update, new remote config) does not bypass the minimum interval.
  An iperf3 server only serves one test at a time; if it reports
  "the server is busy running a test" (e.g. because a second probe is
  measuring right now), the probe retries up to three times, waiting test
  duration + 5 s in between.
  **Recommended iperf3 server options:** `iperf3 -s --idle-timeout=30 --rcv-timeout=10000`
  (open TCP and UDP 5201). The `=` notation is intentional: some NAS Docker UIs
  pass `--idle-timeout 30` as *one* argument including the space, iperf3 then
  aborts with "unrecognized option" and the container keeps restarting in a
  loop. If a client drops out in the middle of a test, a server without these
  options occasionally gets stuck on "busy" permanently and rejects every
  further test – observed live: all LAN tests of a site failed for hours until
  the server was restarted.
  As a Docker Compose service:

  ```yaml
  services:
    iperf3:
      image: networkstatic/iperf3:latest
      container_name: iperf3-server
      restart: unless-stopped
      ports:
        - "5201:5201/tcp"
        - "5201:5201/udp"
      command: ["-s", "--idle-timeout=30", "--rcv-timeout=10000"]
  ```
  The ping target also applies per target SSID (`ping_target`): empty = the
  default gateway of the network obtained via DHCP (useful with separate/
  isolated VLANs), `connection_tests.ping_target` is only the last fallback if
  no gateway can be determined. The target actually pinged is included in the
  test result.
- **Captive portal check** (optional, per target SSID with
  `captive_portal_check: true`, a checkbox per SSID in the dashboard; the
  detection URL is global in `connection_tests.captive_portal_url`, default
  `http://connectivitycheck.gstatic.com/generate_204`):
  after DHCP, the probe requests the detection URL over plain HTTP, bound to
  the Wi-Fi interface (`SO_BINDTODEVICE`, like `ping -I`), without following
  redirects. HTTP 204 = no portal; 3xx, 200 or 511 = portal (the redirect
  target is included); DNS/connection errors and other status codes = "not
  checkable". The result is in the `captive_portal` field of the connection
  test; a detected portal does not make the test fail.
  Limits: portals often remember the MAC after the first login (then "no
  portal"); `random_mac: true` per target SSID helps against that (see below).
  As long as a portal blocks traffic, iperf3 is skipped
  (`skipped: "captive_portal"`); only the default gateway is pinged then
  (`ping_target_source: "portal_gateway"`), which is almost always reachable
  behind the portal too – the SSID's own `ping_target` or the global fallback
  would usually lie behind the portal and only report "all lost". If no
  gateway can be determined, the ping is skipped. Some portals (e.g. Cisco
  Meraki, `eu.network-auth.com`) also drop ICMP to the gateway before login –
  the dashboard then shows this neutrally ("portal blocks ICMP") and does not
  count it as ping loss. As a latency that also gets through such a portal,
  the portal check measures, since 1.0.1.8, `tcp_connect_ms` (TCP handshake)
  and `response_ms` (request until response headers) of the detection URL over
  Wi-Fi – without DNS, which goes through the system resolver.
- **Portal login** (optional, per target SSID with `captive_portal_login`
  `{enabled, type, username, password, logoff}`): modular, see "Portal
  modules" below. After the login, it counts as a success when the detection
  URL returns HTTP 204 (up to 4 checks, 1.5 s apart); then ping/iperf3 run as
  usual. At the end of the test, the probe logs the session off again
  (`logoff`, on by default) so guest sessions do not pile up. The result is in
  `captive_portal.login` (`ok`, `portal_type`, `login_by`, `http_status`,
  `error`) and `captive_portal.logoff`; the password is never logged or sent,
  but like the PSKs it is stored in plain text in the configuration.
- **Random MAC** (optional, per target SSID with `random_mac: true`): before
  the test, the probe sets a random, locally administered MAC on the Wi-Fi
  interface (`ip link set address`, the interface goes down briefly for this)
  and restores the hardware MAC (`/sys/class/net/<if>/phy80211/macaddress`)
  afterwards. This way a captive portal shows up anew on every test. The MAC
  used for the test is included in the result (`mac_address`, `mac_random`).
  Do not use it on MAC-filtered networks or with DHCP reservations. If the
  driver does not allow the change, the test continues with the existing MAC
  (warning in the log).
- **Error codes** (since 1.0.1.40): a failed test carries `error` (German text
  for the probe's own log) and `error_codes`, a list of building blocks such as
  `[{"code": "assoc_failed", "timeout": false}, {"code": "sae_password_wrong",
  "status": 1}]` (see `_err()` in `wifi_ops.py`). The dashboard formulates the
  message from the codes in the UI or alert language. When adding a new code,
  also add it to the dashboard's `src/ProbeError.php`; until then the dashboard
  falls back to the German text.
- **Queue** (`queue_store.py`): every measurement first goes into a local
  SQLite file. A sender thread transfers unacknowledged entries in batches over
  HTTPS to `<server.url>/measurements`. On errors the entry stays in the queue
  and is retried with exponential backoff.

## Capturing failed tests

Since 1.0.1.35 the probe records every connection test and keeps the recording
only if the test fails (`connection_tests.capture_on_failure`, on by default,
switchable per device in the dashboard):

- **pcap** of the test interface via `tcpdump` (installed by
  `setup_wlanmon_probe.sh`): EAPOL (802.1X and 4-way handshake), DHCP, ARP, DNS
  and ICMP – no iperf3 traffic. The adapter runs in client ("managed") mode, so
  the 802.11 management frames of the association itself (authentication,
  association request/response, deauth) are **not** in the pcap; that would
  need a second adapter in monitor mode.
- **Adapter events** from `iw event -t`: authenticate/associate/connect/deauth/
  disconnect with status and reason codes – the part tcpdump cannot see.
- **wpa_supplicant log** (since 1.0.1.38): EAP method, server certificate and
  the failure reason from the client's point of view. Normal verbosity, so no
  passwords or PSKs.

While capturing, `wpa_supplicant` runs with `-p control_port=0` (since
1.0.1.37): otherwise it sends EAPOL frames through the nl80211 control port,
bypassing the interface, and tcpdump sees none of them (verified on a NanoPi
with mt7921u). For the AP nothing changes.

A failed test carries a `capture_id`; the files wait in
`/var/lib/wlanmon-probe/captures/` (at most 1.5 MB pcap per test, 20 MB in
total, oldest dropped first) until the sender has uploaded them to
`POST <server.url>/devices/<device_id>/captures/<capture_id>`. In the dashboard
they appear as download links in the error column of the test table (admin and
user only; retention 30 days by default). Captures contain MAC addresses and
possibly 802.1X identities – keep that in mind before passing them on.

Without `tcpdump` only the events are recorded (one warning in the log); on
existing probes install it with `sudo apt install tcpdump`.

## Portal modules

The captive portal login lives in the `portals/` package. Each module knows
exactly one portal type (`portals/base.py`: `detect()`, `login()`, optionally
`logoff()`). `captive_portal_login.type` selects the module (`auto` = detect
via the redirect URL). Available:

| type | Portal | Login methods |
|------|--------|---------------|
| `cirrus` | Alcatel-Lucent OmniVista Cirrus (guest/BYOD) | username/password, access code, terms of use only (no credentials) |
| `form` | Classic form-based portals, e.g. pfSense (`index.php?zone=…&redirurl=…`) | username/password, voucher/code, just clicking "Accept" |

`form` loads the portal page (with cookies and redirects), reads the HTML form
and fills it in: hidden fields unchanged, the password field or a field with
`voucher`/`code`/`token` in its name = password, a field with
`user`/`login`/`name` = username, a checkbox with `accept`/`terms`/… gets
ticked, plus the submit button. Without a user/password field, no credentials
are needed. If the portal target is an IP address (gateway), the TLS
certificate is not verified. There is no logoff. With `auto`, `form` is the
last resort when no specific module matches. Portals that generate their form
with JavaScript need a module of their own.

New portal: create a file under `portals/`, subclass `PortalModule` and
register it in `portals/__init__.py` (`MODULES`); add it to the dashboard
drop-down (portal type). Modules do not raise exceptions but report errors in
the result.

## Display & buttons (optional, NanoHat OLED)

Built into wlanmon-probe (`display.py`), no separate display daemon.
Hardware verified on a running device:

- **Display**: SSD1306-compatible OLED, I2C address `0x3c`, bus `i2c-0`
  (no framebuffer device present – it is driven directly over I2C from user
  space, for which the device tree overlay `i2c0` is enough).
- **3 buttons**: sysfs GPIO `0`, `2`, `3`, rising edge.

**Important:** no other process may use the display at the same time (e.g. an
OLED daemon shipped with the OS image), otherwise both compete for the same I2C
bus and the same GPIOs.

Enable it in `config.yaml`:

```yaml
display:
  enabled: true
  i2c_port: 0
  i2c_address: 0x3c
  width: 128
  height: 64   # switch to 32 if the lower half is distorted
  button_gpios: [0, 2, 3]
  sleep_after_seconds: 120   # display sleep, 0 = always on
```

If the libraries are missing (`luma.oled`/`luma.core`, included in
`requirements.txt`) or the hardware initialization fails, `DisplayLoop` logs a
warning and stays inactive – the rest of wlanmon-probe keeps running
unchanged.

**Operation:**
- Button 1 / button 2: navigate between the screens (status → last scan →
  last connection test → back to status)
- Button 3: triggers a connection test run immediately instead of waiting for
  the next `connection_tests.interval_seconds` interval – handy for on-site
  diagnostics right at the device.
- **Display sleep:** after `sleep_after_seconds` (default 120) without a button
  press, the panel switches off (OLEDs burn in with a permanently static
  image). Any button wakes it up again; this first press neither changes the
  screen nor triggers a test. The content keeps being updated during sleep and
  is current after waking up. `0` = always on. The option lives in the local
  `display` block of `config.yaml` (not in the dashboard configuration) and
  takes effect after a restart of the probe.

The screens show device ID/site/uptime/time since the last successful server
transfer, the last scan result (time + number of networks) and the last
connection test (SSID, status, ping RTT/loss, iperf3 throughput if configured,
error text on failure). Updates are event-driven – a redraw only happens on a
button press or when the underlying status actually changes, not on a fixed
schedule.

Not yet tested against real hardware (built only from the I2C address/GPIO
numbers verified above and the standard API of `luma.oled`) – on first use,
check the log (`journalctl -u wlanmon-probe`) for hardware init errors.

## Central configuration from the server (optional)

With `remote_config.enabled: true` in `config.yaml`, the client periodically
fetches the `scan` and `connection_tests` blocks (intervals, target SSIDs, ping
target, iperf3 server...) from the server instead of only reading them locally:

- On startup: `GET <server.url>/devices/<device_id>/config` with
  `Authorization: Bearer <api_key>`. The response is expected as JSON with the
  keys `scan` and `connection_tests` (same structure as in
  `config.example.yaml`).
- Success -> the values are cached locally under `remote_config.cache_path`
  and used.
- Failure on startup -> the last local cache is used if available; otherwise
  the local values from `config.yaml`.
- 404 from the server -> the server has no specific configuration for this
  device (yet), local/cached values stay active.
- During operation, a `ConfigWatcher` thread checks every
  `remote_config.poll_interval_seconds` whether the server configuration has
  changed (hash comparison). On a change, the cache is updated and the process
  exits cleanly (exit code 75) – systemd (`Restart=always`) then starts it
  fresh with the new configuration. This is more robust than a live reload of
  individual threads and makes sure the scan/test loops never run with
  inconsistent half-old values.

Bootstrap values (`device.id`, `device.site`, `interface.name`, `server.*`,
`queue.*`, `logging.*`) always stay local in `config.yaml` and are never
overwritten by the server – otherwise a device could cut itself off from the
server.

## Expected server API schema (POST /measurements)

```json
{
  "device_id": "wlanmon-probe-01",
  "probe_version": "1.0.0",
  "auto_update": {"enabled": true, "branch": "stable",
                  "repo_dir": "/home/pi/wlanmon/probe",
                  "repo_url": "https://github.com/dgawin/wlanmon.git"},
  "measurements": [
    {
      "id": 123,
      "kind": "scan",
      "data": {
        "timestamp": "2026-09-15T10:00:00+00:00",
        "interface": "wlan1",
        "networks": [
          {"ssid": "Corp-Network", "bssid": "aa:bb:cc:dd:ee:ff",
           "signal_dbm": -47.0, "frequency_mhz": 5180, "channel": 36,
           "station_count": 7, "channel_utilization_pct": 25.1}
        ],
        "channels": [
          {"frequency_mhz": 5180, "channel": 36, "in_use": false,
           "noise_dbm": -95, "active_ms": 110, "busy_ms": 12,
           "receive_ms": 9, "transmit_ms": 0, "busy_pct": 10.9}
        ]
      }
    },
    {
      "id": 124,
      "kind": "connection_test",
      "data": {
        "timestamp": "2026-09-15T10:05:00+00:00",
        "interface": "wlan1",
        "ssid": "Corp-Network",
        "security": "wpa2-psk",
        "connected": true,
        "assoc_seconds": 1.2,
        "scan_seconds": 0.9,
        "dhcp_seconds": 0.8,
        "ip_address": "10.0.5.42",
        "ping_sent": 5,
        "ping_received": 5,
        "ping_rtt_avg_ms": 12.3,
        "iperf3_mbps": 340.5,
        "error": null
      }
    }
  ]
}
```

Expected response: HTTP 2xx when the whole batch has been accepted.
Anything else (4xx/5xx, timeout) leaves the batch unchanged in the queue, and
it is sent again later.

The dashboard shows `auto_update` on the device page. The probe reads on/off
and branch fresh from `config.yaml` with every batch, using the same rules as
`update_probe.py` (a changed `auto_update.branch` takes effect without a
restart). Repo URL, commit and result of the last update run
(`current`/`updated`/`disabled`/`error` including the error text, e.g. a
`Permission denied (publickey)` during `git fetch`) are written by
`update_probe.py` on every run to `/var/lib/wlanmon-probe/update_state.json` –
the probe itself runs with `ProtectHome=true` and cannot see the checkout under
`/home`. Credentials in an HTTP(S) URL (`https://user:token@...`) are removed
beforehand. The file only appears after the first run of an `update_probe.py`
from 1.0.1.3 on; until then this information is missing.

Since 1.0.1.12, `git fetch`/`git pull` give up quickly on a hanging connection
(SSH: `ConnectTimeout=20`, `ServerAliveInterval=10`/`CountMax=3`; HTTPS: abort
below 1 KB/s for 30 s). The SSH timeouts are appended to the command git would
use anyway (`GIT_SSH_COMMAND`, otherwise `core.sshCommand`, otherwise `ssh`) –
since 1.0.1.15; 1.0.1.12–1.0.1.14 overrode an SSH command set up via
`core.sshCommand` ("Host key verification failed") – and a failed `fetch` is
retried once after 45 s. The reason: a `fetch` hung for 120 s when it ran at the
same time as a connection test – `wlan0` in the same subnet as `eth0` then
briefly gets a default route of its own. `fail_count` counts consecutive
failures; the dashboard shows a single one only as a grey hint, red only from
two in a row.

`probe_version` comes from the `VERSION` file next to `main.py` (copied
automatically on updates, see `FILES` in `update_probe.py`) – `"unbekannt"` if the
file is missing. Version scheme as in the dashboard (see its README, section
"Version"): every commit on `main` increments the fourth digit (`1.0.1.1`,
`1.0.1.2`, ...), with the next release it is dropped again (`1.0.2`). Done by
hand in the same commit, no automatic bump.

`device.site` still exists in `config.yaml`, but since the site rework in the
dashboard (assignment happens there via `devices.site_id`, managed by admins)
it is no longer sent to the server – it is purely local, for the log line on
startup and the OLED status (`display.py`).

## 802.1X / WPA2-Enterprise

Targets with `security: "wpa2-eap"` need an `eap` block (example in
`config.example.yaml`, usually maintained via the dashboard). Supported:
`peap` (MSCHAPv2), `ttls` (PAP, MSCHAPv2, MSCHAP, CHAP) and `tls` (client
certificate + private key).

- The CA certificate is optional: if one is stored and `verify_server` is not
  `false`, the RADIUS server is verified (optionally also against
  `server_name`); otherwise it is not.
- Certificates/keys are stored as PEM text in the config and are placed in a
  0700 directory during a test, which is deleted afterwards. The local config
  cache (`remote_config_cache.yaml`) is readable by root only.
- After association, the test waits for the completed EAP/4-way handshake
  (`wpa_cli status`) and reports its duration as `auth_seconds`, separately
  from `assoc_seconds`. If it fails, a readable cause is included in the
  result (certificate, password, RADIUS timeout ...).
- Requires `wpa_cli` (package `wpasupplicant`).

## License

MIT – see [LICENSE](../LICENSE).
