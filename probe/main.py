#!/usr/bin/env python3
"""
WLANMON-Probe – Client-Anwendung.

Läuft als systemd-Service, benötigt Root-Rechte. Zwei parallele Loops:
  - Scan-Loop: periodischer Scan aller sichtbaren SSIDs/BSSIDs
  - Test-Loop: periodischer Verbindungstest gegen konfigurierte Ziel-SSIDs
Beide schreiben Ergebnisse in eine lokale SQLite-Queue, ein separater
Sender-Thread überträgt diese asynchron an den Server.

Aufruf: python3 main.py --config /etc/wlanmon-probe/config.yaml
"""

from __future__ import annotations

import argparse
import dataclasses
import json
import logging
import os
import logging.handlers
import random
import signal
import socket
import subprocess
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

import urllib3

from config_manager import ConfigWatcher, load_bootstrap, resolve_initial_config
from display import DisplayLoop
from probe_status import ProbeStatus
from queue_store import QueueStore
from sender import Heartbeat, Sender
from wifi_ops import (
    DEFAULT_CAPTIVE_PORTAL_URL,
    capture_remove,
    channel_survey,
    ensure_regdomain,
    interface_ipv4,
    run_connection_test,
    run_lan_iperf3,
    release_from_networkmanager,
    scan,
    unblock_wifi,
)

log = logging.getLogger("wlanmon_probe")

# Eintrag des LAN-Tests in iperf3_last_run.json (daneben stehen die SSIDs).
LAN_IPERF3_KEY = "__lan__"


def setup_logging(cfg: dict) -> None:
    level = getattr(logging, cfg.get("level", "INFO").upper(), logging.INFO)
    handlers: list[logging.Handler] = [logging.StreamHandler()]
    log_file = cfg.get("file")
    if log_file:
        Path(log_file).parent.mkdir(parents=True, exist_ok=True)
        handlers.append(
            logging.handlers.RotatingFileHandler(
                log_file, maxBytes=10_000_000, backupCount=5
            )
        )
    logging.basicConfig(
        level=level,
        format="%(asctime)s %(levelname)-8s %(name)s: %(message)s",
        handlers=handlers,
    )


def warn_insecure_server(server_cfg: dict) -> None:
    """Einmal beim Start deutlich warnen, wenn die Verbindung zum Server
    ungeschuetzt ist, statt der InsecureRequestWarning von urllib3 bei jeder
    einzelnen Anfrage (die schaltet main() ab). Ueber diese Verbindung gehen
    neben dem API-Key auch die zentrale Konfiguration mit WLAN-Passwoertern,
    802.1X- und Portal-Zugangsdaten."""
    url = str(server_cfg.get("url") or "")
    if url.lower().startswith("http://"):
        log.warning(
            "server.url nutzt http:// - API-Key und zentrale Konfiguration (inkl. "
            "WLAN-Passwoerter) gehen unverschluesselt uebers Netz. Nur in einem "
            "abgeschotteten Testnetz verwenden."
        )
    elif server_cfg.get("verify_tls", True) is False:
        log.warning(
            "server.verify_tls ist false - das Server-Zertifikat wird nicht geprueft, "
            "wer sich ins Netz einklinkt, kann API-Key und WLAN-Passwoerter mitlesen. "
            "Besser verify_tls auf die CA-Datei des Servers zeigen lassen."
        )


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _read_version() -> str:
    """Liest die VERSION-Datei neben main.py (wird bei Updates mitkopiert,
    siehe FILES in update_probe.py) - "unbekannt" statt einer Exception,
    falls sie fehlt (z.B. bei einer manuellen Installation ohne diese
    Datei)."""
    try:
        return (Path(__file__).resolve().parent / "VERSION").read_text(encoding="utf-8").strip()
    except OSError:
        return "unbekannt"


UPDATE_STATE_PATH = Path("/var/lib/wlanmon-probe/update_state.json")


