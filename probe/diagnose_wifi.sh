#!/usr/bin/env bash
# Diagnose-Helfer fuer das Ping-0%-Empfangen-Problem trotz erfolgreicher
# Assoziation/DHCP: das Test-Interface ist nur wenige Sekunden pro
# Connection-Test verbunden - zu kurz, um Befehle manuell einzutippen.
# Dieses Skript wartet aktiv (0.2s-Polling) darauf, dass <iface> eine
# IPv4-Adresse bekommt (also waehrend wlanmon-probe gerade seinen eigenen
# Test faehrt), schnappt sich sofort Routing-/rp_filter-Zustand und
# schneidet parallel per tcpdump mit, ob ICMP-Antworten auf dem Interface
# ankommen - unabhaengig davon, ob `ping` sie als "empfangen" zaehlt.
#
# Aufruf (als root/sudo, wegen tcpdump):
#   sudo ./diagnose_wifi.sh [interface] [ausgabe-verzeichnis] [--once]
#
# Standardmaessig laeuft es in einer Endlosschleife und legt bei jedem
# Connection-Test-Fenster einen neuen Zeitstempel-Log/Capture an - damit
# du es einfach starten und liegen lassen kannst, bis der naechste
# automatische Test (config.yaml: connection_tests.interval_seconds)
# durchlaeuft. Mit --once beendet es sich nach der ersten Aufzeichnung.

set -uo pipefail

IFACE="${1:-wlan0}"
OUTDIR="${2:-/tmp/wlan-diag}"
ONCE=0
for arg in "$@"; do
    [ "$arg" = "--once" ] && ONCE=1
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte mit sudo/root ausfuehren (tcpdump braucht Root-Rechte)." >&2
    exit 1
fi

if ! command -v tcpdump >/dev/null 2>&1; then
    echo "tcpdump ist nicht installiert (sudo apt install tcpdump)." >&2
    exit 1
fi

mkdir -p "$OUTDIR"

capture_once() {
    echo "[$(date -Is)] Warte auf IPv4 auf $IFACE ..."
    local ip=""
    while true; do
        ip=$(ip -4 -o addr show dev "$IFACE" 2>/dev/null | awk '{print $4}' | cut -d/ -f1)
        [ -n "$ip" ] && break
        sleep 0.2
    done

    local ts log pcap
    ts=$(date +%Y%m%d-%H%M%S)
    log="$OUTDIR/diag-$ts.log"
    pcap="$OUTDIR/diag-$ts.pcap"
    echo "[$(date -Is)] IP erkannt: $ip -> $log"

    {
        echo "== $(date -Is) - $IFACE hat $ip =="
        echo
        echo "== ip addr show $IFACE =="
        ip addr show "$IFACE"
        echo
        echo "== ip route show =="
        ip route show
        echo
        echo "== ip route show table all =="
        ip route show table all
        echo
        echo "== ip rule show =="
        ip rule show
        echo
        echo "== rp_filter =="
        sysctl net.ipv4.conf.all.rp_filter net.ipv4.conf.default.rp_filter "net.ipv4.conf.$IFACE.rp_filter" 2>&1
    } > "$log" 2>&1

    # 20s ICMP auf dem Interface mitschneiden - reicht, um den kompletten
    # Ping-Teil eines Connection-Tests (Standard: 5 Pakete, 2s Timeout je
    # Paket) abzudecken, egal ob wlanmon-probe oder wir selbst pingen.
    echo "[$(date -Is)] Schneide 20s ICMP auf $IFACE mit ..."
    timeout 20 tcpdump -i "$IFACE" -n icmp -w "$pcap" 2>>"$log"

    {
        echo
        echo "== Inhalt des Mitschnitts (tcpdump -r) =="
        tcpdump -r "$pcap" -n 2>&1
    } >> "$log"

    echo "[$(date -Is)] Fertig: $log"
    echo "---"
    cat "$log"
    echo "==="
}

if [ "$ONCE" -eq 1 ]; then
    capture_once
else
    while true; do
        capture_once
        # Kurze Pause, damit wir nicht sofort dieselbe (noch nicht ganz
        # abgebaute) Adresse erneut als "neue" Verbindung werten.
        sleep 3
    done
fi
