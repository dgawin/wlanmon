# WLANMON Probe – Client

The probe is the part of WLANMON that sits on site and behaves like a Wi-Fi
client. It scans the air, connects to your SSIDs one after the other and
measures how long each step takes. Results are buffered on the device
(SQLite), so nothing gets lost while the dashboard is unreachable, and are sent
to the dashboard over HTTPS.

## Requirements

- A Debian-based system, e.g. a minimal Armbian or Raspberry Pi OS.
- A Wi-Fi adapter for the tests: a USB adapter (e.g. Comfast CF-953AX, driver
  `mt7921u`, 2.4/5/6 GHz) or the built-in Wi-Fi of a Raspberry Pi. See
  [Tested hardware](#tested-hardware).
- An Ethernet uplink. The test adapter is disconnected and reconfigured for
  every test, so it cannot carry the connection to the dashboard.
- Python 3.10 or newer.

The probe drives Wi-Fi itself with `wpa_supplicant` and `iw`, so no other
network manager may touch the test interface. The setup script takes care of
that and installs all packages it needs (`iw`, `wpasupplicant`, a DHCP client,
`iperf3`, `tcpdump`, ...) – only `git` has to be there beforehand.

## Tested hardware

These probes run in our own deployment. Other Debian-based boards with a
supported Wi-Fi adapter will most likely work too.

| Board | Operating system | Wi-Fi for the tests | Bands | Notes |
|---|---|---|---|---|
| Raspberry Pi 5 (1 GB) | Raspberry Pi OS (Trixie) | Built-in (Broadcom, `brcmfmac`) | 2.4 / 5 GHz, Wi-Fi 5 | Tested with the Waveshare PoE HAT (G). It cannot tell the Pi that it delivers 5 A, so set `PSU_MAX_CURRENT=5000` with `sudo rpi-eeprom-config --edit` – otherwise the Pi limits its USB ports to 600 mA (matters with a USB Wi-Fi adapter). |
| Raspberry Pi 3 B | Raspberry Pi OS | USB: Comfast CF-951AX (internal antennas, MediaTek MT7921AU, `mt7921u`) | 2.4 / 5 / 6 GHz, Wi-Fi 6 | Runs. USB 2.0 and 100 Mbit/s Ethernet limit the measurable throughput (the LAN test tops out at about 94 Mbit/s). |
| Raspberry Pi 3 B | Raspberry Pi OS | Built-in (Broadcom, `brcmfmac`) | 2.4 GHz only | Works, but cannot test 5 GHz networks – add a USB adapter for 5 and 6 GHz. |
| NanoPi NEO2 (Allwinner H5, 512 MB) | Armbian Trixie (minimal) | USB: Comfast CF-953AX (MediaTek MT7921AU, `mt7921u`) | 2.4 / 5 / 6 GHz, Wi-Fi 6 | Reference platform. WPA2, WPA3 and 802.1X, captive portal login, capture of failed tests. NanoHat OLED with buttons supported. PoE via splitter works (the board cannot detect it). |
| NanoPi NEO3 (Rockchip RK3328) | Armbian | USB: Comfast CF-953AX (`mt7921u`) | 2.4 / 5 / 6 GHz, Wi-Fi 6 | Same as the NEO2, no OLED. Runs noticeably warmer than the NEO2 (around 60 °C idle is normal). |

Good to know:

- **Wi-Fi adapter:** for tests on all bands, a USB adapter with the MediaTek
  MT7921AU chip (driver `mt7921u`, in the kernel since 5.19) is the safe
  choice. Which 6 GHz channels you can use depends on the country set during
  setup.
- **Raspberry Pi OS** manages Wi-Fi with NetworkManager. The setup script
  takes the test interface out of its hands; the uplink (usually `eth0`) stays
  with it.
- **Raspberry Pi and DHCP:** remove `isc-dhcp-client` there, see
  [Troubleshooting](#troubleshooting).
- **Power:** the dashboard shows voltage, power supply limits and throttling
  of a Raspberry Pi (device page, tab "System"), so a weak power supply shows
  up early.

## Installation

Only `git` is needed beforehand; the setup script
([`setup_wlanmon_probe.sh`](setup_wlanmon_probe.sh)) installs everything else:

```bash
sudo apt update && sudo apt install -y git
git clone https://github.com/dgawin/wlanmon.git ~/wlanmon && cd ~/wlanmon/probe
sudo ./setup_wlanmon_probe.sh
```

On Armbian, the first run switches to classic interface names (`wlan0`
instead of `wlx<mac>`). That needs a reboot, after which you start the wizard
yourself:

```bash
sudo reboot
sudo wlanmon setup
```

On Raspberry Pi OS, and on any later run, the wizard starts right away.

### Setup wizard

Have the device ID and its API key from the dashboard at hand (Devices → add
device). The wizard ([`config_wizard.py`](config_wizard.py)) asks for:

- device ID, dashboard URL and API key – and **tests the connection** right
  away, so a wrong key or an untrusted certificate shows up immediately
- how to check the server certificate (see
  [Server connection & TLS](#server-connection--tls))
- the Wi-Fi adapter for the tests (it warns you if that adapter carries the
  uplink) and the country code
- whether the test configuration comes from the dashboard, automatic updates
  (channel and Git checkout), the USB watchdog (only asked for USB adapters)
  and the OLED display

At the end you get a numbered summary. If something is wrong, answer `n` and
pick the number of the value to change – everything else, including the API
key, stays as entered.

The wizard only changes these values in `/etc/wlanmon-probe/config.yaml`.
Comments and all other settings stay as they are, and the previous file is
kept as a backup (the last five). Run it again any time with
`sudo wlanmon setup`; your current values are offered as defaults. For
unattended installs, `--no-wizard` skips it.

### OLED display

The NanoHat OLED needs the I2C bus. On Armbian the setup script enables the
`i2c0` overlay if the board has one (e.g. NanoPi NEO2); it takes effect with
the same reboot. If `/dev/i2c-0` is still missing, the wizard tells you. To
enable it by hand:

```bash
grep -q '^overlays=' /boot/armbianEnv.txt && sudo sed -i '/^overlays=/{/i2c0/!s/$/ i2c0/}' /boot/armbianEnv.txt || echo 'overlays=i2c0' | sudo tee -a /boot/armbianEnv.txt
```

### Interface name and country

The setup script lists the Wi-Fi interfaces it finds, and the wizard lets you
pick one. To look yourself: `iw dev`. The name goes into `interface.name`.

`interface.country` (default `DE`) sets the regulatory domain, i.e. which
channels and transmit power are allowed. Without it, the adapter reports
"country 00", may only scan passively on 5 GHz and cannot connect on channels
12 and 13. The probe sets the country on startup and checks it before every
scan. Outside Germany, enter your own code in quotes (e.g. `"AT"`, `"CH"`,
`"US"`); `""` leaves the system setting alone.

### Manual installation

If you would rather not use the script:

```bash
sudo mkdir -p /opt/wlanmon-probe/portals /etc/wlanmon-probe /var/lib/wlanmon-probe /var/log/wlanmon-probe
sudo cp *.py requirements.txt VERSION wlan_watchdog.sh /opt/wlanmon-probe/
sudo cp portals/*.py /opt/wlanmon-probe/portals/

# Own venv: recent Debian versions refuse a system-wide pip install (PEP 668).
sudo python3 -m venv /opt/wlanmon-probe/venv
sudo /opt/wlanmon-probe/venv/bin/pip install -r /opt/wlanmon-probe/requirements.txt

sudo cp config.example.yaml /etc/wlanmon-probe/config.yaml
sudo /opt/wlanmon-probe/venv/bin/python3 config_wizard.py   # or edit config.yaml by hand

sudo cp wlanmon-probe.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wlanmon-probe
sudo journalctl -u wlanmon-probe -f
```

## Server connection & TLS

`server.url` and `server.verify_tls` decide how the probe talks to the
dashboard. This connection carries more than measurements: the API key and,
with central configuration, **Wi-Fi passwords, 802.1X and portal
credentials**. So pick the strongest option your setup allows:

| Setting | Use it when |
|---|---|
| `verify_tls: true` (default) | the server has a certificate from a public CA (e.g. Let's Encrypt) |
| `verify_tls: "/etc/wlanmon-probe/ca.crt"` | **recommended for internal servers:** trust exactly this CA (e.g. the internal CA of the dashboard's Docker setup, see the dashboard README) |
| `verify_tls: false` | self-signed certificate without a CA file: encrypted, but the server is not verified – anyone who can intercept the traffic can read the credentials |
| `url: "http://..."` | no encryption at all – only in an isolated test network |

With `verify_tls: false` or an `http://` URL, the probe logs a warning on
startup.

## Toolbox (`sudo wlanmon`)

A menu for everything you keep needing on a probe:

- **Status:** installed and checked-out version, branch, services, last update,
  Wi-Fi interface, MAC and country, IP addresses, measurements not yet sent
- **Logs:** probe log (live or recent), warnings and errors only, connection
  test results, updater and watchdog log
- **Services:** restart, stop or start the probe; status of all services and
  timers
- **Update:** run now, last result, compare versions and Git state, repair
  with the `update_probe.py` from the checkout if the installed copy is broken
- **Wi-Fi diagnostics:** adapter, country, bands and channels, scan as a table,
  search for an SSID, channel occupancy, `diagnose_wifi.sh`
- **Configuration:** setup wizard, show `config.yaml` (keys masked), edit it
  (with YAML check and restart), show the central configuration from the
  dashboard (passwords masked)
- **Network/server:** addresses and routes, server reachability, queue,
  time/NTP
- **System:** memory, temperature, reboot, shutdown

Without the menu: `sudo wlanmon status`, `log` (live), `update-log`,
`restart`, `update`, `setup` (wizard), `help`.

The tool lives in the Git checkout, so automatic updates keep it current. The
setup script creates the `wlanmon` command. On older installs, run it once
from the checkout and it sets the command up itself:

```bash
cd <repo_dir>          # e.g. ~/wlanmon/probe, see auto_update.repo_dir
sudo ./wlanmon-tool.sh
```

## Automatic updates

Off by default. When enabled, `update_probe.py` checks every 15 minutes
(`wlanmon-probe-update.timer`) whether the branch on GitHub has moved on. If
so, it:

1. fast-forwards the local checkout (`git merge --ff-only`),
2. copies the probe to `/opt/wlanmon-probe/` – all `*.py` files, `portals/`,
   `requirements.txt`, `VERSION` and `wlan_watchdog.sh`,
3. runs `pip install -r requirements.txt`,
4. does a **trial run** (`import main` with the probe's venv), and
5. only then restarts the probe.

If the trial run fails, the previous files go back in place, the probe keeps
running on the old version and the dashboard shows the error. Even when there
is nothing new, the updater checks that the installed files match the
checkout and fixes any difference.

```yaml
auto_update:
  enabled: true
  repo_dir: "/home/pi/wlanmon/probe"   # local Git checkout
  branch: "stable"
```

The setup script installs the timer and fills in `repo_dir` with the folder it
was started from; nothing happens until `enabled: true`. A changed branch takes
effect on the next run, without a restart.

The timer runs the copy of `update_probe.py` in `/opt/wlanmon-probe/`, not the
one in the checkout. When an update brings a new updater, it replaces that copy
and restarts itself, so the rest of the run already uses the new logic. Only
devices from the very first versions need a one-time manual step:

```bash
sudo cp /home/pi/wlanmon/probe/update_probe.py /opt/wlanmon-probe/update_probe.py
```

(Adjust the path to your `repo_dir`.)

**`stable` instead of `main`:** probes in the field should pull from `stable`.
Every commit lands on `main` right away, but a commit only reaches `stable`
when it is released on purpose – after [CI](../.github/workflows/ci.yml)
(`php -l`, `py_compile` and `bash -n` on every push) and ideally a test on a
single device. To release (fast-forward, no merge commit):

```bash
git fetch origin
git checkout stable
git merge --ff-only origin/main
git push origin stable
```

If `--ff-only` fails, `stable` has diverged (e.g. a hotfix made directly on
`stable`). Sort that out by hand instead of forcing it.

A few details that just work, but are good to know:

- The checkout usually belongs to the user who cloned it, while the updater
  runs as `root`. Git refuses that by default ("dubious ownership"), so the
  updater adds the checkout to root's `safe.directory` list itself.
- `repo_dir` may be the `probe/` folder of the combined repository; the `.git`
  folder can sit one level up.
- Hanging connections are cut short (SSH and HTTPS timeouts), and a failed
  fetch is retried once after 45 seconds. The dashboard shows a single failed
  run as a grey hint and only turns red after two in a row.

**Security note:** the updater runs whatever is on `branch`. Only enable it if
that branch contains released code only, and keep access to the GitHub account
secure. Local changes in the checkout make the update stop instead of being
overwritten, and a wrong `repo_dir` only produces a log line. To follow the
updater:

```bash
journalctl -u wlanmon-probe-update -f
```

## How it works

### Scans

Every `scan.interval_seconds` the probe runs `iw dev <iface> scan` and records
for each access point:

- SSID, BSSID, signal, frequency and channel
- Wi-Fi generation (4–7), channel width and the centre of the occupied
  channel block (for the spectrum view)
- security (e.g. `WPA2/WPA3-Personal`, `WPA3-Enterprise`, `OWE`, `Open`),
  PMF and the raw AKM suites
- roaming support: 802.11k, neighbour report, 802.11v (BSS transition) and
  802.11r
- if the AP sends it (BSS Load): number of clients and channel utilisation as
  the AP sees it

Right after the scan, `iw survey dump` adds the channel occupancy as the
probe's own radio sees it – busy time and noise per channel. This also works
with APs that do not send BSS Load. mt76 drivers (e.g. `mt7921u`) reset these
counters on every channel change, so a scan gives only a short snapshot per
channel. If the driver does not support it, the list stays empty.

### Connection tests

Every `connection_tests.interval_seconds` the probe connects to each target
SSID in turn (`wpa_supplicant` plus a DHCP client), measures, and disconnects
cleanly again:

- `assoc_seconds` – from starting `wpa_supplicant` to the association,
  including the search for the network; `scan_seconds` is the search part on
  its own
- `auth_seconds` – 802.1X and the 4-way handshake, for enterprise networks
- `dhcp_seconds`, then ping round trip and loss
- optionally iperf3 throughput (upload and download)
- the occupancy of the connected channel (`channel_load`): once for the quiet
  phase from DHCP to the end of the ping (the everyday load) and once during
  iperf3, each split into own transmit, receive and everything else
  (neighbours, interference)

The ping goes to `ping_target` of the SSID or, if empty, to the default
gateway the network handed out via DHCP – useful with separate VLANs. The
global `connection_tests.ping_target` is only the last fallback.

### iperf3

iperf3 is set per SSID: on or off, download on or off, and server, port and
duration if an SSID leads into its own VLAN with its own iperf3 server (empty
or 0 = use the defaults from `connection_tests`). `iperf3_bitrate_mbps` limits
the rate (0 = unlimited). An unlimited test fills the channel for its duration;
a limited one checks whether a given rate holds. The dashboard marks iperf3
periods in the "Timeline" tab.

To keep the load down, `iperf3_min_interval_minutes` runs iperf3 at most once
per SSID within that time; the other tests skip it. A test started with
button 3 on the display always measures. The timestamps survive restarts.

The **LAN test** (`connection_tests.iperf3_lan`) measures throughput over
Ethernet once per cycle as a reference, with its own minimum interval
(`min_interval_minutes`, default 30).

An iperf3 server only serves one test at a time. If it is busy (e.g. another
probe is measuring), the probe tries again up to three times and otherwise
moves the measurement to the next cycle – that does not count as a failure.

**Recommended server options:** `iperf3 -s --idle-timeout=30
--rcv-timeout=10000` (TCP and UDP port 5201 open). Without them, a server
whose client drops out mid-test can get stuck on "busy" for good – we once saw
all LAN tests of a site fail for hours until the server was restarted. Write
the options with `=`: some NAS Docker interfaces pass `--idle-timeout 30` as
one argument including the space, and iperf3 then refuses to start. As a
Docker Compose service:

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

### Captive portals

**Detection** (per SSID, `captive_portal_check: true`): after DHCP the probe
requests `connection_tests.captive_portal_url` (default
`http://connectivitycheck.gstatic.com/generate_204`) over the Wi-Fi interface
without following redirects. HTTP 204 means no portal; 3xx, 200 or 511 mean a
portal (the redirect target is recorded). A portal does not make the test
fail. It also measures TCP connect and response time of that URL – a latency
that gets through most portals.

While a portal blocks traffic, iperf3 is skipped and only the gateway is
pinged. Some portals (e.g. Cisco Meraki) block even that before login; the
dashboard then shows "portal blocks ICMP" instead of counting it as loss.
Portals often remember the MAC address after the first login – use a random
MAC (below) to see the portal on every test.

**Login** (per SSID, `captive_portal_login`): the probe logs in through the
portal, counts the login as successful once the detection URL returns 204, and
then runs ping and iperf3 as usual. At the end it logs off again (`logoff`, on
by default) so guest sessions do not pile up. The password is never logged or
sent to the dashboard, but like the Wi-Fi passwords it is stored in plain text
in the configuration. See [Portal modules](#portal-modules) for supported
portals.

**Random MAC** (per SSID, `random_mac: true`): before the test the probe sets
a random, locally administered MAC address and restores the real one
afterwards. Do not use it on networks with MAC filtering or DHCP reservations.
If the driver does not allow it, the test continues with the normal MAC.

### Error codes

A failed test carries a German message for the probe's own log (`error`) and a
list of codes (`error_codes`, e.g. `assoc_failed`, `sae_password_wrong`). The
dashboard turns the codes into a message in your language. When adding a new
code in `wifi_ops.py` (`_err()`), add it to the dashboard's
`src/ProbeError.php` as well; until then the dashboard shows the German text.

### Queue and heartbeat

Every measurement goes into a local SQLite queue first. A sender thread sends
it to the dashboard in batches (`queue.batch_size`, every
`queue.flush_interval_seconds`) and only deletes it once the dashboard has
confirmed it. If that fails, it tries again later.

Separately, a **heartbeat** (`heartbeat.interval_seconds`, default 60) tells
the dashboard that the probe is alive, even when no measurement is due. It
carries system values – CPU, memory, disk, temperature, uptime, queue length
and, on a Raspberry Pi, power supply and throttling – which the dashboard shows
in the "System" tab. The answer also tells the probe when its central
configuration has changed, so it picks it up right away.

## Capturing failed tests

The probe records every connection test and keeps the recording only if the
test fails (`connection_tests.capture_on_failure`, on by default, switchable
per device in the dashboard). A recording contains:

- a **pcap** of the test interface (`tcpdump`): EAPOL (802.1X and 4-way
  handshake), DHCP, ARP, DNS and ICMP – no iperf3 traffic. The adapter runs as a
  normal client, so the 802.11 management frames (authentication,
  association, deauth) are **not** included; that would need a second adapter
  in monitor mode.
- the **adapter events** from `iw event`: authenticate, associate, connect,
  deauth and disconnect with status and reason codes – what tcpdump cannot see.
- the **wpa_supplicant log**: EAP method, server certificate and the reason
  for the failure from the client's point of view. No passwords.

So that tcpdump sees the EAPOL frames at all, `wpa_supplicant` runs with
`-p control_port=0` while capturing; for the AP nothing changes.

The files wait in `/var/lib/wlanmon-probe/captures/` (at most 1.5 MB pcap per
test, 20 MB in total, oldest dropped first) until they are uploaded. In the
dashboard they appear as download links next to the failed test (admins and
users; kept 30 days by default). Captures contain MAC addresses and possibly
802.1X identities – keep that in mind before passing them on.

Without `tcpdump`, only the events are recorded; install it with
`sudo apt install tcpdump`.

## Portal modules

The portal login lives in the `portals/` package, one module per portal type.
`captive_portal_login.type` selects the module; `auto` picks it from the
redirect URL.

| type | Portal | Login methods |
|------|--------|---------------|
| `cirrus` | Alcatel-Lucent OmniVista Cirrus (guest/BYOD) | username/password, access code, terms of use only |
| `form` | Classic form-based portals, e.g. pfSense | username/password, voucher/code, just clicking "Accept" |

`form` loads the portal page, reads the HTML form and fills it in: the password
field (or a field named like `voucher`, `code` or `token`) gets the password, a
field like `user` or `login` the username, and a terms checkbox gets ticked.
Without such fields, no credentials are needed. `form` has no logoff and is the
fallback for `auto`. Portals that build their form with JavaScript need a
module of their own.

To add a portal: create a file in `portals/`, subclass `PortalModule`
(`portals/base.py`: `detect()`, `login()`, optionally `logoff()`), register it
in `MODULES` in `portals/__init__.py` and add it to the portal type drop-down
in the dashboard. Modules report errors in their result instead of raising
exceptions.

## Display & buttons (NanoHat OLED)

Optional, built into the probe (`display.py`):

- **Display:** SSD1306-compatible OLED, I2C address `0x3c`, bus `i2c-0`
- **3 buttons:** GPIO `0`, `2` and `3`

No other program may use the display at the same time (e.g. an OLED service
from the OS image), otherwise both fight over the I2C bus and the GPIOs.

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

The screens show device ID, site, uptime and the last transfer to the
dashboard; the last scan; and the last connection test (SSID, result, ping,
iperf3, error). Buttons 1 and 2 switch between the screens, button 3 starts a
connection test right away – handy when you are standing next to the device.

After `sleep_after_seconds` without a button press the display switches off,
because OLEDs burn in. Any button wakes it up; that first press does nothing
else. If the display stays dark, look for an I2C error in
`journalctl -u wlanmon-probe`; the rest of the probe keeps running either way.

## Central configuration from the dashboard

With `remote_config.enabled: true` (the default), the probe gets its test
settings from the dashboard: scans, connection tests with all target SSIDs,
heartbeat and the site name.

- On startup it fetches `GET <server.url>/devices/<device_id>/config` and keeps
  a copy in `remote_config.cache_path`. If the dashboard is unreachable, it
  uses that copy, otherwise the values from `config.yaml`.
- A 404 means the dashboard has no configuration for this device yet; the local
  values stay active.
- While running, it checks for changes every
  `remote_config.poll_interval_seconds` – and immediately when the heartbeat
  reports one. On a change it restarts itself cleanly, so scans and tests never
  run with half old, half new settings.

`device.id`, `interface.*`, `server.*`, `queue.*` and `logging.*` always stay
local and are never overwritten by the dashboard – otherwise a probe could cut
itself off.

## 802.1X / WPA2-Enterprise

Targets with `security: "wpa2-eap"` need an `eap` block, normally maintained in
the dashboard (example in `config.example.yaml`). Supported: `peap`
(MSCHAPv2), `ttls` (PAP, MSCHAPv2, MSCHAP, CHAP) and `tls` (client
certificate and key).

- The CA certificate is optional. If one is stored and `verify_server` is not
  `false`, the RADIUS server is verified (optionally against `server_name`).
- Certificates and keys are stored as PEM text in the configuration. During a
  test they are written to a private temporary folder that is deleted
  afterwards. The local configuration copy is readable by root only.
- After the association the probe waits for the completed handshake and
  reports its duration as `auth_seconds`. If it fails, the result names the
  cause (certificate, password, RADIUS timeout, ...).

## USB hotplug & watchdog

Plug the USB Wi-Fi adapter in before booting and leave it there. On the
NanoPi NEO2 we saw this: if the `mt7921u` adapter is unplugged and plugged back
in during operation, it sometimes ends up on the slow USB controller
(`dmesg`: "not running at top speed"). The firmware upload then fails, the
driver crashes ("Failed to get patch semaphore") and the adapter stays dead
until the next reboot – replugging does not help. This is a limitation of the
Allwinner H5 USB controllers, not a software bug.

For the case that it happens anyway (a loose connector in the field), the
**watchdog** reboots the device once the test interface has been missing for
longer than `missing_threshold_minutes`. It checks every 2 minutes and runs on
its own timer, so it also works when the probe itself hangs. It is off by
default; the setup wizard asks about it for USB adapters.

```yaml
watchdog:
  enabled: true
  missing_threshold_minutes: 10
```

## Troubleshooting

### DHCP

The probe uses `dhclient` if it is installed and `dhcpcd` otherwise, and
always releases the lease with the same client.

- **Raspberry Pi 3 and 5: uninstall `dhclient`.** With `isc-dhcp-client`,
  connection tests there kept failing with a `DHCPDECLINE` right after the
  `DHCPACK` (the new IP is rejected at once), sometimes also with "Network is
  down" – even on a quiet network with a strong signal, with built-in Wi-Fi and
  with USB adapters alike. With `dhcpcd` everything runs cleanly:

  ```bash
  sudo apt remove -y isc-dhcp-client
  sudo systemctl restart wlanmon-probe
  ```

  On the NanoPi (Armbian), `dhclient` works fine. We do not know the exact
  cause. Not to be confused with "Failed to allocate an IPv4 address" in the
  Kea log of a pfSense – that means the DHCP pool is too small.
- **Every test starts with a fresh DISCOVER.** With an old lease, `dhclient`
  tries to reuse it, which reliably failed after roaming to another AP. The
  probe therefore uses its own lease file and empties it before every test.
- **No 169.254.x.x addresses.** Without an answer, `dhcpcd` would hand itself
  a link-local address and the test would look successful. The probe turns
  that off and also rejects such addresses explicitly.
- **Before DHCP, the probe switches off promiscuous mode and Wi-Fi power
  saving** on the test interface. Some drivers (seen with `brcmfmac` on the
  Raspberry Pi 5) leave promiscuous mode on after a scan, which can make
  `dhclient` suspect an address conflict; power saving on Broadcom chips can
  drop packets in the middle of a DHCP exchange.

### Where to look

- `sudo wlanmon` – status, logs and diagnostics in one menu
- `journalctl -u wlanmon-probe -f` – probe log
- `journalctl -u wlanmon-probe-update -n 50` – last update runs

## Server API (for developers)

The probe sends its measurements as `POST <server.url>/measurements`:

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

The dashboard answers with HTTP 2xx once it has accepted the whole batch.
Anything else – an error, a timeout, or 429 when the dashboard's rate limit
kicks in – leaves the batch in the queue, and it is sent again later.

`auto_update` describes the update state for the device page. On/off and
branch come fresh from `config.yaml`; repository URL (without any credentials),
commit and the result of the last update run come from
`/var/lib/wlanmon-probe/update_state.json`, which the updater writes (the probe
itself cannot see the checkout under `/home`).

`probe_version` comes from the `VERSION` file. Every commit on `main` raises
the fourth digit (`1.0.1.1`, `1.0.1.2`, ...); the next release drops it again
(`1.0.2`). This is done by hand in the same commit.

## License

MIT – see [LICENSE](../LICENSE).
