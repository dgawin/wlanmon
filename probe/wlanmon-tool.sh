#!/usr/bin/env bash
# WLANMON probe toolbox: logs, services, updates, Wi-Fi diagnostics,
# configuration and system tasks from a menu - the commands you otherwise
# keep typing by hand.
#
# Lives in the git checkout (auto_update.repo_dir) and is kept up to date
# there by the auto-update ("git pull"). The first run as root sets up the
# "wlanmon" command by itself (setup_wlanmon_probe.sh does this on new
# installs), e.g. from inside the checkout:
#   sudo ./wlanmon-tool.sh
#
# Usage:
#   sudo wlanmon                 menu
#   sudo wlanmon status          status overview
#   sudo wlanmon log             follow the probe log
#   sudo wlanmon update-log      updater log (recent runs)
#   sudo wlanmon restart         restart the probe service
#   sudo wlanmon setup           setup wizard (device ID, dashboard, API key ...)
#   sudo wlanmon update          run an update now
#   sudo wlanmon help            this help
#
# Paths can be overridden for testing (WLANMON_CONFIG, WLANMON_INSTALL_DIR,
# WLANMON_STATE_FILE).

set -uo pipefail

CONFIG="${WLANMON_CONFIG:-/etc/wlanmon-probe/config.yaml}"
INSTALL_DIR="${WLANMON_INSTALL_DIR:-/opt/wlanmon-probe}"
STATE_FILE="${WLANMON_STATE_FILE:-/var/lib/wlanmon-probe/update_state.json}"
SVC="wlanmon-probe"
UPD="wlanmon-probe-update"
WDG="wlanmon-wifi-watchdog"
SELF="$(readlink -f "${BASH_SOURCE[0]}")"
REPO_DIR_SELF="$(dirname "$SELF")"
# iw/ip live in /usr/sbin, which is missing from PATH after "su" without "-".
export PATH="$PATH:/usr/sbin:/sbin"

if [ -x "$INSTALL_DIR/venv/bin/python3" ]; then
    PY="$INSTALL_DIR/venv/bin/python3"
else
    PY="python3"
fi

# --- Helpers ----------------------------------------------------------------

if [ -t 1 ]; then
    B=$'\e[1m'; DIM=$'\e[2m'; RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; RST=$'\e[0m'
else
    B=""; DIM=""; RED=""; GRN=""; YLW=""; RST=""
fi

title() { echo; echo "${B}== $* ==${RST}"; }
info()  { echo "${DIM}$*${RST}"; }
ok()    { echo "${GRN}$*${RST}"; }
warn()  { echo "${YLW}$*${RST}"; }
err()   { echo "${RED}$*${RST}"; }

pause() {
    echo
    read -r -p "Press Enter to continue ... " _ || true
}

confirm() {
    local answer
    read -r -p "$1 [y/N] " answer || return 1
    [[ "$answer" =~ ^[yYjJ]$ ]]
}

# Value from config.yaml by dotted path, e.g. cfg interface.name wlan0
cfg() {
    "$PY" - "$CONFIG" "$1" "${2:-}" <<'PYEOF' 2>/dev/null
import sys, yaml
path, key, default = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    node = yaml.safe_load(open(path, encoding="utf-8")) or {}
    for part in key.split("."):
        node = node[part]
    # Booleans as written in config.yaml (true/false) instead of Python's True/False.
    print("" if node is None else (str(node).lower() if isinstance(node, bool) else node))
except Exception:
    print(default)
PYEOF
}

# Git-Checkout direkt in $1 oder eine Ebene hoeher - im gemeinsamen Repo
# liegt die Probe im Unterordner probe/ (wie in update_probe.py akzeptiert).
is_checkout() { [ -e "$1/.git" ] || [ -e "$1/../.git" ]; }

IFACE="$(cfg interface.name wlan0)"
REPO_DIR="$(cfg auto_update.repo_dir "$REPO_DIR_SELF")"
is_checkout "$REPO_DIR" || REPO_DIR="$REPO_DIR_SELF"

