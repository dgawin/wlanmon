#!/usr/bin/env bash
# Provisioning-Script fuer wlanmon-probe auf dem NanoPi NEO2/NEO3.
#
# Aufgaben in einem Lauf:
#   1. Konflikte mit der WLAN-Steuerung vermeiden (nachgewiesen: ifplugd
#      verwaltet wlan0 im Wireless-Hotplug-Modus und kollidiert mit der
#      direkten wpa_supplicant/iw-Steuerung in wifi_ops.py; ausserdem die
#      ARP-Flux-Sysctls fuer den Fall, dass eth0 und das WLAN-Testnetz im
#      selben Subnetz haengen).
#   1.5 net.ifnames=0 setzen, damit USB-WLAN-Adapter ueber Geraete hinweg
#      konsistent wlan0/wlan1 statt der MAC-basierten Form (wlx<mac>)
#      bekommen - wirkt erst nach einem Reboot.
#   2. wlanmon-probe selbst installieren/aktualisieren (Dateien, Python-
#      Abhaengigkeiten, systemd-Service).
#   3. Einrichtungsassistent (config_wizard.py): fragt Geraete-ID, Dashboard-
#      URL, API-Key, TLS, WLAN-Interface usw. ab, testet die Verbindung und
#      schreibt config.yaml. Laeuft bei einer neuen Installation automatisch,
#      sofern das Skript in einem Terminal laeuft; spaeter erneut mit
#      "sudo wlanmon setup".
#
# Mehrfach ausfuehrbar; fehlt etwas (z.B. ifplugd), wird der jeweilige
# Schritt uebersprungen.
#
# Aufruf: sudo ./setup_wlanmon_probe.sh [--skip-foundation-cleanup] [--wizard|--no-wizard]
#   --wizard     Assistent auch bei vorhandener config.yaml ohne Rueckfrage starten
#   --no-wizard  keinen Assistenten (z.B. fuer automatisierte Installationen)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
SKIP_FOUNDATION_CLEANUP=0
WIZARD="auto"
for arg in "$@"; do
    case "$arg" in
        --skip-foundation-cleanup) SKIP_FOUNDATION_CLEANUP=1 ;;
        --wizard) WIZARD="yes" ;;
        --no-wizard) WIZARD="no" ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run with sudo/as root." >&2
    exit 1
fi

log() { echo "[setup_wlanmon_probe] $*"; }

# ---------------------------------------------------------------------
# 1. Konflikte mit der WLAN-Steuerung vermeiden
# ---------------------------------------------------------------------

remove_wlan_from_ifplugd() {
    local cfg="/etc/default/ifplugd"
    if [ ! -f "$cfg" ]; then
        log "No ifplugd configuration ($cfg) - skipping."
        return
    fi
    if ! grep -q 'HOTPLUG_INTERFACES=.*wlan' "$cfg"; then
        log "ifplugd does not manage a Wi-Fi interface ($cfg) - nothing to change."
        return
    fi

    log "Removing wlan0/wlan1 from ifplugd HOTPLUG_INTERFACES in $cfg ..."
    cp "$cfg" "$cfg.bak.$(date +%Y%m%d%H%M%S)"
    # Nur wlan*-Eintraege aus der Liste entfernen, eth-Interfaces bleiben
    # unangetastet - ifplugd soll fuer Kabel-Link-Erkennung weiterlaufen,
    # nur nicht mehr fuer das von wlanmon-probe exklusiv gesteuerte
    # WLAN-Testinterface.
    python3 - "$cfg" <<'PYEOF'
import re, sys
path = sys.argv[1]
with open(path) as f:
    content = f.read()

def strip_wlan(match):
    ifaces = [i for i in match.group(1).split() if not i.startswith("wlan")]
    return 'HOTPLUG_INTERFACES="{}"'.format(" ".join(ifaces))

content = re.sub(r'HOTPLUG_INTERFACES="([^"]*)"', strip_wlan, content)
with open(path, "w") as f:
    f.write(content)
PYEOF
    log "New value: $(grep HOTPLUG_INTERFACES "$cfg")"

    if systemctl list-unit-files ifplugd.service 2>/dev/null | grep -q '^ifplugd.service'; then
        log "Restarting ifplugd.service so the new interface list takes effect ..."
        systemctl restart ifplugd.service 2>/dev/null || true
    fi
}

