<p align="center">
  <img src="branding/wlanmon-logo.svg" alt="wlanmon" width="420">
</p>

# wlanmon

wlanmon überwacht WLAN-Qualität und -Erreichbarkeit an mehreren Standorten.
Kleine Linux-Geräte (NanoPi, Raspberry Pi) scannen periodisch die WLAN-Umgebung
und testen Verbindungen zu konfigurierten SSIDs (Verbindungsaufbau, DHCP,
Erreichbarkeit, optional iperf3). Die Ergebnisse landen in einem zentralen
Web-Dashboard mit Verlauf, Spektrumansicht und Alarmierung.

| Ordner | Inhalt |
|---|---|
| [`probe/`](probe/) | Probe-Client (Python) für die Messgeräte, inkl. Setup-Skript und Auto-Update – siehe [probe/README.md](probe/README.md) |
| [`dashboard/`](dashboard/) | Server und Web-Dashboard (PHP + MySQL/MariaDB), klassisch oder als Docker-Stack – siehe [dashboard/README.md](dashboard/README.md) |
| [`branding/`](branding/) | Logo und Icon (SVG) |

## Schnellstart

1. Dashboard aufsetzen – am einfachsten als Docker-Stack, siehe
   [dashboard/README.md, Abschnitt „Docker“](dashboard/README.md#docker).
2. Im Dashboard ein Gerät anlegen; es liefert die fertige `server`-Konfiguration
   samt API-Key für die Probe.
3. Probe einrichten:

   ```bash
   git clone https://github.com/dgawin/wlanmon.git ~/wlanmon
   cd ~/wlanmon/probe
   sudo ./setup_wlanmon_probe.sh
   ```

   Details (Hardware, WLAN-Adapter, Konfiguration) in
   [probe/README.md](probe/README.md).

## Branches

- `main` – aktueller Entwicklungsstand
- `stable` – freigegebener Stand; die Auto-Updates von Probe und Dashboard
  ziehen standardmäßig von hier

## Lizenz

MIT – siehe [LICENSE](LICENSE). Name und Logo „wlanmon“ sind von der Lizenz
nicht umfasst.