# Print YAML/JSON with secrets (keys like api_key, psk, password ...) masked.
show_masked() {
    "$PY" - "$1" <<'PYEOF'
import sys, json, yaml
SECRET = {"api_key", "psk", "password", "private_key", "private_key_password", "bot_token", "smtp_pass", "token"}
def mask(node):
    if isinstance(node, dict):
        return {k: ("***" if k in SECRET and node[k] not in (None, "") else mask(v)) for k, v in node.items()}
    if isinstance(node, list):
        return [mask(v) for v in node]
    return node
text = open(sys.argv[1], encoding="utf-8").read()
try:
    data = json.loads(text)
except ValueError:
    data = yaml.safe_load(text)
print(yaml.safe_dump(mask(data), allow_unicode=True, sort_keys=False, default_flow_style=False))
PYEOF
}

need_root() {
    if [ "$(id -u)" -ne 0 ]; then
        exec sudo -- bash "$SELF" "$@"
    fi
}

svc_state() {
    local s
    s="$(systemctl is-active "$1" 2>/dev/null)"
    case "$s" in
        active) echo "${GRN}${s}${RST}" ;;
        activating|reloading) echo "${YLW}${s}${RST}" ;;
        *) echo "${RED}${s:-unknown}${RST}" ;;
    esac
}

# iw scan with retries in case the probe is scanning/testing itself ("busy").
iw_scan() {
    local out i
    for i in 1 2 3 4 5; do
        out="$(iw dev "$IFACE" scan "$@" 2>&1)" && { printf '%s\n' "$out"; return 0; }
        if [[ "$out" == *"busy"* ]]; then
            info "Adapter busy (probe is scanning/testing) - retrying in 5 s ($i/5) ..." >&2
            sleep 5
        elif [[ "$out" == *"Network is down"* ]]; then
            ip link set "$IFACE" up
            sleep 2
        else
            printf '%s\n' "$out" >&2
            return 1
        fi
    done
    err "Adapter stayed busy - stop the probe service briefly if needed (Services menu)." >&2
    return 1
}

# iw scan output as a table: signal, band/channel, security, BSSID, SSID - strongest first.
scan_table() {
    awk '
        function flush() {
            if (bssid != "") {
                band = freq < 3000 ? "2.4" : (freq < 5925 ? "5" : "6")
                ch = freq < 3000 ? (freq == 2484 ? 14 : (freq - 2407) / 5) : (freq < 5925 ? (freq - 5000) / 5 : (freq - 5950) / 5)
                printf "%7s dBm  %-4s ch%-4s %-10s %s  %s\n", sig, band, ch, sec, bssid, (ssid == "" ? "(hidden)" : ssid)
            }
            bssid = ""; ssid = ""; sig = "?"; freq = 0; sec = "open"
        }
        /^BSS / { flush(); bssid = substr($2, 1, 17); section = "" }
        # Remember the current section (RSN:, WPA:, HT capabilities: ...) so the
        # auth suites of the legacy WPA1 element do not skew the result.
        /^\t[A-Za-z]/ { section = $1 }
        /^\tfreq:/ { freq = int($2) }
        /^\tsignal:/ { sig = $2 }
        /^\tSSID:/ { ssid = substr($0, index($0, ":") + 2) }
        section == "RSN:" && /Authentication suites:.*SAE/ { sec = (sec ~ /WPA2/ ? "WPA2/WPA3" : "WPA3") }
        section == "RSN:" && /Authentication suites:.*PSK/ { sec = (sec ~ /WPA3/ ? "WPA2/WPA3" : "WPA2") }
        section == "RSN:" && /Authentication suites:.*802\.1X/ { sec = "Enterprise" }
        /^\tWPA:/ { if (sec == "open") sec = "WPA" }
        END { flush() }
    ' | sort -rn
}

run_show() {
    # Show the command, then run it
    info "\$ $*"
    "$@"
}

