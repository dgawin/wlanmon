#!/usr/bin/env bash
# WLAN-Watchdog fuer wlanmon-probe.
#
# Rebootet das Geraet automatisch, wenn das konfigurierte WLAN-Test-
# Interface laenger als watchdog.missing_threshold_minutes komplett aus
# dem Kernel verschwunden ist.
#
# Hintergrund (siehe README.md, Abschnitt "USB-Hotplug-Einschraenkung"):
# der mt7921u-USB-WLAN-Treiber kann nach einem Hot-Unplug/-Replug in
# einen irreparablen Zustand geraten (auf einem NanoPi NEO2 live
# reproduziert: Firmware-Ladefehler nach Enumeration auf dem falschen
# USB-Companion-Controller, danach bleibt der Chip auch nach erneutem
# Einstecken tot). Unbind/Bind hilft in diesem Zustand nachweislich
# nicht mehr - nur ein voller Neustart bringt den Adapter zuverlaessig
# zurueck. Ohne diesen Watchdog bliebe ein Feldgeraet nach einem
# versehentlich gewackelten USB-Stecker dauerhaft offline, bis jemand
# physisch vor Ort eingreift.
#
# Laeuft als eigener systemd-Timer (wlanmon-wifi-watchdog.timer),
# bewusst UNABHAENGIG vom wlanmon-probe-Service selbst - der Watchdog
# soll auch dann noch funktionieren, wenn der eigentliche Probe-Prozess
# haengt oder abgestuerzt ist.

set -uo pipefail

CONFIG_FILE="/etc/wlanmon-probe/config.yaml"
STATE_FILE="/var/lib/wlanmon-probe/wlan_watchdog_last_seen"
VENV_PYTHON="/opt/wlanmon-probe/venv/bin/python3"

log() {
    logger -t wlanmon-wifi-watchdog "$*" 2>/dev/null
    echo "$*"
}

if [ ! -f "$CONFIG_FILE" ]; then
    log "Config $CONFIG_FILE nicht gefunden - Watchdog uebersprungen."
    exit 0
fi
if [ ! -x "$VENV_PYTHON" ]; then
    log "venv-Python ($VENV_PYTHON) nicht gefunden - Watchdog uebersprungen."
    exit 0
fi

is_enabled() {
    "$VENV_PYTHON" -c "
import yaml
cfg = yaml.safe_load(open('$CONFIG_FILE')) or {}
print('yes' if (cfg.get('watchdog') or {}).get('enabled') else 'no')
" 2>/dev/null
}

get_interface() {
    "$VENV_PYTHON" -c "
import yaml
cfg = yaml.safe_load(open('$CONFIG_FILE')) or {}
print(cfg['interface']['name'])
" 2>/dev/null
}

get_threshold_seconds() {
    "$VENV_PYTHON" -c "
import yaml
cfg = yaml.safe_load(open('$CONFIG_FILE')) or {}
minutes = (cfg.get('watchdog') or {}).get('missing_threshold_minutes', 10)
print(int(minutes) * 60)
" 2>/dev/null
}

if [ "$(is_enabled)" != "yes" ]; then
    exit 0
fi

IFACE=$(get_interface)
if [ -z "$IFACE" ]; then
    log "interface.name konnte nicht aus $CONFIG_FILE gelesen werden - Watchdog uebersprungen."
    exit 0
fi

THRESHOLD_SECONDS=$(get_threshold_seconds)
if [ -z "$THRESHOLD_SECONDS" ]; then
    THRESHOLD_SECONDS=600
fi

mkdir -p "$(dirname "$STATE_FILE")"
now=$(date +%s)

if [ -e "/sys/class/net/$IFACE" ]; then
    echo "$now" > "$STATE_FILE"
    exit 0
fi

if [ ! -f "$STATE_FILE" ]; then
    # Erster Check ueberhaupt (oder nach Neustart) ohne vorhandene
    # Baseline - erstmal nur Zeitstempel setzen statt sofort so zu tun,
    # als fehle das Interface schon ewig.
    echo "$now" > "$STATE_FILE"
    log "Interface $IFACE fehlt, Baseline gesetzt (kein sofortiger Reboot)."
    exit 0
fi

last_seen=$(cat "$STATE_FILE" 2>/dev/null)
if ! [[ "$last_seen" =~ ^[0-9]+$ ]]; then
    # State-Datei leer/korrupt - Baseline sicherheitshalber neu setzen,
    # statt aus einem ungueltigen Wert einen riesigen "missing_for"-Wert
    # zu errechnen und faelschlich sofort zu rebooten.
    echo "$now" > "$STATE_FILE"
    log "State-Datei $STATE_FILE ungueltig, Baseline neu gesetzt."
    exit 0
fi

missing_for=$((now - last_seen))

log "Interface $IFACE fehlt seit ${missing_for}s (Schwelle: ${THRESHOLD_SECONDS}s)."

if [ "$missing_for" -ge "$THRESHOLD_SECONDS" ]; then
    log "Schwelle ueberschritten - starte Geraet neu (systemctl reboot)."
    systemctl reboot
fi
