# WLANMON-Probe – Client

Scannt WLAN-Netze und führt periodische Verbindungstests gegen konfigurierte
SSIDs durch. Ergebnisse werden lokal gepuffert (SQLite) und per HTTPS an
einen zentralen Server übertragen.

Ehemals "wlanpi-probe" auf Basis des WLANPi-Images – läuft jetzt auf einem
eigenen, schlanken Armbian-Image für den NanoPi NEO2 ohne die
WLANPi-eigene Netzwerk-/UI-Tooling (siehe
[`setup_wlanmon_probe.sh`](setup_wlanmon_probe.sh)), da einzelne
WLANPi-Dienste (v.a. `ifplugd` mit `wlan0` im Hotplug-Modus) nachweislich
mit der direkten `wpa_supplicant`/`iw`-Steuerung hier kollidiert haben.

## Voraussetzungen

- Debian/Armbian-basiertes System auf dem NanoPi NEO2 (kein WLANPi-Image nötig)
- USB-WLAN-Stick (z. B. Comfast CF-953AX, Treiber `mt7921u`, Kernel ≥ 5.19)
- Root-Rechte (für `iw`, `wpa_supplicant`, `dhclient`)
- Pakete: `iw`, `wpasupplicant`, `isc-dhcp-client` (oder `dhcpcd5`), `iputils-ping`,
  `python3-venv`, optional `iperf3`. `_run_dhcp()` in `wifi_ops.py` bevorzugt
  `dhclient`, faellt automatisch auf `dhcpcd` zurueck, falls `dhclient` nicht
  im PATH liegt (`shutil.which()`) - `_cleanup_connection()` spiegelt dieselbe
  Wahl, damit zum Freigeben der Lease immer der tatsaechlich benutzte Client
  aufgerufen wird (`dhclient -r` bzw. `dhcpcd -k`).

  **Bekannte DHCP-Eigenheiten, live auf mehreren Geraeten beobachtet und
  inzwischen abgefedert:**
  - `dhclient` versucht bei einer im Lease-File gespeicherten alten Lease
    per "INIT-REBOOT" (direktes DHCPREQUEST statt frischem DISCOVER)
    diese wiederzuverwenden - nach einem AP-Wechsel (Roaming auf
    dieselbe SSID, andere BSSID) fuehrte das zuverlaessig zu einem
    DHCP-Timeout (Dashboard-Historie: ein Geraet lief sauber verbunden,
    scheiterte direkt nach dem naechsten Roaming-Event). `_run_dhcp()`
    nutzt deshalb ein eigenes, vor jedem Versuch geleertes Lease-File
    (`-lf`) statt des System-Lease-Files, damit jeder Connection-Test
    tatsaechlich mit einem frischen DISCOVER startet - `-r` beim
    Aufraeumen zeigt bewusst auf dieselbe Datei.
  - `dhcpcd` weicht ohne `-L`/`--noipv4ll` nach ein paar Sekunden ohne
    DHCP-Antwort auf eine selbst vergebene IPv4LL-Adresse (169.254.x.x,
    RFC 3927 "Zeroconf") aus und `_run_dhcp()` haette das faelschlich
    als Erfolg gewertet (live beobachtet: `connected=True` mit
    `ip=169.254.x.x`, der anschliessende iperf3-Test lief dann prompt in
    einen Timeout, weil 169.254.x.x nicht ins eigentliche Netz routen
    kann) - zusaetzlich zu `-L` prueft `_run_dhcp()` das auch explizit
    gegen den Praefix, falls eine IPv4LL-Adresse doch auf anderem Weg
    zustande kommt.
  - **Raspberry Pi 3 und 5 (Raspberry Pi OS / Debian Trixie): `dhclient`
    deinstallieren.** Mit `isc-dhcp-client` (`dhclient 4.4.3`) scheiterten
    Connection-Tests dort konsequent mit `DHCPDECLINE` direkt nach dem
    `DHCPACK` (die frisch zugewiesene IP wird sofort wieder abgelehnt),
    teils auch mit "Network is down" waehrend der DHCP-Phase - auch bei
    ruhigem Netz und starkem Signal, mit dem internen WLAN-Chip genauso wie
    mit USB-Sticks. Ohne `dhclient` weicht die Probe automatisch auf
    `dhcpcd` aus (siehe oben) und laeuft sauber:

    ```bash
    sudo apt remove -y isc-dhcp-client
    sudo systemctl restart wlanmon-probe
    ```

    Auf den NanoPi-Geraeten (Armbian) laeuft `dhclient` dagegen fehlerfrei.
    Die eigentliche Ursache des `DHCPDECLINE` auf den Raspberry-Pi-Images
    ist nicht geklaert (bekannt ist nur: `dhclient` lehnt ab, `dhcpcd` im
    selben Netz nicht) - bei einem neuen Raspberry Pi also direkt nach der
    Installation entfernen, statt erst auf die Fehlschlaege zu warten.
    Nicht mit einem anderen, davon unabhaengigen Fehlerbild verwechseln:
    "Failed to allocate an IPv4 address" im Kea-Log von pfSense bedeutet
    einen zu kleinen DHCP-Pool (dort den Adressbereich vergroessern), nicht
    diesen `dhclient`-Effekt.
- Python ≥ 3.10

## Installation

Automatisiert (empfohlen, siehe [`setup_wlanmon_probe.sh`](setup_wlanmon_probe.sh)
für Details und Voraussetzungen):

```bash
sudo ./setup_wlanmon_probe.sh
```