# --- Sections ---------------------------------------------------------------

status_overview() {
    title "Status"
    local version branch commit
    version="$(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo "?")"
    echo "Device:         $(cfg device.id "$(hostname)")   (host $(hostname))"
    echo "Version:        installed $version, checkout $(cat "$REPO_DIR/VERSION" 2>/dev/null || echo "?")"
    if is_checkout "$REPO_DIR"; then
        branch="$(git -C "$REPO_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null)"
        commit="$(git -C "$REPO_DIR" log -1 --format='%h %cd %s' --date=format:'%Y-%m-%d %H:%M' 2>/dev/null)"
        echo "Git:            $REPO_DIR ($branch) $commit"
    fi
    echo "Auto-update:    $(cfg auto_update.enabled false) (branch $(cfg auto_update.branch '?'))"
    echo "Services:       probe $(svc_state "$SVC"), update timer $(svc_state "$UPD.timer"), watchdog timer $(svc_state "$WDG.timer")"
    if [ -f "$STATE_FILE" ]; then
        echo "Last update:    $("$PY" -c 'import json,sys; s=json.load(open(sys.argv[1])); print(s.get("last_run_at","?"), "-", s.get("result","?"), "-", (s.get("message") or "")[:90])' "$STATE_FILE" 2>/dev/null)"
    fi
    echo "Wi-Fi:          $IFACE, MAC $(cat "/sys/class/net/$IFACE/address" 2>/dev/null || echo '?'), country $(iw reg get 2>/dev/null | awk '/^country/ {print $2; exit}' | tr -d :)"
    echo "IP addresses:   $(ip -4 -br addr show 2>/dev/null | awk '$1 != "lo" {printf "%s %s  ", $1, $3}')"
    echo "Queue:          $(queue_count) measurement(s) not sent yet"
    echo "Uptime:         $(uptime -p 2>/dev/null)"
}

queue_count() {
    local db
    db="$(cfg queue.db_path /var/lib/wlanmon-probe/queue.db)"
    "$PY" - "$db" <<'PYEOF' 2>/dev/null || echo "?"
import sqlite3, sys
con = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
print(con.execute("SELECT COUNT(*) FROM measurements WHERE sent = 0").fetchone()[0])
PYEOF
}

menu_logs() {
    local c logfile
    logfile="$(cfg logging.file "")"
    while true; do
        title "Logs"
        echo " 1) Follow the probe log (Ctrl+C to stop)"
        echo " 2) Probe log: last 150 lines"
        echo " 3) Probe log: warnings/errors since today"
        echo " 4) Connection test results since today"
        echo " 5) Updater log: recent runs"
        echo " 6) Follow the updater log"
        echo " 7) Wi-Fi watchdog log"
        echo " 8) Probe log file ($logfile): last 150 lines"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            1) journalctl -u "$SVC" -f -n 30 ;;
            2) journalctl -u "$SVC" -n 150 --no-pager; pause ;;
            3) journalctl -u "$SVC" -p warning --since today --no-pager; pause ;;
            4) journalctl -u "$SVC" --since today --no-pager | grep -E "Connection-Test" | sed -E 's/^.*wlanmon_probe: //'; pause ;;
            5) journalctl -u "$UPD" -n 80 --no-pager; pause ;;
            6) journalctl -u "$UPD" -f -n 20 ;;
            7) journalctl -u "$WDG" -n 60 --no-pager; pause ;;
            8) if [ -n "$logfile" ] && [ -f "$logfile" ]; then tail -n 150 "$logfile"; else warn "No log file configured (logging.file)."; fi; pause ;;
            0|"") return ;;
        esac
    done
}