def _auto_update_info(config_path: str, state_path: Path = UPDATE_STATE_PATH) -> dict:
    """Auto-Update-Stand fuer das Dashboard (Geraeteseite).

    an/aus und Branch: bei jedem Aufruf frisch aus config.yaml, mit genau
    denselben Regeln wie update_probe.py (eine geaenderte Branch wirkt dort
    ohne Neustart der Probe, die Anzeige soll dem folgen).

    Repo-URL, Commit und Ergebnis des letzten Laufs: aus der Status-Datei,
    die update_probe.py bei jedem Lauf schreibt - die Probe selbst laeuft
    mit ProtectHome=true und kann den Checkout unter /home nicht lesen."""
    au = (load_bootstrap(config_path) or {}).get("auto_update") or {}
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
        if not isinstance(state, dict):
            state = {}
    except (OSError, ValueError):
        state = {}
    return {
        "enabled": bool(au.get("enabled")),
        "branch": str(au.get("branch") or "main"),
        "repo_dir": str(au.get("repo_dir") or "") or None,
        "repo_url": state.get("repo_url"),
        "commit": state.get("commit"),
        "last_run_at": state.get("run_at"),
        "last_result": state.get("result"),
        "last_message": state.get("message"),
        # Fehlschlaege in Folge (ab update_probe 1.0.1.12); None bei aelteren.
        "fail_count": state.get("fail_count"),
    }


def _wait_for_ntp_sync(timeout_seconds: int = 60, poll_interval: float = 2.0) -> None:
    """
    Wartet bis zu timeout_seconds, bis die Systemuhr per NTP synchronisiert
    ist, bevor die Scan-/Test-Loops anfangen, Zeitstempel zu erzeugen.

    Hintergrund: der NanoPi hat kein batteriegepuffertes RTC (Armbian
    nutzt stattdessen fake-hwclock als Notlösung) - nach einem Kaltstart
    kann die Systemzeit deshalb für eine Weile falsch sein. An einem
    laufenden Gerät wurde genau das reproduziert: ein konstanter ~2h-
    Versatz (UTC/CEST-Differenz) über 150+ Messungen hinweg, bis der
    nächste NTP-Sync griff - alle Zeitstempel in dem Fenster waren damit
    unbrauchbar. Bricht nach dem Timeout ab und läuft trotzdem weiter
    (Zeitstempel könnten dann kurzzeitig falsch sein), statt den Start
    auf unbestimmte Zeit zu blockieren, falls NTP z.B. in einem
    isolierten Netz ohne Zeitserver nie synchronisiert.
    """
    deadline = time.monotonic() + timeout_seconds
    while time.monotonic() < deadline:
        try:
            result = subprocess.run(
                ["timedatectl", "show", "-p", "NTPSynchronized", "--value"],
                capture_output=True, text=True, timeout=5,
            )
        except (OSError, subprocess.TimeoutExpired):
            log.warning("timedatectl nicht verfügbar - überspringe NTP-Wartezeit.")
            return
        if result.stdout.strip() == "yes":
            log.info("Systemuhr per NTP synchronisiert.")
            return
        time.sleep(poll_interval)
    log.warning(
        "Systemuhr nach %ds immer noch nicht per NTP synchronisiert - "
        "starte trotzdem (Zeitstempel könnten kurzzeitig falsch sein).",
        timeout_seconds,
    )