setup_arp_flux_sysctls() {
    local cfg="/etc/sysctl.d/99-wlanmon-probe.conf"
    log "Setting ARP flux sysctls (${cfg}) ..."
    cat > "$cfg" <<'EOF'
# wlanmon-probe: verhindert "ARP Flux", falls eth0 (Management-Uplink)
# und das per wlan0 getestete WLAN im selben Subnetz haengen - ohne das
# antwortet ggf. das falsche lokale Interface auf ARP-Requests fuer die
# jeweils andere Interface-IP, wodurch Antwortpakete am falschen
# Interface ankommen (klassisches Symptom: Ping zeigt 100% Verlust,
# obwohl Pakete nachweislich rausgehen).
net.ipv4.conf.all.arp_ignore=1
net.ipv4.conf.all.arp_announce=2
EOF
    sysctl --system >/dev/null
}

if [ "$SKIP_FOUNDATION_CLEANUP" -eq 1 ]; then
    log "--skip-foundation-cleanup set - leaving ifplugd and ARP sysctls untouched."
else
    log "=== Step 1: avoid conflicts with the Wi-Fi control ==="
    remove_wlan_from_ifplugd
    setup_arp_flux_sysctls
fi

# ---------------------------------------------------------------------
# 1.5 Klassische Interface-Namen erzwingen (net.ifnames=0)
# ---------------------------------------------------------------------
# Ohne das vergibt udev fuer USB-WLAN-Adapter oft die MAC-basierte Form
# (wlx<mac>) statt wlan0/wlan1 - je nach Adapter unterschiedlich, macht
# eine einheitliche config.yaml ueber mehrere Geraete hinweg unnoetig
# muehsam. Wirkt erst nach einem Reboot, daher hier nur setzen und am
# Ende des Skripts klar darauf hinweisen statt selbst zu rebooten.

ARMBIAN_ENV="/boot/armbianEnv.txt"
IFNAMES_JUST_SET=0

ensure_net_ifnames_disabled() {
    if [ ! -f "$ARMBIAN_ENV" ]; then
        log "$ARMBIAN_ENV not found - skipping net.ifnames (no Armbian boot setup; Raspberry Pi OS already uses wlan0/eth0)."
        return
    fi
    if grep -q 'net\.ifnames=0' "$ARMBIAN_ENV"; then
        log "net.ifnames=0 is already set."
        return
    fi

    cp "$ARMBIAN_ENV" "$ARMBIAN_ENV.bak.$(date +%Y%m%d%H%M%S)"
    python3 - "$ARMBIAN_ENV" <<'PYEOF'
import re, sys
path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()

pattern = re.compile(r'^extraargs=(.*)$')
for i, line in enumerate(lines):
    m = pattern.match(line.rstrip('\n'))
    if m:
        current = m.group(1).strip()
        new_value = (current + ' net.ifnames=0').strip() if current else 'net.ifnames=0'
        lines[i] = f'extraargs={new_value}\n'
        break
else:
    lines.append('extraargs=net.ifnames=0\n')

with open(path, 'w') as f:
    f.writelines(lines)
PYEOF
    log "Added net.ifnames=0 to $ARMBIAN_ENV (classic names like wlan0/eth0 instead of wlx<mac>) - takes effect after a reboot."
    IFNAMES_JUST_SET=1
}

# I2C-Bus 0 fuer das NanoHat-OLED (display.py, /dev/i2c-0): auf einem frischen
# Armbian ist das Overlay aus, das Display bleibt dann dunkel. Hier statt im
# Assistenten, weil es denselben Reboot wie net.ifnames=0 nutzt (der Assistent
# laeuft erst danach). Nur wenn das Board ein passendes Overlay mitbringt
# (<overlay_prefix>-i2c0.dtbo, z.B. sun50i-h5 beim NanoPi NEO2) - ohne Display
# schadet der aktive Bus nicht.
I2C_JUST_SET=0