menu_services() {
    local c
    while true; do
        title "Services"
        echo "Probe: $(svc_state "$SVC")   update timer: $(svc_state "$UPD.timer")   watchdog timer: $(svc_state "$WDG.timer")"
        echo " 1) Status of all WLANMON services and timers"
        echo " 2) Restart the probe service"
        echo " 3) Stop the probe service"
        echo " 4) Start the probe service"
        echo " 5) Timers: next run"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            1) systemctl status --no-pager -n 5 "$SVC" "$UPD.timer" "$WDG.timer" 2>&1; pause ;;
            2) run_show systemctl restart "$SVC" && ok "Restarted."; sleep 2; journalctl -u "$SVC" -n 8 --no-pager; pause ;;
            3) confirm "Really stop the probe service? (no measurements until it runs again)" && run_show systemctl stop "$SVC" && ok "Stopped."; pause ;;
            4) run_show systemctl start "$SVC" && ok "Started."; sleep 2; journalctl -u "$SVC" -n 8 --no-pager; pause ;;
            5) systemctl list-timers --all --no-pager 'wlanmon*'; pause ;;
            0|"") return ;;
        esac
    done
}

menu_update() {
    local c since
    while true; do
        title "Update"
        echo " 1) Run an update now (same as the timer)"
        echo " 2) Result of the last update"
        echo " 3) Compare installed version and git state"
        echo " 4) Repair: run the update with the script from the checkout"
        echo "    (if the installed copy of update_probe.py is broken)"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            1)
                since="$(date '+%Y-%m-%d %H:%M:%S')"
                info "Updating ..."
                systemctl start "$UPD.service"
                journalctl -u "$UPD" --since "$since" --no-pager
                pause ;;
            2)
                if [ -f "$STATE_FILE" ]; then show_masked "$STATE_FILE"; else warn "No update run recorded yet ($STATE_FILE)."; fi
                pause ;;
            3)
                echo "Installed ($INSTALL_DIR): $(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo '?')"
                echo "Checkout ($REPO_DIR):  $(cat "$REPO_DIR/VERSION" 2>/dev/null || echo '?')"
                if is_checkout "$REPO_DIR"; then
                    git -C "$REPO_DIR" log -3 --format='  %h %cd %s' --date=format:'%Y-%m-%d %H:%M'
                    info "Checking the server state (git fetch) ..."
                    if git -C "$REPO_DIR" fetch -q 2>/dev/null; then
                        echo "New commits on the server: $(git -C "$REPO_DIR" rev-list --count 'HEAD..@{u}' 2>/dev/null || echo '?')"
                    else
                        warn "git fetch failed (the user's SSH key? The updater itself runs as root with its own settings)."
                    fi
                fi
                pause ;;
            4)
                if confirm "Run the update with $REPO_DIR/update_probe.py (fetches, copies, restarts)?"; then
                    run_show "$PY" "$REPO_DIR/update_probe.py"
                fi
                pause ;;
            0|"") return ;;
        esac
    done
}

menu_wifi() {
    local c ssid
    while true; do
        title "Wi-Fi diagnostics ($IFACE)"
        echo " 1) Adapter and interfaces (lsusb, iw dev)"
        echo " 2) Country / regulatory domain (iw reg get)"
        echo " 3) Supported bands and channels"
        echo " 4) Scan: all networks (active)"
        echo " 5) Scan: 2.4 GHz only, active vs. passive"
        echo " 6) Search for an SSID"
        echo " 7) Channel occupancy (survey dump)"
        echo " 8) Connection/link, if a test is running right now"
        echo " 9) Capture during the next connection test (diagnose_wifi.sh --once)"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            1) lsusb; echo; iw dev; echo; ip -br link; pause ;;
            2) iw reg get | head -12; info "Expected per config.yaml: interface.country = $(cfg interface.country DE)"; pause ;;
            3) iw phy | grep -E "Band [0-9]|\* [0-9]{4}(\.[0-9])? MHz" | sed -E 's/^\s+/  /'; pause ;;
            4) iw_scan | scan_table; pause ;;
            5)
                echo "${B}active:${RST}"; iw_scan freq 2412 2417 2422 2427 2432 2437 2442 2447 2452 2457 2462 2467 2472 | scan_table
                echo "${B}passive:${RST}"; iw_scan freq 2412 2417 2422 2427 2432 2437 2442 2447 2452 2457 2462 2467 2472 passive | scan_table
                pause ;;
            6)
                read -r -p "SSID (exact name): " ssid
                if [ -n "$ssid" ]; then
                    iw_scan ssid "$ssid" | scan_table | awk -v s="$ssid" 'index($0, "  " s) == length($0) - length(s) - 1 { print; n++ } END { if (!n) print "not found: no AP with this SSID in range (all bands searched actively)" }'
                fi
                pause ;;
            7) iw dev "$IFACE" survey dump | grep -E "frequency|in use|noise|channel (active|busy) time" | sed -E 's/^\s+/  /'; pause ;;
            8) iw dev "$IFACE" link; ip -4 addr show "$IFACE"; pause ;;
            9)
                if [ -f "$REPO_DIR/diagnose_wifi.sh" ]; then
                    bash "$REPO_DIR/diagnose_wifi.sh" "$IFACE" /tmp/wlan-diag --once
                else
                    warn "diagnose_wifi.sh not found."
                fi
                pause ;;
            0|"") return ;;
        esac
    done
}