class ScanLoop(threading.Thread):
    def __init__(
        self,
        cfg: dict,
        store: QueueStore,
        stop_event: threading.Event,
        wifi_lock: threading.Lock,
        status: ProbeStatus | None = None,
    ):
        super().__init__(name="scan-loop", daemon=True)
        self._cfg = cfg
        self._store = store
        self._stop_event = stop_event
        self._wifi_lock = wifi_lock
        self._status = status
        self._interface = cfg["interface"]["name"]
        self._country = _country(cfg)
        self._interval = cfg["scan"]["interval_seconds"]
        # Scans je Durchlauf, zusammengefuehrt (siehe wifi_ops.scan()).
        self._passes = min(5, max(1, int(cfg["scan"].get("passes") or 2)))
        # Verwaltungs-Interface (Kabel) - dessen IP wird bei jedem Scan mit
        # an den Server gemeldet und im Dashboard angezeigt.
        self._mgmt_interface = (cfg.get("display") or {}).get("management_interface") or "eth0"

    def _local_ips(self) -> dict[str, str]:
        """IPv4 je Interface (nur Interfaces, die gerade eine haben). Das
        WLAN-Interface ist ausserhalb der Tests meist ohne Adresse."""
        ips: dict[str, str] = {}
        for name in dict.fromkeys([self._mgmt_interface, self._interface]):
            ip = interface_ipv4(name)
            if ip:
                ips[name] = ip
        return ips

    def run(self) -> None:
        while not self._stop_event.is_set():
            try:
                # Lock verhindert, dass Scan und Connection-Test
                # gleichzeitig auf denselben Funkadapter zugreifen - ein
                # einzelner WLAN-Stick kann nicht beides parallel.
                with self._wifi_lock:
                    # Falls die Ländereinstellung zwischendurch zurückgesetzt
                    # wurde (z.B. Treiber neu geladen) - setzt nur bei Abweichung.
                    ensure_regdomain(self._country)
                    results, pass_counts = scan(self._interface, passes=self._passes)
                    # Direkt nach dem Scan, solange die Kanalzaehler noch
                    # zu diesem Scan gehoeren (siehe channel_survey()).
                    try:
                        surveys = channel_survey(self._interface)
                    except Exception:
                        log.warning("Kanalbelegung (survey dump) nicht auslesbar", exc_info=True)
                        surveys = []
                payload = {
                    "timestamp": _now_iso(),
                    "interface": self._interface,
                    "local_ips": self._local_ips(),
                    "networks": [dataclasses.asdict(r) for r in results],
                    "pass_counts": pass_counts,
                    "channels": [dataclasses.asdict(s) for s in surveys],
                }
                self._store.enqueue("scan", payload)
                log.info("Scan abgeschlossen: %d Netze gefunden (je Durchgang: %s)",
                         len(results), ", ".join(map(str, pass_counts)))
                if self._status is not None:
                    self._status.update_scan(len(results))
            except Exception:
                log.exception("Fehler beim Scan")
            self._stop_event.wait(self._interval)


