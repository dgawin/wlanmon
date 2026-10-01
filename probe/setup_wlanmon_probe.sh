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
    echo "Bitte mit sudo/root ausfuehren." >&2
    exit 1
fi

log() { echo "[setup_wlanmon_probe] $*"; }

# ---------------------------------------------------------------------
# 1. Konflikte mit der WLAN-Steuerung vermeiden
# ---------------------------------------------------------------------

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
    log "--skip-foundation-cleanup gesetzt - ifplugd und ARP-Sysctls werden nicht angefasst."
else
    log "=== Schritt 1: Konflikte mit der WLAN-Steuerung vermeiden ==="
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
    # Ausgabe unterdruecken: ohne python3-venv (minimales Armbian) meldet
    # venv sonst eine lange "ensurepip is not available"-Fehlermeldung,
    # obwohl das Skript das Paket gleich selbst nachinstalliert. Das halb
    # angelegte venv vor dem zweiten Versuch wegraeumen.
    if ! python3 -m venv "$VENV_DIR" >/dev/null 2>&1; then
        log "python3-venv fehlt - installiere nach ..."
        rm -rf "$VENV_DIR"
        apt-get update -qq && apt-get install -y -qq python3-venv
        python3 -m venv "$VENV_DIR"
    fi
fi
# DHCP-Client fuer die Connection-Tests: minimales Armbian (Trixie) hat weder
# dhclient noch dhcpcd. dhcpcd-base bringt nur das Programm, keinen eigenen
# Dienst, der dem Probe das Test-Interface streitig machen wuerde; dhclient
# (isc-dhcp-client) ist in Debian abgekuendigt und macht auf Raspberry Pi OS
# Probleme (DHCPDECLINE, siehe README).
if ! command -v dhclient >/dev/null 2>&1 && ! command -v dhcpcd >/dev/null 2>&1; then
    log "Installiere DHCP-Client (dhcpcd-base) ..."
    apt-get install -y -qq dhcpcd-base >/dev/null 2>&1 \
        || { apt-get update -qq && apt-get install -y -qq dhcpcd-base; } \
        || log "WARNUNG: kein DHCP-Client installierbar - Connection-Tests schlagen fehl (dhclient oder dhcpcd noetig)."
fi
# tcpdump fuer den Mitschnitt fehlgeschlagener Connection-Tests (pcap, siehe
# FailureCapture in wifi_ops.py). Ohne laeuft alles weiter, nur ohne pcap.
if ! command -v tcpdump >/dev/null 2>&1; then
    log "Installiere tcpdump (Mitschnitt fehlgeschlagener Tests) ..."
    apt-get install -y -qq tcpdump >/dev/null 2>&1 \
        || { apt-get update -qq && apt-get install -y -qq tcpdump; } \
        || log "tcpdump liess sich nicht installieren - Mitschnitte dann ohne pcap."
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
    log "$CONFIG_DIR/config.yaml aus Vorlage angelegt."
else
    log "$CONFIG_DIR/config.yaml existiert bereits - Werte bleiben, bis der Assistent sie aendert."
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
    log "=== Schritt 3: Einrichtungsassistent ==="
    if "$VENV_DIR/bin/python3" "$SCRIPT_DIR/config_wizard.py" "$CONFIG_DIR/config.yaml"; then
        WIZARD_DONE=1
    else
        log "Assistent nicht abgeschlossen - spaeter erneut mit: sudo wlanmon setup"
    fi
}

if [ "$WIZARD" != "no" ] && [ -t 0 ] && [ -t 1 ]; then
    if [ "$IFNAMES_JUST_SET" -eq 1 ]; then
        log "Einrichtungsassistent folgt nach dem Neustart (Interface-Namen aendern sich noch)."
    elif [ "$WIZARD" = "yes" ] || [ "$CONFIG_IS_NEW" -eq 1 ]; then
        run_wizard
    else
        read -r -p "[setup_wlanmon_probe] Konfiguration mit dem Assistenten anpassen? [j/N] " answer || answer=""
        case "$answer" in j|J|y|Y) run_wizard ;; esac
    fi
fi

log ""
log "Installation abgeschlossen."
if [ "$IFNAMES_JUST_SET" -eq 1 ]; then
    log ""
    log "ACHTUNG: net.ifnames=0 wurde gerade gesetzt und wirkt erst nach einem"
    log "Neustart (danach heissen USB-Adapter wlan0/wlan1 statt wlx<mac>)."
    log "  1. sudo reboot"
    log "  2. sudo wlanmon setup    (Einrichtungsassistent)"
elif [ "$WIZARD_DONE" -eq 1 ]; then
    start_now="j"
    if [ -t 0 ]; then
        read -r -p "[setup_wlanmon_probe] Probe jetzt (neu) starten? [J/n] " start_now || start_now="n"
    fi
    case "${start_now:-j}" in
        j|J|y|Y)
            systemctl restart wlanmon-probe
            log "Probe gestartet. Erste Messungen erscheinen nach etwa einer Minute im Dashboard." ;;
        *) log "Starten mit: sudo systemctl start wlanmon-probe" ;;
    esac
else
    log "Noch einzurichten: sudo wlanmon setup   (Assistent fuer $CONFIG_DIR/config.yaml)"
    log "Danach starten mit: sudo systemctl start wlanmon-probe"
    WLAN_IFACES=$(ip -br link show 2>/dev/null | awk '{print $1}' | grep -E '^(wlan|wlx)' || true)
    if [ -z "$WLAN_IFACES" ]; then
        log "Hinweis: kein WLAN-Interface gefunden - USB-WLAN-Adapter gesteckt? ('dmesg | grep -i usb')"
    fi
fi
log ""
log "Logs verfolgen mit: sudo journalctl -u wlanmon-probe -f"
log "Werkzeugkasten:     sudo wlanmon   (Logs, Dienste, Update, WLAN-Diagnose, Einrichtung)"