# Setup wizard (config_wizard.py in the checkout): asks for device ID,
# dashboard URL, API key, TLS, Wi-Fi interface etc. and writes config.yaml.
run_setup_wizard() {
    local wizard="$REPO_DIR/config_wizard.py"
    [ -f "$wizard" ] || wizard="$REPO_DIR_SELF/config_wizard.py"
    if [ ! -f "$wizard" ]; then
        err "config_wizard.py not found in $REPO_DIR - update the checkout first (sudo wlanmon update)."
        return 1
    fi
    if "$PY" "$wizard" "$CONFIG"; then
        confirm "Restart the probe service so the new configuration takes effect?" \
            && systemctl restart "$SVC" && ok "Restarted."
    fi
}

menu_config() {
    local c editor cache
    cache="$(cfg remote_config.cache_path /var/lib/wlanmon-probe/remote_config_cache.yaml)"
    while true; do
        title "Configuration"
        echo " 1) Show config.yaml (secrets masked)"
        echo " 2) Edit config.yaml"
        echo " 3) Show the central configuration from the dashboard (cache, passwords masked)"
        echo " 4) Setup wizard (device ID, dashboard, API key, interface ...)"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            4) run_setup_wizard; pause ;;
            1) show_masked "$CONFIG"; pause ;;
            2)
                editor="${EDITOR:-$(command -v nano || command -v vi)}"
                cp -p "$CONFIG" "$CONFIG.bak.$(date +%Y%m%d%H%M%S)"
                "$editor" "$CONFIG"
                if "$PY" -c 'import sys,yaml; yaml.safe_load(open(sys.argv[1]))' "$CONFIG" 2>/dev/null; then
                    ok "YAML is valid."
                    confirm "Restart the probe service so the change takes effect?" && systemctl restart "$SVC" && ok "Restarted."
                else
                    err "YAML error! The file cannot be read like this - please edit it again (backup: $CONFIG.bak.*)."
                fi
                pause ;;
            3) if [ -f "$cache" ]; then show_masked "$cache"; else warn "No cache ($cache) - remote config disabled or never loaded."; fi; pause ;;
            0|"") return ;;
        esac
    done
}

menu_network() {
    local c url
    url="$(cfg server.url "")"
    while true; do
        title "Network and server"
        echo " 1) IP addresses and routes"
        echo " 2) Is the server reachable? ($url)"
        echo " 3) Queue: measurements not sent yet"
        echo " 4) Time/NTP synchronization"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            1) ip -br addr; echo; ip route; pause ;;
            2)
                if [ -z "$url" ]; then warn "server.url is missing in config.yaml."
                else
                    # Without the API key: 401/404 means "server reached"; errors here are DNS/TLS/network.
                    curl -sS -o /dev/null -m 15 -w "HTTP %{http_code}, %{time_total} s (DNS %{time_namelookup} s, TLS %{time_appconnect} s)\n" "$url/" \
                        && ok "Server reachable (any HTTP response counts, including 401/404)." \
                        || err "Server not reachable - see the message above (DNS, certificate, network)."
                fi
                pause ;;
            3) echo "$(queue_count) measurement(s) waiting to be sent."; pause ;;
            4) timedatectl 2>/dev/null || date; pause ;;
            0|"") return ;;
        esac
    done
}