class ConnectionTestLoop(threading.Thread):
    def __init__(
        self,
        cfg: dict,
        store: QueueStore,
        stop_event: threading.Event,
        wifi_lock: threading.Lock,
        status: ProbeStatus | None = None,
        trigger_event: threading.Event | None = None,
    ):
        super().__init__(name="connection-test-loop", daemon=True)
        self._cfg = cfg
        self._store = store
        self._stop_event = stop_event
        self._wifi_lock = wifi_lock
        self._status = status
        self._trigger_event = trigger_event
        self._interface = cfg["interface"]["name"]
        ct_cfg = cfg["connection_tests"]
        self._interval = ct_cfg["interval_seconds"]
        self._connect_timeout = ct_cfg["connect_timeout_seconds"]
        # Nur letzter Fallback; je SSID gilt target["ping_target"], leer =
        # Default-Gateway (siehe wifi_ops._resolve_ping_target).
        self._ping_target = ct_cfg.get("ping_target", "")
        self._ping_count = ct_cfg["ping_count"]
        # Standardwerte, falls ein Ziel unten keine eigenen einvertraegt
        # (siehe _iperf3_settings_for()).
        self._default_iperf3_server = ct_cfg.get("iperf3_server", "")
        self._default_iperf3_duration = ct_cfg.get("iperf3_duration_seconds", 5)
        self._default_iperf3_port = ct_cfg.get("iperf3_port", 5201)
        # iperf3 hoechstens alle N Minuten je SSID (0 = bei jedem Test).
        # Verbindung, DHCP, Ping und Portal-Check laufen weiter in jedem
        # Zyklus; nur die schwere Durchsatzmessung wird seltener - sonst
        # misst die Probe im Verlauf vor allem ihre eigene Last.
        try:
            self._iperf3_min_interval = max(0, int(ct_cfg.get("iperf3_min_interval_minutes") or 0)) * 60
        except (TypeError, ValueError):
            self._iperf3_min_interval = 0
        # SSID -> Unix-Zeit des letzten iperf3-Laufs. Liegt als Datei neben der
        # Queue, damit ein Neustart (Update, neue Remote-Config) den
        # Mindestabstand nicht aushebelt - sonst liefe nach jedem Neustart
        # sofort wieder iperf3. Echte Uhrzeit statt monotonic, weil dessen
        # Zaehler nach einem Reboot neu beginnt.
        queue_dir = Path((cfg.get("queue") or {}).get("db_path") or "/var/lib/wlanmon-probe/queue.db").parent
        self._iperf3_state_path = queue_dir / "iperf3_last_run.json"
        self._last_iperf3: dict[str, float] = self._load_iperf3_state()
        # True, wenn der laufende Zyklus per Button 3 ausgeloest wurde: dann
        # laeuft iperf3 unabhaengig vom Mindestabstand (Vor-Ort-Diagnose).
        self._forced_cycle = False
        # Detection-URL fuer den Captive-Portal-Check; geprueft wird nur bei
        # Zielen mit captive_portal_check: true. Leere URL = Standard.
        self._captive_portal_url = (
            (ct_cfg.get("captive_portal_url") or "").strip() or DEFAULT_CAPTIVE_PORTAL_URL
        )
        # ct_cfg.get(...) statt ["targets"]: eine leere/fehlende Liste in
        # der config.yaml (z.B. beim Anpassen versehentlich geleert) soll
        # den Thread nicht mit einer unbehandelten Exception sterben
        # lassen - stattdessen unten bei jedem Zyklus eine Warnung loggen
        # und einfach nichts tun, bis targets nachgetragen werden.
        self._targets = ct_cfg.get("targets") or []
        # Fehlgeschlagene Tests mitschneiden (pcap + iw-Ereignisse, siehe FailureCapture in wifi_ops.py).
        # Standard an; ueber das Dashboard je Geraet abschaltbar.
        self._capture_on_failure = bool(ct_cfg.get("capture_on_failure", True))
        # Optionaler iperf3-Test ueber das Kabel (Upload + Download),
        # einmal pro Zyklus und unabhaengig von den Ziel-SSIDs.
        lan_cfg = ct_cfg.get("iperf3_lan") or {}
        self._lan_enabled = bool(lan_cfg.get("enabled")) and bool(lan_cfg.get("server"))
        self._lan_server = lan_cfg.get("server", "")
        self._lan_interface = lan_cfg.get("interface") or "eth0"
        self._lan_duration = lan_cfg.get("duration_seconds", 5)
        self._lan_port = lan_cfg.get("port", 5201)
        # Eigener Mindestabstand fuer den LAN-Test (Minuten, 0 = jeder Zyklus);
        # fehlt er, gilt der allgemeine iperf3-Abstand (Verhalten bis 1.0.1.52).
        try:
            lan_minutes = lan_cfg.get("min_interval_minutes")
            self._lan_min_interval = (
                max(0, int(lan_minutes)) * 60 if lan_minutes is not None else self._iperf3_min_interval
            )
        except (TypeError, ValueError):
            self._lan_min_interval = self._iperf3_min_interval

    def _iperf3_settings_for(self, target: dict) -> tuple[str, int, bool, int]:
        """iperf3-Server/-Dauer/-Download/-Port fuer ein Ziel: eigene Werte,
        falls gesetzt (z.B. eigenes VLAN mit eigenem Server/Port), sonst die
        Standardwerte aus connection_tests. iperf3_enabled: false schaltet
        iperf3 fuer dieses Ziel unabhaengig davon komplett ab."""
        if not target.get("iperf3_enabled", True):
            return "", self._default_iperf3_duration, True, self._default_iperf3_port
        server = (target.get("iperf3_server") or "").strip() or self._default_iperf3_server
        duration = target.get("iperf3_duration_seconds") or self._default_iperf3_duration
        port = target.get("iperf3_port") or self._default_iperf3_port
        # Download-Zusatzmessung ist rein per-SSID (Default an), kein
        # globaler Fallback noetig.
        download = bool(target.get("iperf3_download", True))
        return server, duration, download, port

    @staticmethod
    def _iperf3_bitrate_for(target: dict) -> float:
        """Bandbreitenbegrenzung fuer iperf3 je Ziel in Mbit/s (0 = ohne
        Begrenzung). Ein unbegrenzter Test lastet den Kanal fuer seine Dauer
        voll aus - genau die Last, die er messen soll; begrenzt misst er,
        ob eine definierte Rate (z.B. 20 Mbit/s) stabil erreicht wird."""
        try:
            return max(0.0, float(target.get("iperf3_bitrate_mbps") or 0))
        except (TypeError, ValueError):
            return 0.0

    def _run_lan_test(self) -> None:
        # Wie WLAN-iperf3 hoechstens alle iperf3_min_interval_minutes: mehrere
        # Probes an einem Standort messen sonst in jedem Zyklus gegen dieselbe
        # NAS und blockieren sich gegenseitig ("server is busy").
        if not self._iperf3_due(LAN_IPERF3_KEY, self._lan_min_interval):
            return
        # Zufaelliger Versatz: Probes, die gleichzeitig neu gestartet wurden
        # (z.B. Auto-Update), laufen sonst im selben Takt.
        if self._stop_event.wait(random.uniform(0, 30)):
            return
        try:
            result = run_lan_iperf3(
                self._lan_interface, self._lan_server, self._lan_duration, self._lan_port
            )
            if any(c.get("code") == "iperf3_busy" for c in (result.error_codes or [])):
                # Kein Fehler des Netzes, nur eine andere Probe am Messen: nicht
                # melden (das Dashboard behaelt die letzte echte Messung) und
                # im naechsten Zyklus erneut versuchen.
                log.info("LAN-iperf3 -> %s: Server belegt, naechster Versuch im naechsten Zyklus",
                         result.server)
                return
            self._last_iperf3[LAN_IPERF3_KEY] = time.time()
            self._save_iperf3_state()
            payload = {
                "timestamp": _now_iso(),
                **dataclasses.asdict(result),
            }
            self._store.enqueue("lan_test", payload)
            up = f"{result.upload_mbps:.1f}" if result.upload_mbps is not None else "-"
            down = f"{result.download_mbps:.1f}" if result.download_mbps is not None else "-"
            log.info(
                "LAN-iperf3 %s (%s) -> %s: up=%s down=%s Mbit/s retr=%s/%s error=%s",
                result.interface, result.ip_address, result.server, up, down,
                result.upload_retransmits, result.download_retransmits, result.error,
            )
        except Exception:
            log.exception("Fehler beim LAN-iperf3-Test")

    def run(self) -> None:
        while not self._stop_event.is_set():
            if not self._targets:
                log.warning(
                    "connection_tests.targets ist leer - keine Verbindungstests "
                    "konfiguriert. config.yaml pruefen."
                )
            for target in self._targets:
                if self._stop_event.is_set():
                    break
                try:
                    # Lock geteilt mit ScanLoop - verhindert, dass ein
                    # laufender Scan die Assoziation stört (und umgekehrt).
                    with self._wifi_lock:
                        # Erst nach dem Lock loggen: so steht die Zeile
                        # tatsaechlich direkt vor den Meldungen dieses Tests
                        # (dhclient & Co.) und nicht vor einer Wartezeit auf
                        # einen laufenden Scan.
                        security = target.get("security", "wpa2-psk")
                        eap_method = (target.get("eap") or {}).get("method") or "peap"
                        log.info(
                            "Beginne Connection-Test: SSID %s (%s%s)",
                            target["ssid"], security,
                            f"/{eap_method}" if security == "wpa2-eap" else "",
                        )
                        iperf3_server, iperf3_duration, iperf3_download, iperf3_port = (
                            self._iperf3_settings_for(target)
                        )
                        iperf3_deferred = bool(iperf3_server) and not self._iperf3_due(target["ssid"])
                        if iperf3_deferred:
                            log.info(
                                "SSID %s: iperf3 ausgelassen (Mindestabstand %d min)",
                                target["ssid"], self._iperf3_min_interval // 60,
                            )
                            iperf3_server = ""
                        result = run_connection_test(
                            interface=self._interface,
                            ssid=target["ssid"],
                            psk=target.get("psk", ""),
                            security=target.get("security", "wpa2-psk"),
                            connect_timeout=self._connect_timeout,
                            ping_target=self._ping_target,
                            ping_count=self._ping_count,
                            ssid_ping_target=(target.get("ping_target") or ""),
                            iperf3_server=iperf3_server,
                            iperf3_duration=iperf3_duration,
                            iperf3_download=iperf3_download,
                            iperf3_port=iperf3_port,
                            iperf3_bitrate_mbps=self._iperf3_bitrate_for(target),
                            eap=target.get("eap"),
                            random_mac=bool(target.get("random_mac")),
                            capture_on_failure=self._capture_on_failure,
                            portal_login=target.get("captive_portal_login"),
                            captive_portal_url=(
                                self._captive_portal_url
                                if target.get("captive_portal_check")
                                else ""
                            ),
                        )
                    if self._stop_event.is_set() and not result.connected:
                        # Beim Herunterfahren (z.B. "systemctl restart" nach
                        # einem Auto-Update) beendet systemd auch wpa_supplicant
                        # und dhclient/dhcpcd dieses Tests - der Fehlschlag
                        # ("Link bereits getrennt") kaeme dann von uns selbst,
                        # nicht vom Netz. Nicht melden, nur protokollieren.
                        log.warning(
                            "Connection-Test %s beim Herunterfahren abgebrochen - "
                            "Ergebnis verworfen (%s)", target["ssid"], result.error,
                        )
                        if result.capture_id:
                            capture_remove(result.capture_id)
                        break
                    result.iperf3_deferred = iperf3_deferred
                    # Server belegt: nicht als Lauf zaehlen, der naechste Zyklus misst erneut.
                    if result.iperf3_started_at and not result.iperf3_busy:
                        self._last_iperf3[target["ssid"]] = time.time()
                        self._save_iperf3_state()
                    payload = {
                        "timestamp": _now_iso(),
                        "interface": self._interface,
                        **dataclasses.asdict(result),
                    }
                    self._store.enqueue("connection_test", payload)
                    up_txt = (
                        f"{result.iperf3_mbps:.1f}"
                        if result.iperf3_mbps is not None
                        else "-"
                    )
                    down_txt = (
                        f"{result.iperf3_download_mbps:.1f}"
                        if result.iperf3_download_mbps is not None
                        else "-"
                    )
                    iperf3_txt = f"up={up_txt} down={down_txt} Mbit/s"
                    link_txt = ""
                    if result.eap_method:
                        link_txt += (
                            f" eap={result.eap_method}"
                            f" auth={f'{result.auth_seconds}s' if result.auth_seconds is not None else '-'}"
                        )
                    if result.link:
                        tx = result.link.get("tx_rate") or {}
                        link_txt += (
                            f" ip={result.ip_address} signal={result.link.get('signal_dbm')}dBm"
                            f" tx={tx.get('mbps')}Mbit/s"
                            f" {tx.get('standard', '')}"
                            f"{'-MCS' + str(tx['mcs']) if 'mcs' in tx else ''}"
                        )
                    log.info(
                        "Connection-Test %s: connected=%s error=%s iperf3=%s%s",
                        target["ssid"], result.connected, result.error, iperf3_txt,
                        link_txt,
                    )
                    if self._status is not None:
                        self._status.update_test(
                            ssid=result.ssid,
                            connected=result.connected,
                            assoc_seconds=result.assoc_seconds,
                            dhcp_seconds=result.dhcp_seconds,
                            ping_sent=result.ping_sent,
                            ping_received=result.ping_received,
                            ping_rtt_avg_ms=result.ping_rtt_avg_ms,
                            iperf3_mbps=result.iperf3_mbps,
                            error=result.error,
                        )
                except Exception:
                    log.exception("Fehler beim Connection-Test für %s", target["ssid"])
                # Kurze Pause zwischen zwei Zielen, damit das Interface
                # sauber zurücksetzen kann, bevor der nächste Test startet.
                self._stop_event.wait(5)
            if self._lan_enabled and not self._stop_event.is_set():
                self._run_lan_test()
            self._wait_for_next_cycle()

    def _iperf3_due(self, ssid: str, interval: int | None = None) -> bool:
        """Darf iperf3 fuer diese SSID (bzw. den LAN-Test) in diesem Zyklus
        laufen? Ja ohne Mindestabstand, beim ersten Test nach dem Start, nach
        Ablauf des Abstands oder wenn der Zyklus per Button 3 ausgeloest wurde.
        interval in Sekunden, Standard: der allgemeine iperf3-Abstand."""
        interval = self._iperf3_min_interval if interval is None else interval
        if interval <= 0 or self._forced_cycle:
            return True
        last = self._last_iperf3.get(ssid)
        now = time.time()
        # last > now: Uhr wurde zurueckgestellt - lieber messen als tagelang warten.
        return last is None or last > now or now - last >= interval

    def _load_iperf3_state(self) -> dict[str, float]:
        """Letzte iperf3-Zeitpunkte aus der Datei; leer, wenn sie fehlt oder
        kaputt ist. Eintraege aus der Zukunft (Uhr war falsch) werden verworfen."""
        try:
            raw = json.loads(self._iperf3_state_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return {}
        if not isinstance(raw, dict):
            return {}
        now = time.time()
        return {
            str(ssid): float(ts) for ssid, ts in raw.items()
            if isinstance(ts, (int, float)) and ts <= now + 60
        }

    def _save_iperf3_state(self) -> None:
        """Atomar schreiben; ein Fehler kostet nur die Merkfaehigkeit ueber
        einen Neustart, nie den Test."""
        try:
            tmp = self._iperf3_state_path.with_suffix(".tmp")
            tmp.write_text(json.dumps(self._last_iperf3), encoding="utf-8")
            os.replace(tmp, self._iperf3_state_path)
        except OSError:
            log.debug("iperf3-Zeitpunkte nicht speicherbar (%s)", self._iperf3_state_path, exc_info=True)

    def _wait_for_next_cycle(self) -> None:
        """Wartet bis zum naechsten Intervall, kann aber per Button 3
        (DisplayLoop setzt trigger_event) vorzeitig abgebrochen werden -
        fuer Vor-Ort-Diagnose direkt am Geraet statt bis zu 15 Minuten auf
        den naechsten Testlauf zu warten."""
        if self._trigger_event is None:
            self._stop_event.wait(self._interval)
            return
        waited = 0.0
        poll = 0.5
        while (
            waited < self._interval
            and not self._stop_event.is_set()
            and not self._trigger_event.is_set()
        ):
            time.sleep(poll)
            waited += poll
        self._forced_cycle = self._trigger_event.is_set()
        self._trigger_event.clear()


# Gilt, wenn in der config.yaml unter interface kein "country" steht.
DEFAULT_COUNTRY = "DE"


def _country(cfg: dict) -> str:
    """Ländereinstellung aus interface.country (siehe config.example.yaml)."""
    value = (cfg.get("interface") or {}).get("country", DEFAULT_COUNTRY)
    # YAML-Fallen bei fehlenden Anführungszeichen: NO (Norwegen) liest YAML
    # als false, 00 als die Zahl 0.
    if value is False:
        return "NO"
    if value is None or value == 0:
        return ""
    return str(value)


def main() -> None:
    parser = argparse.ArgumentParser(description="WLANMON-Probe Client")
    parser.add_argument(
        "--config", default="/etc/wlanmon-probe/config.yaml", help="Pfad zur Config-Datei"
    )
    args = parser.parse_args()

    bootstrap_cfg = load_bootstrap(args.config)
    setup_logging(bootstrap_cfg.get("logging", {}))
    # verify=False (verify_tls: false, Portal-Ziele per IP in bound_http.py)
    # ist immer bewusst gewaehlt - statt einer urllib3-Warnung pro Anfrage im
    # Journal warnt warn_insecure_server() einmal beim Start.
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    warn_insecure_server(bootstrap_cfg.get("server") or {})
    _wait_for_ntp_sync()
    cfg = resolve_initial_config(bootstrap_cfg)
    ensure_regdomain(_country(cfg))
    for note in unblock_wifi():
        log.info("WLAN-Sperre beim Start: %s", note)
    release_from_networkmanager(cfg["interface"]["name"])

    device_id = cfg["device"].get("id") or socket.gethostname()
    # Standortname nur zur Anzeige (Log-Zeile unten, OLED-Status in
    # display.py), kommt mit der Remote-Config vom Dashboard (devices.site_id).
    # Ohne Remote-Config oder ohne zugeordneten Standort leer.
    site = cfg.get("site") or ""
    probe_version = _read_version()

    store = QueueStore(
        db_path=cfg["queue"]["db_path"],
        max_queue_size=cfg["queue"].get("max_queue_size", 100_000),
    )

    stop_event = threading.Event()
    # Gemeinsamer Lock für ScanLoop und ConnectionTestLoop - beide
    # greifen auf denselben physischen Funkadapter zu und dürfen sich
    # nie überlappen (Scannen und Assoziieren gleichzeitig geht nicht).
    wifi_lock = threading.Lock()
    # Von Button 3 (DisplayLoop) gesetzt, um einen sofortigen
    # Connection-Test auszulösen statt auf das nächste Intervall zu warten.
    trigger_test_event = threading.Event()
    status = ProbeStatus(device_id=device_id, site=site)

    def handle_signal(signum, frame):  # noqa: ANN001
        log.info("Signal %s empfangen, fahre herunter...", signum)
        stop_event.set()
        # Der ConfigWatcher wartet auf sein eigenes Ereignis - wecken, damit er
        # nicht bis zum naechsten Poll weiterlaeuft (beim Signal vor seinem
        # Anlegen gibt es noch nichts zu wecken).
        try:
            config_watcher.check_now()
        except NameError:
            pass

    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)

    sender = Sender(
        store=store,
        server_url=cfg["server"]["url"],
        api_key=cfg["server"]["api_key"],
        device_id=device_id,
        probe_version=probe_version,
        verify_tls=cfg["server"].get("verify_tls", True),
        request_timeout=cfg["server"].get("request_timeout", 10),
        flush_interval=cfg["queue"].get("flush_interval_seconds", 15),
        batch_size=cfg["queue"].get("batch_size", 50),
        stop_event=stop_event,
        status=status,
        auto_update_info=lambda: _auto_update_info(args.config),
    )
    scan_loop = ScanLoop(cfg, store, stop_event, wifi_lock, status=status)
    test_loop = ConnectionTestLoop(
        cfg, store, stop_event, wifi_lock, status=status, trigger_event=trigger_test_event
    )
    config_watcher = ConfigWatcher(bootstrap_cfg, cfg, stop_event)

    # Heartbeat mit Systemwerten: Standard an, alle 60 s; per zentraler
    # Konfiguration (Schluessel "heartbeat") je Probe abschalt- und einstellbar.
    hb_cfg = cfg.get("heartbeat") or {}
    heartbeat = None
    if hb_cfg.get("enabled", True):
        try:
            hb_interval = min(3600, max(10, int(hb_cfg.get("interval_seconds") or 60)))
        except (TypeError, ValueError):
            hb_interval = 60
        heartbeat = Heartbeat(
            server_url=cfg["server"]["url"],
            api_key=cfg["server"]["api_key"],
            device_id=device_id,
            probe_version=probe_version,
            verify_tls=cfg["server"].get("verify_tls", True),
            request_timeout=cfg["server"].get("request_timeout", 10),
            interval=hb_interval,
            stop_event=stop_event,
            store=store,
            on_config_changed=config_watcher.check_now,
            interface=cfg["interface"]["name"],
        )

    display_cfg = cfg.get("display", {})
    display_loop = None
    if display_cfg.get("enabled"):
        display_loop = DisplayLoop(display_cfg, status, stop_event, trigger_test_event)

    log.info("WLANMON-Probe startet (device_id=%s, site=%s, version=%s)", device_id, site, probe_version)
    sender.start()
    scan_loop.start()
    test_loop.start()
    config_watcher.start()
    if heartbeat is not None:
        heartbeat.start()
    if display_loop is not None:
        display_loop.start()

    last_prune = time.monotonic()
    while not stop_event.is_set():
        stop_event.wait(30)
        if time.monotonic() - last_prune > 3600:
            store.prune_sent()
            last_prune = time.monotonic()

    sender.join(timeout=10)
    scan_loop.join(timeout=10)
    test_loop.join(timeout=10)
    config_watcher.join(timeout=10)
    if heartbeat is not None:
        heartbeat.join(timeout=10)
    if display_loop is not None:
        display_loop.join(timeout=10)
    store.close()
    log.info("WLANMON-Probe beendet")


if __name__ == "__main__":
    main()