Manuell:

```bash
sudo mkdir -p /opt/wlanmon-probe /etc/wlanmon-probe /var/lib/wlanmon-probe /var/log/wlanmon-probe
sudo cp main.py wifi_ops.py sender.py queue_store.py config_manager.py \
        probe_status.py display.py bound_http.py requirements.txt /opt/wlanmon-probe/
sudo mkdir -p /opt/wlanmon-probe/portals
sudo cp portals/*.py /opt/wlanmon-probe/portals/

# Eigenes venv statt system-weitem pip install - moderne Debian/Ubuntu-
# Versionen (PEP 668, "externally-managed-environment") verweigern
# sonst die Installation.
sudo python3 -m venv /opt/wlanmon-probe/venv
sudo /opt/wlanmon-probe/venv/bin/pip install -r /opt/wlanmon-probe/requirements.txt

sudo cp config.example.yaml /etc/wlanmon-probe/config.yaml
sudo nano /etc/wlanmon-probe/config.yaml   # Interface, Server-URL, API-Key, Ziel-SSIDs anpassen

sudo cp wlanmon-probe.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wlanmon-probe
sudo journalctl -u wlanmon-probe -f
```

## Werkzeugkasten (`sudo wlanmon`)

Menü für die Befehle, die man auf einer Probe immer wieder braucht (Oberfläche
auf Englisch):

- **Status:** Version (installiert/Checkout), Branch, Dienste, letzter Update-Lauf,
  WLAN-Interface/MAC/Land, IP-Adressen, noch nicht gesendete Messungen
- **Logs:** Probe-Log live oder die letzten Zeilen, nur Warnungen/Fehler,
  Connection-Test-Ergebnisse, Updater- und Watchdog-Log
- **Dienste:** Probe neu starten/stoppen/starten, Status aller Dienste und Timer
- **Update:** jetzt ausführen, letzter Stand, Version und Git-Stand vergleichen,
  Reparatur mit dem `update_probe.py` aus dem Checkout (falls die installierte Kopie
  defekt ist)
- **WLAN-Diagnose:** Adapter, Ländereinstellung, Bänder/Kanäle, Scan als Tabelle
  (alle Netze, 2,4 GHz aktiv/passiv), SSID suchen, Kanalbelegung, `diagnose_wifi.sh`
- **Konfiguration:** config.yaml anzeigen (Schlüssel maskiert) und bearbeiten (mit
  YAML-Prüfung und Neustart), zentrale Konfiguration vom Dashboard (Passwörter maskiert)
- **Netzwerk/Server:** Adressen/Routen, Server erreichbar, Warteschlange, Uhrzeit/NTP
- **System:** Speicher/Temperatur, Neustart, Herunterfahren

Direkt ohne Menü: `sudo wlanmon status`, `log` (live), `update-log`, `restart`,
`update`, `help`.

Das Tool liegt im Git-Checkout (`auto_update.repo_dir`) und wird vom Auto-Update
mit jedem `git pull` aktuell gehalten. `setup_wlanmon_probe.sh` richtet den Befehl
`wlanmon` ein; auf schon installierten Geräten reicht ein erster Aufruf aus dem
Checkout, der den Befehl selbst anlegt:

```bash
cd <repo_dir>          # z.B. ~/wlanmon/probe, siehe auto_update.repo_dir
sudo ./wlanmon-tool.sh
```

## Interface identifizieren

`setup_wlanmon_probe.sh` macht beides automatisch: setzt `net.ifnames=0`
in `/boot/armbianEnv.txt` (klassische Namen wie `wlan0` statt der
MAC-basierten Form `wlx<mac>`, wirkt erst nach einem Reboot) und gibt am
Ende des Laufs die erkannten WLAN-Interfaces aus. Manuell geht's so:

```bash
iw dev
# oder
ip link show | grep wl
```

Den gefundenen Namen (z. B. `wlan1`) in `config.yaml` unter `interface.name` eintragen.

**Ländereinstellung:** `interface.country` (Standard `DE`, wenn der Eintrag
fehlt) legt die Regulatory Domain fest - erlaubte Kanäle und Sendeleistung.
Ohne Land meldet `iw reg get` "country 00": dann darf der Adapter auf 5 GHz
nur passiv scannen und auf den Kanälen 12/13 keine Verbindung aufbauen. Die
Probe setzt das Land beim Start und prüft es vor jedem Scan (`iw reg set`).
Außerhalb Deutschlands den eigenen Ländercode eintragen (z. B. `"AT"`,
`"CH"`, `"US"`, in Anführungszeichen); `""` lässt die Systemeinstellung
unverändert.

## USB-Hotplug-Einschränkung & Watchdog

**An echter Hardware reproduziert (NanoPi NEO2 + mt7921u-USB-Adapter):**
Wird der USB-WLAN-Adapter im laufenden Betrieb gezogen und wieder
gesteckt, landet er beim Wiedereinstecken manchmal auf dem falschen
USB-Companion-Controller (OHCI/Full-Speed statt EHCI/High-Speed –
`dmesg`: `usb X-Y: not running at top speed`). Der Firmware-Upload des
mt7921u braucht die volle High-Speed-Bandbreite; schlägt er fehl,
crasht der Treiber (`Failed to get patch semaphore`, `hardware init
failed`) und der Chip bleibt **auch nach erneutem Stecken tot** – ein
Unbind/Bind über `/sys/bus/usb/drivers/usb/` bringt ihn in diesem
Zustand nachweislich nicht zurück, nur ein voller Neustart. Das ist
eine Einschränkung der Allwinner-H5-USB-Controller beim Hot-Plug
(EHCI/OHCI-Companion-Aushandlung), kein Software-Fehler.