menu_system() {
    local c
    while true; do
        title "System"
        echo " 1) Disk space, memory, temperature"
        echo " 2) Reboot"
        echo " 3) Shut down"
        echo " 0) back"
        read -r -p "> " c || return
        case "$c" in
            1)
                df -h / /var 2>/dev/null | awk '!seen[$0]++'; echo; free -h
                for t in /sys/class/thermal/thermal_zone*/temp; do
                    [ -f "$t" ] && echo "Temperature $(basename "$(dirname "$t")"): $(( $(cat "$t") / 1000 )) °C"
                done
                pause ;;
            2) confirm "Really reboot the device?" && run_show systemctl reboot ;;
            3) confirm "Really shut down the device? (only reachable again by power cycling it)" && run_show systemctl poweroff ;;
            0|"") return ;;
        esac
    done
}

install_command() {
    ln -sf "$SELF" /usr/local/bin/wlanmon
    chmod +x "$SELF" 2>/dev/null || true
    ok "Set up: /usr/local/bin/wlanmon -> $SELF"
    echo "From now on: sudo wlanmon"
}

# On the first run as root (e.g. "sudo ./wlanmon-tool.sh" in the checkout),
# quietly set up the "wlanmon" command so no separate --install is needed.
auto_install() {
    if [ -d /usr/local/bin ] && [ "$(readlink -f /usr/local/bin/wlanmon 2>/dev/null)" != "$SELF" ]; then
        if ln -sf "$SELF" /usr/local/bin/wlanmon 2>/dev/null; then
            chmod +x "$SELF" 2>/dev/null || true
            info "Command set up - from now on simply: sudo wlanmon"
        fi
    fi
}

main_menu() {
    local c
    while true; do
        title "WLANMON toolbox - $(cfg device.id "$(hostname)") ($(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo '?'))"
        echo " 1) Status overview"
        echo " 2) Logs"
        echo " 3) Services (restart, stop, start)"
        echo " 4) Update"
        echo " 5) Wi-Fi diagnostics"
        echo " 6) Configuration"
        echo " 7) Network and server"
        echo " 8) System (reboot, shut down)"
        echo " q) Quit"
        read -r -p "> " c || exit 0
        case "$c" in
            1) status_overview; pause ;;
            2) menu_logs ;;
            3) menu_services ;;
            4) menu_update ;;
            5) menu_wifi ;;
            6) menu_config ;;
            7) menu_network ;;
            8) menu_system ;;
            q|Q|0) exit 0 ;;
        esac
    done
}

# --- Entry point ------------------------------------------------------------

case "${1:-}" in
    help|-h|--help)
        sed -n '2,/^$/p' "$SELF" | sed 's/^# \{0,1\}//'
        exit 0 ;;
esac
need_root "$@"
[ "${1:-}" = "--install" ] || auto_install

case "${1:-}" in
    "") main_menu ;;
    --install) install_command ;;
    status) status_overview ;;
    log) journalctl -u "$SVC" -f -n 30 ;;
    update-log) journalctl -u "$UPD" -n 80 --no-pager ;;
    restart) systemctl restart "$SVC" && ok "Probe service restarted." ;;
    setup) run_setup_wizard ;;
    update)
        since="$(date '+%Y-%m-%d %H:%M:%S')"
        systemctl start "$UPD.service"
        journalctl -u "$UPD" --since "$since" --no-pager ;;
    *) err "Unknown command: $1 (sudo wlanmon help)"; exit 1 ;;
esac
