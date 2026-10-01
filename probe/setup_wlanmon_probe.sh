#!/usr/bin/env bash
# Provisioning-Script fuer wlanmon-probe auf dem NanoPi NEO2/NEO3.
#
# Aufgaben in einem Lauf:
#   1. Bekannte Konflikt-Dienste aus dem WLANPi-Fundament deaktivieren
#      (nachgewiesen: ifplugd verwaltet wlan0 im Wireless-Hotplug-Modus
#      und kollidiert mit der direkten wpa_supplicant/iw-Steuerung in
#      wifi_ops.py; ausserdem die ARP-Flux-Sysctls fuer den Fall, dass
#      eth0 und das WLAN-Testnetz im selben Subnetz haengen).
#   1.5 net.ifnames=0 setzen, damit USB-WLAN-Adapter ueber Geraete hinweg
#      konsistent wlan0/wlan1 statt der MAC-basierten Form (wlx<mac>)
#      bekommen - wirkt erst nach einem Reboot.
#   2. wlanmon-probe selbst installieren/aktualisieren (Dateien, Python-
#      Abhaengigkeiten, systemd-Service). Am Ende wird das erkannte
#      WLAN-Interface ausgegeben, das in config.yaml unter
#      interface.name eingetragen werden muss.
#
# Alle Deaktivierungen sind reversibel (disable/mask statt purge), damit
# das Script sowohl auf einem bestehenden WLANPi-Image als Migrations-
# schritt laeuft als auch - ohne Fehler - auf einem bereits sauberen,
# rein fuer wlanmon-probe aufgesetzten Armbian-Image (die betroffenen
# Units existieren dort schlicht nicht, die jeweiligen Schritte werden
# dann uebersprungen).
#
# Aufruf: sudo ./setup_wlanmon_probe.sh [--skip-foundation-cleanup]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
SKIP_FOUNDATION_CLEANUP=0
for arg in "$@"; do
    [ "$arg" = "--skip-foundation-cleanup" ] && SKIP_FOUNDATION_CLEANUP=1
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte mit sudo/root ausfuehren." >&2
    exit 1
fi

log() { echo "[setup_wlanmon_probe] $*"; }

# ---------------------------------------------------------------------
# 1. WLANPi-Fundament: bekannte Konflikt-Dienste deaktivieren
# ---------------------------------------------------------------------

disable_unit_if_present() {
    local unit="$1"
    if systemctl list-unit-files "$unit" 2>/dev/null | grep -q "^$unit"; then
        log "Deaktiviere $unit (stop + disable + mask) ..."
        systemctl stop "$unit" 2>/dev/null || true
        systemctl disable "$unit" 2>/dev/null || true
        systemctl mask "$unit" 2>/dev/null || true
    else
        log "$unit nicht vorhanden - ueberspringe (bereits sauberes Image?)."
    fi
}

remove_wlan_from_ifplugd() {
    local cfg="/etc/default/ifplugd"
    if [ ! -f "$cfg" ]; then
        log "ifplugd-Konfiguration ($cfg) nicht vorhanden - ueberspringe."
        return
    fi
    if ! grep -q 'HOTPLUG_INTERFACES=.*wlan' "$cfg"; then
        log "ifplugd ueberwacht laut $cfg kein WLAN-Interface - keine Aenderung noetig."
        return
    fi

    log "Entferne wlan0/wlan1 aus ifplugds HOTPLUG_INTERFACES in $cfg ..."
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
    log "Neuer Inhalt: $(grep HOTPLUG_INTERFACES "$cfg")"

    if systemctl list-unit-files ifplugd.service 2>/dev/null | grep -q '^ifplugd.service'; then
        log "Starte ifplugd.service neu, damit die neue Interface-Liste greift ..."
        systemctl restart ifplugd.service 2>/dev/null || true
    fi
}

setup_arp_flux_sysctls() {
    local cfg="/etc/sysctl.d/99-wlanmon-probe.conf"
    log "Setze ARP-Flux-Sysctls (${cfg}) ..."
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
    log "--skip-foundation-cleanup gesetzt - WLANPi-Fundament wird nicht angefasst."
else
    log "=== Schritt 1: WLANPi-Fundament bereinigen ==="
    disable_unit_if_present fpms.service
    disable_unit_if_present networkinfo.service
    disable_unit_if_present wlanpi_webui.service
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
        log "$ARMBIAN_ENV nicht gefunden - ueberspringe net.ifnames-Anpassung (kein Armbian-Bootsetup erkannt, ggf. manuell pruefen)."
        return
    fi
    if grep -q 'net\.ifnames=0' "$ARMBIAN_ENV"; then
        log "net.ifnames=0 ist bereits gesetzt."
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
    log "net.ifnames=0 zu $ARMBIAN_ENV hinzugefuegt (klassische Namen wie wlan0/eth0 statt wlx<mac>) - wirkt erst nach Reboot."
    IFNAMES_JUST_SET=1
}

log "=== Schritt 1.5: klassische Interface-Namen erzwingen ==="
ensure_net_ifnames_disabled

# ---------------------------------------------------------------------
# 2. wlanmon-probe installieren
# ---------------------------------------------------------------------