**Praktische Konsequenz:** Der Adapter sollte beim Booten bereits
gesteckt sein; Hot-Plug im laufenden Betrieb nach Möglichkeit vermeiden.

**Für den Fall, dass es trotzdem passiert** (z. B. versehentlich
gewackelter Stecker im Feld), gibt es `wlan_watchdog.sh` +
`wlanmon-wifi-watchdog.timer`: prüft alle 2 Minuten, ob
`interface.name` noch als Netzwerk-Interface existiert, und rebootet
das Gerät automatisch, wenn es länger als
`watchdog.missing_threshold_minutes` durchgehend fehlt. Läuft als
eigener systemd-Timer, unabhängig vom `wlanmon-probe`-Service selbst –
der Watchdog funktioniert auch, wenn der Hauptprozess hängt. Standardmäßig
deaktiviert, aktivieren in `config.yaml`:

```yaml
watchdog:
  enabled: true
  missing_threshold_minutes: 10
```

`setup_wlanmon_probe.sh` installiert und aktiviert den Timer immer
(`systemctl enable --now wlanmon-wifi-watchdog.timer`) – der Watchdog
selbst greift aber erst, wenn `watchdog.enabled: true` gesetzt ist, tut
bis dahin bei jedem Check-Lauf einfach nichts.

## Automatische Updates aus dem Git-Repo

Optional, standardmäßig aus: `update_probe.py` prüft alle 15 Minuten
(`wlanmon-probe-update.timer`), ob im lokalen Git-Checkout eine neuere
Version auf `origin/<branch>` liegt (`git fetch` + `git rev-parse`). Falls
ja: `git pull --ff-only`, die bekannten Dateien (dieselbe Liste wie in
`setup_wlanmon_probe.sh`) nach `/opt/wlanmon-probe/` kopieren, `pip
install -r requirements.txt` (falls sich Abhängigkeiten geändert haben),
`systemctl restart wlanmon-probe`. Läuft als eigener systemd-Timer,
unabhängig vom `wlanmon-probe`-Service selbst.

```yaml
auto_update:
  enabled: true
  repo_dir: "/home/pi/wlanmon/probe"   # lokaler Git-Checkout
  branch: "stable"
```

`setup_wlanmon_probe.sh` installiert und aktiviert auch diesen Timer immer
(`systemctl enable --now wlanmon-probe-update.timer`) und trägt `repo_dir`
automatisch als das Verzeichnis ein, aus dem das Skript selbst aufgerufen
wurde – aktiv wird er trotzdem erst mit `auto_update.enabled: true`.

`update_probe.py` steht mit in seiner eigenen `FILES`-Liste, kopiert sich
bei jedem Lauf also selbst nach `/opt/wlanmon-probe/` – genau die Kopie
dort führt der systemd-Timer aus (`ExecStart` in
`wlanmon-probe-update.service`), nicht die im Git-Checkout unter
`repo_dir`. **Auf Geräten, die schon vor diesem Eintrag liefen, ist genau
dieser eine Selbstaktualisierungs-Mechanismus per Definition eingefroren**
(die alte Kopie kennt die neue `FILES`-Liste ja noch nicht) – dort einmalig
von Hand nachziehen:

```bash
sudo cp /home/pi/wlanmon/probe/update_probe.py /opt/wlanmon-probe/update_probe.py
```

(Pfad an `repo_dir` anpassen.) Ab dann läuft die Selbstaktualisierung wie
bei jeder anderen Datei aus `FILES` mit.

**`stable` statt `main`:** Geräte im Feld ziehen bewusst nicht direkt von
`main` (dort landet jeder Commit sofort), sondern vom Branch `stable`. Ein
Commit geht erst live, wenn er bewusst dorthin gemerged wird – nach
[CI](../.github/workflows/ci.yml) (`php -l`/`py_compile`/`bash -n` auf jeden
Push, siehe Badge/Checks auf GitHub) und optional einem manuellen Test auf
einem einzelnen Gerät. Freigeben (Fast-Forward, kein Merge-Commit):

```bash
git fetch origin
git checkout stable
git merge --ff-only origin/main
git push origin stable
```

Schlägt der `--ff-only`-Merge fehl, ist `stable` divergiert (z. B. ein
Hotfix direkt auf `stable`) – dann erst manuell klären statt zu erzwingen.
Geräte, die noch `branch: "main"` eingetragen haben, ziehen weiterhin von
`main`; auf `stable` umstellen durch einmaliges Anpassen von
`auto_update.branch` in `/etc/wlanmon-probe/config.yaml` (wirkt ab dem
nächsten Timer-Tick, kein Codeänderung/Neustart-von-Hand nötig).