ensure_i2c0_overlay() {
    # "return 0" statt nacktem "return": das uebernaehme den Exitcode des
    # fehlgeschlagenen Tests, und set -e beendete dann das ganze Skript
    # (2026-10-02 auf einem Raspberry Pi ohne armbianEnv.txt passiert).
    [ -f "$ARMBIAN_ENV" ] || return 0
    local prefix found
    prefix=$(sed -n 's/^overlay_prefix=//p' "$ARMBIAN_ENV" | head -n1)
    # Ausgabe statt Exitcode von ls auswerten: ls meldet einen Fehler, sobald
    # EINER der Pfade fehlt - beim NanoPi NEO2 liegt das Overlay nur unter
    # /boot/dtb/allwinner/overlay/, nicht unter /boot/dtb/overlay/.
    found=$( { [ -n "$prefix" ] && ls /boot/dtb/*/overlay/"$prefix"-i2c0.dtbo /boot/dtb/overlay/"$prefix"-i2c0.dtbo 2>/dev/null; } | head -n1 ) || true
    if [ -z "$found" ]; then
        log "No I2C0 overlay for this board - skipping (only needed for the NanoHat OLED)."
        return
    fi
    if grep -Eq '^overlays=(.*[[:space:]])?i2c0([[:space:]]|$)' "$ARMBIAN_ENV"; then
        log "I2C0 overlay is already enabled."
        return
    fi
    cp "$ARMBIAN_ENV" "$ARMBIAN_ENV.bak.$(date +%Y%m%d%H%M%S)"
    if grep -q '^overlays=' "$ARMBIAN_ENV"; then
        sed -i '/^overlays=/ s/[[:space:]]*$/ i2c0/; s/^overlays= /overlays=/' "$ARMBIAN_ENV"
    else
        echo "overlays=i2c0" >> "$ARMBIAN_ENV"
    fi
    log "Enabled the I2C0 overlay in $ARMBIAN_ENV (NanoHat OLED) - takes effect after a reboot."
    I2C_JUST_SET=1
}

log "=== Step 1.5: classic interface names, I2C for the OLED ==="
ensure_net_ifnames_disabled
ensure_i2c0_overlay

# ---------------------------------------------------------------------
# 2. wlanmon-probe installieren
# ---------------------------------------------------------------------

log "=== Step 2: install wlanmon-probe ==="

INSTALL_DIR="/opt/wlanmon-probe"
CONFIG_DIR="/etc/wlanmon-probe"
DATA_DIR="/var/lib/wlanmon-probe"
LOG_DIR="/var/log/wlanmon-probe"

mkdir -p "$INSTALL_DIR" "$CONFIG_DIR" "$DATA_DIR" "$LOG_DIR"

# Alle Module statt einer festen Liste - dieselbe Auswahl wie
# installable_files() in update_probe.py, damit neue Module ohne Zutun ankommen.
cp "$SCRIPT_DIR"/*.py "$SCRIPT_DIR"/wlan_watchdog.sh \
   "$SCRIPT_DIR"/requirements.txt "$SCRIPT_DIR"/VERSION "$INSTALL_DIR/"
# Captive-Portal-Login-Module (Paket)
mkdir -p "$INSTALL_DIR/portals"
cp "$SCRIPT_DIR"/portals/*.py "$INSTALL_DIR/portals/"
chmod +x "$INSTALL_DIR/wlan_watchdog.sh"

# Systempakete in einem Schritt - vorab braucht es nur git (zum Klonen).
# Fehlt nichts, wird auch kein "apt-get update" ausgefuehrt.
#   iw, wpasupplicant  WLAN-Steuerung der Tests
#   dhcpcd-base        DHCP-Client, nur wenn weder dhclient noch dhcpcd da ist
#                      (minimales Armbian Trixie hat keinen). Nur das Programm,
#                      kein eigener Dienst, der der Probe das Test-Interface
#                      streitig macht; dhclient (isc-dhcp-client) ist in Debian
#                      abgekuendigt und macht auf Raspberry Pi OS Probleme
#                      (DHCPDECLINE, siehe README).
#   iperf3             Durchsatzmessung (WLAN und LAN)
#   iputils-ping       Ping-Test nach dem Verbindungsaufbau
#   tcpdump            pcap fehlgeschlagener Tests (FailureCapture in wifi_ops.py)
#   python3-venv       eigenes venv, siehe unten
PKGS=()
command -v iw >/dev/null 2>&1 || PKGS+=(iw)
command -v wpa_supplicant >/dev/null 2>&1 || PKGS+=(wpasupplicant)
command -v dhclient >/dev/null 2>&1 || command -v dhcpcd >/dev/null 2>&1 || PKGS+=(dhcpcd-base)
command -v iperf3 >/dev/null 2>&1 || PKGS+=(iperf3)
command -v ping >/dev/null 2>&1 || PKGS+=(iputils-ping)
command -v tcpdump >/dev/null 2>&1 || PKGS+=(tcpdump)
# python3 -m venv scheitert ohne ensurepip erst mittendrin (und mit langer
# Fehlermeldung) - daher vorher pruefen.
python3 -c "import ensurepip" >/dev/null 2>&1 || PKGS+=(python3-venv)
if [ "${#PKGS[@]}" -gt 0 ]; then
    log "Installing packages: ${PKGS[*]} ..."
    # noninteractive: iperf3 fragt sonst per debconf, ob es als Dienst laufen
    # soll (Standard: nein - die Probe ist nur Client).
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${PKGS[@]}"
else
    log "All required packages are installed."
fi
# Raspberry Pi OS + dhclient: Connection-Tests scheitern dort mit DHCPDECLINE
# direkt nach dem DHCPACK (README, Abschnitt Voraussetzungen). Die Probe nimmt
# dhclient bevorzugt, wenn er da ist - daher nur warnen, nicht eigenmaechtig
# ein Systempaket entfernen.
if grep -qi "raspberry pi" /proc/device-tree/model 2>/dev/null && command -v dhclient >/dev/null 2>&1; then
    log "WARNING: dhclient on a Raspberry Pi - connection tests fail with it (DHCPDECLINE)."
    log "         Remove it, the probe then uses dhcpcd: sudo apt remove -y isc-dhcp-client"
    command -v dhcpcd >/dev/null 2>&1 || log "         Install dhcpcd first: sudo apt install -y dhcpcd-base"
fi

# Debian/Ubuntu ab Python 3.11 verweigern system-weite "pip install"
# (PEP 668, "externally-managed-environment") - daher eigenes venv statt
# --break-system-packages, das laesst das System-Python unangetastet.
VENV_DIR="$INSTALL_DIR/venv"
if [ ! -d "$VENV_DIR" ]; then
    log "Creating Python venv in $VENV_DIR ..."
    python3 -m venv "$VENV_DIR"
fi

"$VENV_DIR/bin/pip" install --upgrade pip -q
"$VENV_DIR/bin/pip" install -r "$INSTALL_DIR/requirements.txt"

CONFIG_IS_NEW=0
if [ ! -f "$CONFIG_DIR/config.yaml" ]; then
    cp "$SCRIPT_DIR/config.example.yaml" "$CONFIG_DIR/config.yaml"
    # Enthaelt nach dem Assistenten den API-Key (und spaeter ggf. Passwoerter).
    chmod 600 "$CONFIG_DIR/config.yaml"
    CONFIG_IS_NEW=1
    # auto_update.repo_dir vorbelegen: SCRIPT_DIR ist der Git-Checkout, von
    # dem aus dieses Skript laeuft - im Normalfall genau der richtige Pfad.
    # Nur bei einer frischen Config, nie bei einer bestehenden anfassen.
    sed -i "s#^\(\s*repo_dir:\).*#\1 \"$SCRIPT_DIR\"#" "$CONFIG_DIR/config.yaml"
    log "Created $CONFIG_DIR/config.yaml from the template."
else
    log "$CONFIG_DIR/config.yaml already exists - values stay until the wizard changes them."
fi

cp "$SCRIPT_DIR/wlanmon-probe.service" /etc/systemd/system/wlanmon-probe.service
cp "$SCRIPT_DIR/wlanmon-wifi-watchdog.service" /etc/systemd/system/wlanmon-wifi-watchdog.service
cp "$SCRIPT_DIR/wlanmon-wifi-watchdog.timer" /etc/systemd/system/wlanmon-wifi-watchdog.timer
cp "$SCRIPT_DIR/wlanmon-probe-update.service" /etc/systemd/system/wlanmon-probe-update.service
cp "$SCRIPT_DIR/wlanmon-probe-update.timer" /etc/systemd/system/wlanmon-probe-update.timer
systemctl daemon-reload
systemctl enable wlanmon-probe.service
# Bewusst die Timer aktivieren, nicht die Services direkt - beides sind
# "oneshot"-Services, die vom jeweiligen Timer periodisch angestossen
# werden. Watchdog UND Auto-Update greifen erst, wenn watchdog.enabled
# bzw. auto_update.enabled: true in config.yaml gesetzt ist (die Skripte
# beenden sich sonst sofort, ohne etwas zu tun).
systemctl enable --now wlanmon-wifi-watchdog.timer
systemctl enable --now wlanmon-probe-update.timer

# Werkzeugkasten (Logs, Dienste, Update, WLAN-Diagnose per Menue) als Befehl
# "wlanmon" - zeigt direkt in den Checkout, den das Auto-Update aktuell haelt.
chmod +x "$SCRIPT_DIR/wlanmon-tool.sh"
ln -sf "$SCRIPT_DIR/wlanmon-tool.sh" /usr/local/bin/wlanmon

# ---------------------------------------------------------------------
# 3. Einrichtungsassistent (config_wizard.py)
# ---------------------------------------------------------------------
# Nur in einem Terminal - bei automatisierten Installationen (Pipe,
# cloud-init, --no-wizard) bleibt es beim Hinweis am Ende. Nach dem ersten
# Setzen von net.ifnames=0 erst nach dem Neustart, weil sich die
# Interface-Namen dadurch noch aendern.

WIZARD_DONE=0
run_wizard() {
    log "=== Step 3: setup wizard ==="
    if "$VENV_DIR/bin/python3" "$SCRIPT_DIR/config_wizard.py" "$CONFIG_DIR/config.yaml"; then
        WIZARD_DONE=1
    else
        log "Wizard not completed - run it again later with: sudo wlanmon setup"
    fi
}

if [ "$WIZARD" != "no" ] && [ -t 0 ] && [ -t 1 ]; then
    if [ "$IFNAMES_JUST_SET" -eq 1 ]; then
        log "The setup wizard follows after the reboot (interface names are about to change)."
    elif [ "$WIZARD" = "yes" ] || [ "$CONFIG_IS_NEW" -eq 1 ]; then
        run_wizard
    else
        read -r -p "[setup_wlanmon_probe] Adjust the configuration with the wizard? [y/N] " answer || answer=""
        case "$answer" in j|J|y|Y) run_wizard ;; esac
    fi
fi

# ---------------------------------------------------------------------
# NetworkManager vom Test-Interface fernhalten
# ---------------------------------------------------------------------
# Raspberry Pi OS (und manche Armbian-Images) verwalten WLAN per
# NetworkManager; dessen eigener wpa_supplicant blockiert dann unseren
# ("nl80211: kernel reports: Match already configured", keine Assoziation).
# Dauerhaft als "unmanaged" eintragen - nur das Test-Interface aus
# config.yaml, und nicht, wenn es im NetworkManager verbunden ist (Uplink).
# Die Probe prueft das zusaetzlich bei jedem Start (wifi_ops.py).
ensure_networkmanager_ignores_test_iface() {
    systemctl is-active --quiet NetworkManager 2>/dev/null || return 0
    local iface conf state existing
    iface=$("$VENV_DIR/bin/python3" -c 'import sys, yaml; print(((yaml.safe_load(open(sys.argv[1])) or {}).get("interface") or {}).get("name") or "wlan0")' "$CONFIG_DIR/config.yaml" 2>/dev/null || echo wlan0)
    state=$(nmcli -t -f DEVICE,STATE device status 2>/dev/null | awk -F: -v d="$iface" '$1 == d {print $2}') || true
    if [ "$state" = "connected" ]; then
        log "WARNING: $iface is connected in NetworkManager (uplink?) - not taken over. The tests need a dedicated interface."
        return 0
    fi
    conf=/etc/NetworkManager/conf.d/99-wlanmon.conf
    # Bereits eingetragene Interfaces bleiben drin (z.B. der Onboard-Chip,
    # wenn die Tests auf einen USB-Stick umgezogen sind) - config_wizard.py
    # pflegt dieselbe Liste.
    existing=$(sed -n 's/^unmanaged-devices=//p' "$conf" 2>/dev/null | head -n 1)
    if printf ';%s;' "$existing" | grep -q ";interface-name:$iface;"; then
        log "NetworkManager already leaves $iface alone."
        return 0
    fi
    mkdir -p /etc/NetworkManager/conf.d
    printf '# WLANMON: test interface is controlled by the probe (setup_wlanmon_probe.sh)\n[keyfile]\nunmanaged-devices=%s\n' "${existing:+$existing;}interface-name:$iface" > "$conf"
    nmcli general reload conf >/dev/null 2>&1 || systemctl reload NetworkManager || true
    log "NetworkManager no longer manages $iface ($conf)."
}

ensure_networkmanager_ignores_test_iface

log ""
log "Installation complete."
if [ "$I2C_JUST_SET" -eq 1 ] && [ "$IFNAMES_JUST_SET" -eq 0 ]; then
    # Beim Erstlauf deckt der Reboot-Hinweis unten das schon ab.
    log "Note: the OLED display (I2C) only works after a reboot (sudo reboot)."
fi
if [ "$IFNAMES_JUST_SET" -eq 1 ]; then
    log ""
    log "IMPORTANT: net.ifnames=0 was just set and only takes effect after a"
    log "reboot (USB adapters are then called wlan0/wlan1 instead of wlx<mac>)."
    log "  1. sudo reboot"
    log "  2. sudo wlanmon setup    (setup wizard)"
elif [ "$WIZARD_DONE" -eq 1 ]; then
    start_now="j"
    if [ -t 0 ]; then
        read -r -p "[setup_wlanmon_probe] (Re)start the probe now? [Y/n] " start_now || start_now="n"
    fi
    case "${start_now:-j}" in
        j|J|y|Y)
            systemctl restart wlanmon-probe
            log "Probe started. First measurements appear in the dashboard after about a minute." ;;
        *) log "Start it with: sudo systemctl start wlanmon-probe" ;;
    esac
else
    log "Still to do: sudo wlanmon setup   (wizard for $CONFIG_DIR/config.yaml)"
    log "Then start it with: sudo systemctl start wlanmon-probe"
    WLAN_IFACES=$(ip -br link show 2>/dev/null | awk '{print $1}' | grep -E '^(wlan|wlx)' || true)
    if [ -z "$WLAN_IFACES" ]; then
        log "Note: no Wi-Fi interface found - is the USB Wi-Fi adapter plugged in? ('dmesg | grep -i usb')"
    fi
fi
log ""
log "Follow the logs:  sudo journalctl -u wlanmon-probe -f"
log "Toolbox:          sudo wlanmon   (logs, services, update, Wi-Fi diagnostics, setup)"