log "=== Schritt 2: wlanmon-probe installieren ==="

INSTALL_DIR="/opt/wlanmon-probe"
CONFIG_DIR="/etc/wlanmon-probe"
DATA_DIR="/var/lib/wlanmon-probe"
LOG_DIR="/var/log/wlanmon-probe"

mkdir -p "$INSTALL_DIR" "$CONFIG_DIR" "$DATA_DIR" "$LOG_DIR"

cp "$SCRIPT_DIR"/main.py "$SCRIPT_DIR"/wifi_ops.py "$SCRIPT_DIR"/sender.py \
   "$SCRIPT_DIR"/queue_store.py "$SCRIPT_DIR"/config_manager.py \
   "$SCRIPT_DIR"/probe_status.py "$SCRIPT_DIR"/display.py \
   "$SCRIPT_DIR"/bound_http.py "$SCRIPT_DIR"/wlan_watchdog.sh \
   "$SCRIPT_DIR"/requirements.txt "$SCRIPT_DIR"/VERSION \
   "$SCRIPT_DIR"/update_probe.py "$INSTALL_DIR/"
# Captive-Portal-Login-Module (Paket)
mkdir -p "$INSTALL_DIR/portals"
cp "$SCRIPT_DIR"/portals/*.py "$INSTALL_DIR/portals/"
chmod +x "$INSTALL_DIR/wlan_watchdog.sh"

# Debian/Ubuntu ab Python 3.11 verweigern system-weite "pip install"
# (PEP 668, "externally-managed-environment") - daher eigenes venv statt
# --break-system-packages, das laesst das System-Python unangetastet.
VENV_DIR="$INSTALL_DIR/venv"
if [ ! -d "$VENV_DIR" ]; then
    log "Lege Python-venv unter $VENV_DIR an ..."
    if ! python3 -m venv "$VENV_DIR" 2>/dev/null; then
        log "python3-venv fehlt - installiere nach ..."
        apt-get update -qq && apt-get install -y -qq python3-venv
        python3 -m venv "$VENV_DIR"
    fi
fi
"$VENV_DIR/bin/pip" install --upgrade pip -q
"$VENV_DIR/bin/pip" install -r "$INSTALL_DIR/requirements.txt"

if [ ! -f "$CONFIG_DIR/config.yaml" ]; then
    cp "$SCRIPT_DIR/config.example.yaml" "$CONFIG_DIR/config.yaml"
    # auto_update.repo_dir vorbelegen: SCRIPT_DIR ist der Git-Checkout, von
    # dem aus dieses Skript laeuft - im Normalfall genau der richtige Pfad.
    # Nur bei einer frischen Config, nie bei einer bestehenden anfassen.
    sed -i "s#^\(\s*repo_dir:\).*#\1 \"$SCRIPT_DIR\"#" "$CONFIG_DIR/config.yaml"
    log "$CONFIG_DIR/config.yaml aus Vorlage angelegt - bitte anpassen"
    log "  (device.id, interface.name, server.url, server.api_key, Ziel-SSIDs)."
else
    log "$CONFIG_DIR/config.yaml existiert bereits - unveraendert gelassen."
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

log ""
log "Installation abgeschlossen."
log "Vor dem Start pruefen: $CONFIG_DIR/config.yaml"
log "Danach starten mit:    sudo systemctl start wlanmon-probe"
log "Logs verfolgen mit:    sudo journalctl -u wlanmon-probe -f"
log "Werkzeugkasten:        sudo wlanmon   (Logs, Dienste, Update, WLAN-Diagnose)"
log ""
log "WLAN-Watchdog (Auto-Reboot bei dauerhaft fehlendem Interface) ist"
log "installiert, aber per Default AUS (watchdog.enabled: false in"
log "config.yaml). Zum Aktivieren dort auf true setzen."
log ""
log "Automatisches Update aus dem Git-Repo ist installiert, aber per"
log "Default AUS (auto_update.enabled: false in config.yaml). Zum"
log "Aktivieren dort auf true setzen - repo_dir ist bereits auf"
log "$SCRIPT_DIR vorbelegt."

log ""
log "=== WLAN-Interface(s) erkannt ==="
WLAN_IFACES=$(ip -br link show 2>/dev/null | awk '{print $1}' | grep -E '^(wlan|wlx)' || true)
if [ -n "$WLAN_IFACES" ]; then
    echo "$WLAN_IFACES" | while read -r iface; do log "  - $iface"; done
else
    log "  Keins gefunden - USB-WLAN-Adapter gesteckt? Treiber geladen? ('dmesg | grep -i usb' pruefen.)"
fi
log "Passenden Namen in $CONFIG_DIR/config.yaml unter interface.name eintragen."

if [ "$IFNAMES_JUST_SET" -eq 1 ]; then
    log ""
    log "ACHTUNG: net.ifnames=0 wurde gerade erst gesetzt und wirkt erst nach"
    log "einem Neustart - der oben gezeigte Interface-Name gilt nur bis dahin."
    log "Nach 'sudo reboot' den Namen erneut pruefen: ip -br link"
fi