Der Checkout unter `repo_dir` gehört meist dem Benutzer, der ihn per
`git clone` angelegt hat (z. B. `/home/pi/wlanmon/probe`), `update_probe.py`
läuft aber als `root`. Seit CVE-2022-24765 verweigert `git` in diesem Fall
standardmäßig jeden Zugriff ("detected dubious ownership in repository
at …") – `update_probe.py` trägt `repo_dir` deshalb bei jedem Lauf
automatisch (und idempotent) in `root`s globaler `safe.directory`-Liste
ein, das ist kein manueller Schritt nötig.

**Bei einem SSH-Deploy-Key statt HTTPS** (z. B. bei einem privaten Repo,
ein Key pro Gerät) ist ein einmaliger, manueller Schritt nötig: Der Key
liegt im Home-Verzeichnis des Benutzers, der ihn per `ssh-keygen`
angelegt hat (z. B. `/home/pi/.ssh/id_ed25519`), `update_probe.py` läuft
aber als `root` und hat standardmäßig kein eigenes SSH-Setup für GitHub -
`git fetch` schlägt dann mit `Permission denied (publickey)` fehl. Fix:
`root` denselben Key referenzieren lassen (er kann die Datei lesen,
unabhängig von den Zugriffsrechten des Home-Verzeichnisses - root umgeht
Dateiberechtigungen):

```bash
sudo mkdir -p /root/.ssh && sudo chmod 700 /root/.ssh
sudo tee -a /root/.ssh/config > /dev/null << 'EOF'
Host github.com
    HostName github.com
    User git
    IdentityFile /home/pi/.ssh/id_ed25519
    IdentitiesOnly yes
EOF
sudo chmod 600 /root/.ssh/config
# Host-Key vorab bekannt machen, sonst haengt der erste Verbindungsaufbau
# im Service ohne Terminal an der interaktiven Bestaetigung:
sudo ssh-keyscan github.com | sudo tee -a /root/.ssh/known_hosts > /dev/null
sudo ssh -T git@github.com   # erwartet: "Hi <user>/<repo>! ... successfully authenticated"
```

Pfad zum Key vorher mit `ls -la ~/.ssh/` prüfen (Dateiname kann je nach
Gerät abweichen) - dieser Schritt ist pro Gerät einmalig.

**Sicherheitshinweis:** Das führt automatisch aus, was auf `branch` liegt –
nur aktivieren, wenn dieser Branch tatsächlich nur Freigegebenes enthält
(kein Test-/Feature-Branch), und wenn der Zugriff auf das GitHub-Konto
entsprechend abgesichert ist. `git pull --ff-only` verweigert sich bei
lokalen Änderungen im Checkout statt etwas zu überschreiben; ein
fehlendes/ungültiges `repo_dir` bricht ebenfalls nur folgenlos ab (Zeile im
Log: `[update_probe] repo_dir '…' ist kein Git-Checkout …`). Log verfolgen:

```bash
journalctl -u wlanmon-probe-update -f
```

## Funktionsweise

- **Scan-Loop** (`scan.interval_seconds`): führt `iw dev <iface> scan` aus,
  parst SSID, BSSID, Signalstärke, Frequenz/Kanal sowie – falls der AP ein
  BSS-Load-Element (QBSS) sendet – die Zahl der assoziierten Clients
  (`station_count`) und die vom AP gemessene Kanalauslastung
  (`channel_utilization_pct`, umgerechnet aus `x/255`). Ab 1.0.1.9 außerdem
  die Fähigkeiten aus den Information Elements: `wifi_generation` (4–7 aus
  HT/VHT/HE/EHT capabilities, `null` = nur a/b/g), `channel_width_mhz`
  (VHT/HT operation; VHT auf 2,4 GHz zählt als Wi-Fi 4), ab 1.0.1.11
  `center_freq_mhz` (Mitte des belegten Kanalblocks für die Spektrumansicht),
  `security` (z. B. `WPA2/WPA3-Personal`,
  `WPA3-Enterprise`, `OWE`, `WEP`, `Open`) mit `pmf` (`required`/`optional`)
  und den rohen `akm`-Suites, sowie Roaming-Unterstützung `rrm_11k`,
  `neighbor_report`, `btm_11v` (BSS Transition) und `ft_11r` (FT-AKM im
  RSN). Direkt danach liest
  `iw dev <iface> survey dump` die Kanalbelegung aus Sicht des eigenen Radios
  (`channels`: Messdauer, Belegt-Zeit, `busy_pct`, Rauschen je Kanal) – das
  funktioniert auch bei APs ohne BSS Load. Achtung: mt76-Treiber (z. B.
  mt7921u) setzen diese Zähler bei jedem Kanalwechsel zurück, beim Scan ist
  das also nur eine kurze Momentaufnahme (Verweildauer je Kanal, siehe
  `active_ms`); andere Treiber summieren seit dem Hochfahren des Interfaces.
  Unterstützt der Treiber `survey dump` nicht, bleibt `channels` leer.
  Ab 1.0.1.16 misst die Probe zusätzlich im Connection-Test die Belegung des
  **verbundenen** Kanals (`channel_load`): Zählerstände nach DHCP, nach dem
  Ping und nach iperf3; `baseline` = Zeitraum DHCP bis Ping-Ende (kaum eigener
  Verkehr, entspricht der Alltagslast), `iperf3` = während der Durchsatzmessung,
  jeweils `busy_pct`, eigenes Senden `tx_pct`, Empfang `rx_pct` und der Rest
  `other_pct` (Nachbarn/Störungen). Zählt der Treiber nicht (0 ms), wechselt
  der Kanal oder werden die Zähler zurückgesetzt, fehlt der Wert.
- **Connection-Test-Loop** (`connection_tests.interval_seconds`): verbindet
  sich nacheinander mit jedem konfigurierten Ziel-SSID
  (`wpa_supplicant` + `dhclient`), misst Assoziationszeit, DHCP-Zeit,
  Ping-RTT/-Verlust und optional iperf3-Durchsatz, trennt danach sauber.
  Direkt vor dem DHCP-Versuch wird Promiscuous-Modus auf dem Interface
  explizit ausgeschaltet (`ip link set <iface> promisc off`) - manche
  Treiber (beobachtet: `brcmfmac` auf dem Onboard-WLAN eines Raspberry Pi
  5) lassen das Interface nach dem vorangegangenen Scan vereinzelt darin
  stehen, wodurch es waehrend der DHCP-Phase jeden Unicast-Frame in der
  Luft empfaengt statt nur die eigenen - in einem belebten Netz kann das
  dhclients Duplicate-Address-Detection faelschlich einen IP-Konflikt
  vermuten lassen (Symptom: DHCPDECLINE direkt nach DHCPACK, teils auch
  "Network is down" unter der zusaetzlichen Paketlast). Rein defensiv,
  greift nicht ein, wenn der Modus ohnehin schon aus ist. Direkt danach
  wird ausserdem WLAN-Power-Management ausgeschaltet
  (`iw dev <iface> set power_save off`) - bekanntes Problem bei
  Broadcom/Cypress-Chips (ebenfalls beobachtet auf dem Pi-5-Onboard-WLAN):
  periodische Sleep-/Wake-Zyklen im Power-Save (sichtbar in `dmesg` als
  wiederkehrendes `brcmf_cfg80211_set_power_mgmt: power save enabled`,
  auch ausserhalb aktiver Tests) koennen mitten in einer DHCP-Anfrage zu
  Paketverlust oder einem kurzen Verbindungsabbruch fuehren. Ebenfalls
  rein defensiv.
  iperf3 steht komplett je Ziel-SSID: `iperf3_enabled: false` schaltet es für
  diese SSID ab, `iperf3_download` (Standard an) steuert die zusätzliche
  Download-Messung, und `iperf3_server`/`iperf3_duration_seconds`/`iperf3_port`
  je Ziel überschreiben bei Bedarf die Standardwerte aus `connection_tests` -
  nötig, wenn eine SSID in ein anderes VLAN mit eigenem iperf3-Server/-Port
  führt. Leer bzw. 0 je Ziel = Standardwert aus `connection_tests` verwenden.
  Ab 1.0.1.13 je SSID `iperf3_bitrate_mbps` (iperf3 `-b`, 0 = unbegrenzt):
  ein unbegrenzter Test lastet den Kanal für seine Dauer voll aus – genau
  der Effekt, den ein Speedtest im Alltag verzerrt –, begrenzt prüft er, ob
  eine definierte Rate stabil erreicht wird. Beginn und Ende der iperf3-Last
  stehen als `iperf3_started_at`/`iperf3_ended_at` im Ergebnis; das Dashboard
  markiert diese Zeiträume im Tab „Verlauf“. Ab 1.0.1.14 begrenzt
  `connection_tests.iperf3_min_interval_minutes` (0 = bei jedem Test) iperf3
  auf höchstens einen Lauf je SSID in diesem Abstand; die übrigen Tests
  laufen ohne iperf3 (`iperf3_deferred: true`). Ein per Taste 3 ausgelöster
  Test misst immer mit. Die Zeitpunkte liegen ab 1.0.1.17 in
  `iperf3_last_run.json` neben der Queue (`/var/lib/wlanmon-probe/`), damit ein
  Neustart (Update, neue Remote-Config) den Mindestabstand nicht aushebelt.
  Ein iperf3-Server bedient nur einen Test gleichzeitig; meldet er
  „the server is busy running a test“ (z. B. weil eine zweite Probe gerade
  misst), versucht die Probe es bis zu dreimal im Abstand von Testdauer + 5 s.
  **iperf3-Server empfohlen mit** `iperf3 -s --idle-timeout=30 --rcv-timeout=10000`
  (TCP und UDP 5201 freigeben). Die Schreibweise mit `=` ist Absicht: manche
  NAS-Docker-Oberflächen übergeben `--idle-timeout 30` als *ein* Argument
  samt Leerzeichen, iperf3 bricht dann mit „unrecognized option“ ab und der
  Container startet in einer Schleife neu. Bricht ein Client mitten im Test weg, bleibt
  ein Server ohne diese Optionen gelegentlich dauerhaft auf „busy“ hängen und
  lehnt jeden weiteren Test ab – live beobachtet, alle LAN-Tests eines
  Standorts schlugen stundenlang fehl, bis der Server neu gestartet wurde.
  Als Docker-Compose-Dienst:

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
  Das Ping-Ziel gilt ebenfalls je Ziel-SSID (`ping_target`): leer = Default-Gateway
  des per DHCP erhaltenen Netzes (sinnvoll bei getrennten/isolierten VLANs),
  `connection_tests.ping_target` ist nur noch der letzte Fallback, falls kein
  Gateway ermittelbar ist. Das tatsächlich gepingte Ziel steht im Testergebnis.
- **Captive-Portal-Check** (optional, je Ziel-SSID mit `captive_portal_check: true`,
  im Dashboard eine Checkbox pro SSID; Detection-URL global in
  `connection_tests.captive_portal_url`, Standard
  `http://connectivitycheck.gstatic.com/generate_204`):
  Nach dem DHCP fragt die Probe die Detection-URL per Klartext-HTTP ab, fest
  an das WLAN-Interface gebunden (`SO_BINDTODEVICE`, wie `ping -I`), ohne
  Redirects zu folgen. HTTP 204 = kein Portal; 3xx, 200 oder 511 = Portal
  (Redirect-Ziel wird mitgeschickt); DNS-/Verbindungsfehler und andere
  Statuscodes = "nicht prüfbar". Ergebnis steht im Feld `captive_portal` des
  Connection-Tests; ein erkanntes Portal macht den Test nicht zum Fehlschlag.
  Grenzen: Portale merken sich oft die MAC nach dem ersten Login (dann
  "kein Portal"); dagegen hilft `random_mac: true` je Ziel-SSID (siehe
  unten). Solange ein Portal den Verkehr sperrt, wird iperf3 übersprungen
  (`skipped: "captive_portal"`); gepingt wird dann nur das Default-Gateway
  (`ping_target_source: "portal_gateway"`), das fast immer auch hinter dem
  Portal erreichbar ist – ein eigenes `ping_target` der SSID bzw. der globale
  Fallback läge meist hinter dem Portal und würde nur „alle verloren“ melden.
  Ohne ermittelbares Gateway entfällt der Ping. Manche Portale (z. B. Cisco
  Meraki, `eu.network-auth.com`) verwerfen vor dem Login auch ICMP zum
  Gateway – das Dashboard zeigt das dann neutral („Portal blockt ICMP“) und
  zählt es nicht als Ping-Verlust. Als Latenz, die auch durch so ein Portal
  kommt, misst der Portal-Check ab 1.0.1.8 `tcp_connect_ms` (TCP-Handshake)
  und `response_ms` (Anfrage bis Antwort-Header) der Detection-URL über das
  WLAN – ohne DNS, die läuft über den System-Resolver.
- **Portal-Login** (optional, je Ziel-SSID mit `captive_portal_login`
  `{enabled, type, username, password, logoff}`): modular aufgebaut, siehe
  Abschnitt "Portal-Module" unten. Nach dem Login gilt Erfolg, wenn die
  Detection-URL HTTP 204 liefert (bis zu 4 Prüfungen im Abstand von 1,5 s);
  dann laufen Ping/iperf3 wie gewohnt. Am Ende des Tests meldet die Probe
  die Sitzung wieder ab (`logoff`, Standard an), damit keine Gast-Sitzungen
  auflaufen. Das Ergebnis steht in `captive_portal.login` (`ok`, `portal_type`,
  `login_by`, `http_status`, `error`) und `captive_portal.logoff`; das Passwort
  wird nie geloggt oder gesendet, liegt aber wie die PSKs im Klartext in der
  Konfiguration.
- **Zufällige MAC** (optional, je Ziel-SSID mit `random_mac: true`): Vor dem
  Test setzt die Probe eine zufällige, lokal verwaltete MAC auf das
  WLAN-Interface (`ip link set address`, Interface dafür kurz down) und
  stellt danach die Hardware-MAC (`/sys/class/net/<if>/phy80211/macaddress`)
  wieder her. So erscheint ein Captive Portal bei jedem Test neu. Die MAC
  des Tests steht im Ergebnis (`mac_address`, `mac_random`). Nicht bei
  MAC-gefilterten Netzen oder DHCP-Reservierungen verwenden. Lässt der
  Treiber die Änderung nicht zu, läuft der Test mit der bisherigen MAC weiter
  (Warnung im Log).
- **Queue** (`queue_store.py`): jede Messung landet zuerst in einer lokalen
  SQLite-Datei. Ein Sender-Thread überträgt unbestätigte Einträge in
  Batches per HTTPS an `<server.url>/measurements`. Bei Fehlern bleibt der
  Eintrag in der Queue und wird mit exponentiellem Backoff erneut versucht.

## Portal-Module

Der Captive-Portal-Login liegt im Paket `portals/`. Jedes Modul kennt genau
einen Portaltyp (`portals/base.py`: `detect()`, `login()`, optional `logoff()`).
`captive_portal_login.type` wählt das Modul (`auto` = per Redirect-URL
erkennen). Vorhanden:

| type | Portal | Login-Arten |
|------|--------|-------------|
| `cirrus` | Alcatel-Lucent OmniVista Cirrus (Guest/BYOD) | Benutzername/Passwort, Access-Code, nur Nutzungsbedingungen (ohne Zugangsdaten) |
| `form` | Klassische Formular-Portale, z. B. pfSense (`index.php?zone=…&redirurl=…`) | Benutzername/Passwort, Voucher/Code, nur Klick auf „Akzeptieren“ |

`form` lädt die Portal-Seite (mit Cookies und Redirects), liest das
HTML-Formular und füllt es: hidden-Felder unverändert, Passwortfeld bzw. Feld
mit `voucher`/`code`/`token` im Namen = Passwort, Feld mit `user`/`login`/`name`
= Benutzername, Checkbox mit `accept`/`terms`/… wird angehakt, dazu der
Submit-Button. Ohne Benutzer-/Passwortfeld sind keine Zugangsdaten nötig. Bei
einer IP-Adresse als Portal-Ziel (Gateway) wird das TLS-Zertifikat nicht
geprüft. Eine Abmeldung gibt es nicht. Bei `auto` ist `form` der letzte
Ausweg, wenn kein spezielles Modul passt. Portale, die ihr Formular erst per
JavaScript erzeugen, brauchen ein eigenes Modul.

Neues Portal: Datei unter `portals/` anlegen, `PortalModule` ableiten und in
`portals/__init__.py` (`MODULES`) eintragen; im Dashboard-Dropdown (Portal-Typ)
ergänzen. Module werfen keine Exceptions, sondern melden Fehler im Ergebnis.

## Display & Buttons (optional, NanoHat OLED)

Ersetzt den bisherigen WLANPi-eigenen FPMS-Daemon (`fpms.service` /
`oled-start`) durch ein eigenes Modul (`display.py`), das direkt in
wlanmon-probe integriert ist. Hardware an einem laufenden Gerät
verifiziert:

- **Display**: SSD1306-kompatibles OLED, I2C-Adresse `0x3c`, Bus `i2c-0`
  (kein Framebuffer-Gerät vorhanden – Ansteuerung läuft direkt per I2C
  aus dem Userspace, dafür reicht das Device-Tree-Overlay `i2c0`).
- **3 Buttons**: sysfs-GPIO `0`, `2`, `3`, rising-edge.

**Wichtig:** `fpms.service` muss deaktiviert sein (macht
`setup_wlanmon_probe.sh` automatisch), sonst konkurrieren FPMS und
wlanmon-probe um denselben I2C-Bus und dieselben GPIOs.

Aktivieren in `config.yaml`:

```yaml
display:
  enabled: true
  i2c_port: 0
  i2c_address: 0x3c
  width: 128
  height: 64   # bei verzerrter unterer Hälfte auf 32 wechseln
  button_gpios: [0, 2, 3]
  sleep_after_seconds: 120   # Display-Schlaf, 0 = immer an
```

Fehlen die Bibliotheken (`luma.oled`/`luma.core`, in `requirements.txt`
enthalten) oder schlägt die Hardware-Initialisierung fehl, loggt
`DisplayLoop` eine Warnung und bleibt inaktiv – der Rest von
wlanmon-probe läuft unverändert weiter.

**Bedienung:**
- Button 1 / Button 2: zwischen den Screens navigieren (Status → letzter
  Scan → letzter Connection-Test → wieder Status)
- Button 3: löst sofort einen Connection-Test-Durchlauf aus, statt auf
  das nächste `connection_tests.interval_seconds`-Intervall zu warten –
  praktisch für Diagnose direkt vor Ort am Gerät.
- **Display-Schlaf:** nach `sleep_after_seconds` (Standard 120) ohne
  Tastendruck schaltet sich das Panel ab (OLEDs brennen bei dauerhaft
  statischem Bild ein). Jede Taste weckt es wieder; dieser erste Druck
  wechselt weder den Screen noch löst er einen Test aus. Der Inhalt wird
  auch im Schlaf weiter aktualisiert und ist nach dem Aufwecken aktuell.
  `0` = immer an. Die Option steht im lokalen `display`-Block der
  `config.yaml` (nicht in der Dashboard-Konfiguration) und greift nach
  einem Neustart der Probe.

Die Screens zeigen Device-ID/Standort/Uptime/Zeit seit letzter
erfolgreicher Server-Übertragung, das letzte Scan-Ergebnis
(Zeitpunkt + Netzanzahl) sowie den letzten Connection-Test (SSID,
Status, Ping-RTT/-Verlust, iperf3-Durchsatz falls konfiguriert,
Fehlertext bei Fehlschlag). Aktualisierung ist ereignisgesteuert – ein
Redraw passiert nur bei Button-Druck oder wenn sich der zugrunde
liegende Status tatsächlich ändert, nicht in festem Rhythmus.

Noch nicht gegen echte Hardware getestet (nur anhand der oben
verifizierten I2C-Adresse/GPIO-Nummern und der Standard-API von
`luma.oled` gebaut) – beim ersten Einsatz Log (`journalctl -u
wlanmon-probe`) auf Fehler beim Hardware-Init prüfen.

## Zentrale Konfiguration vom Server (optional)

Mit `remote_config.enabled: true` in `config.yaml` zieht sich der Client die
Blöcke `scan` und `connection_tests` (Intervalle, Ziel-SSIDs, Ping-Ziel,
iperf3-Server...) periodisch vom Server statt sie nur lokal zu lesen:

- Beim Start: `GET <server.url>/devices/<device_id>/config` mit
  `Authorization: Bearer <api_key>`. Antwort erwartet als JSON mit den
  Keys `scan` und `connection_tests` (gleiche Struktur wie in
  `config.example.yaml`).
- Erfolg -> Werte werden lokal unter `remote_config.cache_path` gecacht
  und verwendet.
- Fehlschlag beim Start -> letzter lokaler Cache wird verwendet, falls
  vorhanden; sonst die lokalen Werte aus `config.yaml`.
- 404 vom Server -> Server kennt (noch) keine spezifische Konfiguration
  für dieses Gerät, lokale/gecachte Werte bleiben aktiv.
- Im laufenden Betrieb prüft ein `ConfigWatcher`-Thread alle
  `remote_config.poll_interval_seconds`, ob sich die Server-Konfiguration
  geändert hat (Hash-Vergleich). Bei einer Änderung wird der Cache
  aktualisiert und der Prozess beendet sich sauber (Exit-Code 75) –
  systemd (`Restart=always`) startet ihn danach mit der neuen
  Konfiguration frisch neu. Das ist robuster als ein Live-Reload
  einzelner Threads und stellt sicher, dass Scan-/Test-Loop nie mit
  inkonsistenten halb-alten Werten laufen.

Bootstrap-Werte (`device.id`, `device.site`, `interface.name`,
`server.*`, `queue.*`, `logging.*`) bleiben immer lokal in `config.yaml`
und werden nie vom Server überschrieben – sonst könnte sich ein Gerät
selbst vom Server abschneiden.

## Erwartetes Server-API-Schema (POST /measurements)

```json
{
  "device_id": "wlanmon-probe-01",
  "probe_version": "1.0.0",
  "auto_update": {"enabled": true, "branch": "stable",
                  "repo_dir": "/home/pi/wlanmon/probe",
                  "repo_url": "git@github.com:dgawin/wlanmon.git"},
  "measurements": [
    {
      "id": 123,
      "kind": "scan",
      "data": {
        "timestamp": "2026-09-15T10:00:00+00:00",
        "interface": "wlan1",
        "networks": [
          {"ssid": "Firmennetz-Corp", "bssid": "aa:bb:cc:dd:ee:ff",
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
        "ssid": "Firmennetz-Corp",
        "security": "wpa2-psk",
        "connected": true,
        "assoc_seconds": 1.2,
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

Erwartete Antwort: HTTP 2xx bei erfolgreicher Annahme des gesamten Batches.
Alles andere (4xx/5xx, Timeout) führt dazu, dass der Batch unverändert in
der Queue verbleibt und später erneut gesendet wird.

`auto_update` zeigt das Dashboard auf der Geräteseite an. an/aus und Branch
liest die Probe bei jedem Batch frisch aus `config.yaml`, mit denselben
Regeln wie `update_probe.py` (eine geänderte `auto_update.branch` wirkt ohne
Neustart). Repo-URL, Commit und Ergebnis des letzten Update-Laufs
(`current`/`updated`/`disabled`/`error` samt Fehlertext, z. B. ein
`Permission denied (publickey)` beim `git fetch`) schreibt `update_probe.py`
bei jedem Lauf nach `/var/lib/wlanmon-probe/update_state.json` – die Probe
selbst läuft mit `ProtectHome=true` und sieht den Checkout unter `/home`
nicht. Zugangsdaten in einer HTTP(S)-URL (`https://user:token@...`) werden
vorher entfernt. Die Datei entsteht erst beim ersten Lauf einer
`update_probe.py` ab 1.0.1.3; bis dahin fehlen diese Angaben.

Ab 1.0.1.12 geben `git fetch`/`git pull` bei hängender Verbindung schnell
auf (SSH: `ConnectTimeout=20`, `ServerAliveInterval=10`/`CountMax=3`; HTTPS:
Abbruch unter 1 KB/s für 30 s). Die SSH-Timeouts werden an den Befehl
angehängt, den git ohnehin nutzen würde (`GIT_SSH_COMMAND`, sonst
`core.sshCommand`, sonst `ssh`) – ab 1.0.1.15; 1.0.1.12–1.0.1.14 haben ein per
`core.sshCommand` eingerichtetes SSH überschrieben („Host key verification
failed“),
und ein fehlgeschlagener `fetch` wird nach 45 s einmal wiederholt. Anlass:
ein `fetch` hing 120 s, als er zeitgleich mit einem Connection-Test lief –
`wlan0` im selben Subnetz wie `eth0` bekommt dann kurz eine eigene
Default-Route. `fail_count` zählt Fehlschläge in Folge; das Dashboard zeigt
einen einzelnen nur als grauen Hinweis, rot erst ab zwei in Folge.

`probe_version` kommt aus der `VERSION`-Datei neben `main.py` (wird bei
Updates automatisch mitkopiert, siehe `FILES` in `update_probe.py`) -
"unbekannt", falls die Datei fehlt. Versionsschema wie im Dashboard (siehe
dortiges README, Abschnitt "Version"): jeder Commit auf `main` zählt die
vierte Stelle hoch (`1.0.1.1`, `1.0.1.2`, ...), mit dem nächsten Release
entfällt sie wieder (`1.0.2`). Von Hand im selben Commit, kein
automatischer Bump.

`device.site` in `config.yaml` gibt es weiterhin, wird aber seit dem
Standort-Umbau im Dashboard (Zuordnung läuft dort über `devices.site_id`,
Admin-verwaltet) nicht mehr an den Server geschickt - rein lokal für die
Log-Zeile beim Start und den OLED-Status (`display.py`).

## 802.1X / WPA2-Enterprise

Ziele mit `security: "wpa2-eap"` brauchen einen Block `eap` (Beispiel in
`config.example.yaml`, normalerweise über das Dashboard gepflegt).
Unterstützt: `peap` (MSCHAPv2), `ttls` (PAP, MSCHAPv2, MSCHAP, CHAP) und
`tls` (Client-Zertifikat + privater Schlüssel).

- Das CA-Zertifikat ist optional: ist eines hinterlegt und
  `verify_server` nicht `false`, wird der RADIUS-Server geprüft
  (optional zusätzlich gegen `server_name`); sonst nicht.
- Zertifikate/Schlüssel stehen als PEM-Text in der Config und liegen
  während eines Tests in einem 0700-Verzeichnis, das danach gelöscht wird.
  Der lokale Config-Cache (`remote_config_cache.yaml`) ist nur für root lesbar.
- Der Test wartet nach der Assoziation auf den abgeschlossenen
  EAP-/4-Way-Handshake (`wpa_cli status`) und weist die Dauer als
  `auth_seconds` getrennt von `assoc_seconds` aus. Schlägt er fehl, steht
  eine lesbare Ursache im Ergebnis (Zertifikat, Passwort, RADIUS-Timeout ...).
- Benötigt `wpa_cli` (Paket `wpasupplicant`).

## Lizenz

MIT – siehe [LICENSE](../LICENSE).
