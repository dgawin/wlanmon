"""
Sender-Thread: liest unbestätigte Einträge aus der lokalen Queue und
überträgt sie in Batches per HTTPS an die Server-API. Bei Fehlern
(Netzwerk, Server nicht erreichbar, 5xx) bleibt der Eintrag in der
Queue und wird beim nächsten Zyklus erneut versucht.
"""

from __future__ import annotations

import glob
import logging
import os
import shutil
import subprocess
import threading
import time
from pathlib import Path
from typing import Callable

import requests

from probe_status import ProbeStatus
from queue_store import QueueStore
from wifi_ops import capture_files, capture_pending, capture_remove

log = logging.getLogger("wlanmon_probe.sender")


class Sender(threading.Thread):
    def __init__(
        self,
        store: QueueStore,
        server_url: str,
        api_key: str,
        device_id: str,
        probe_version: str,
        verify_tls: bool,
        request_timeout: int,
        flush_interval: int,
        batch_size: int,
        stop_event: threading.Event,
        status: ProbeStatus | None = None,
        auto_update_info: Callable[[], dict] | None = None,
    ):
        super().__init__(name="sender", daemon=True)
        self._store = store
        self._url = server_url.rstrip("/") + "/measurements"
        self._captures_url = f"{server_url.rstrip('/')}/devices/{device_id}/captures/"
        self._api_key = api_key
        self._captures_unsupported_logged = False
        self._headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        }
        self._device_id = device_id
        self._probe_version = probe_version
        self._verify_tls = verify_tls
        self._timeout = request_timeout
        self._flush_interval = flush_interval
        self._batch_size = batch_size
        self._stop_event = stop_event
        self._status = status
        # Liefert den aktuellen Auto-Update-Stand (an/aus, Branch, Repo) fuer
        # die Anzeige im Dashboard - je Batch neu, siehe main._auto_update_info().
        self._auto_update_info = auto_update_info
        self._session = requests.Session()

    def run(self) -> None:
        backoff = 1
        max_backoff = 120
        while not self._stop_event.is_set():
            sent_any = self._flush_once()
            # Erst die Messwerte (mit capture_id), dann die Mitschnitte dazu.
            if not self._store.fetch_unsent(1):
                self._upload_captures()
            if sent_any:
                backoff = 1
                wait = self._flush_interval
            else:
                wait = min(backoff, max_backoff)
                backoff *= 2
            self._stop_event.wait(wait)

    def _flush_once(self) -> bool:
        batch = self._store.fetch_unsent(self._batch_size)
        if not batch:
            return False

        ids = [row[0] for row in batch]
        payload = {
            "device_id": self._device_id,
            "probe_version": self._probe_version,
            "measurements": [
                {"id": row[0], "kind": row[1], "data": row[2]} for row in batch
            ],
        }
        if self._auto_update_info is not None:
            try:
                payload["auto_update"] = self._auto_update_info()
            except Exception:
                log.debug("Auto-Update-Info nicht ermittelbar", exc_info=True)

        self._store.mark_attempt(ids)
        try:
            resp = self._session.post(
                self._url,
                json=payload,
                headers=self._headers,
                timeout=self._timeout,
                verify=self._verify_tls,
            )
        except requests.RequestException as exc:
            log.warning("Übertragung fehlgeschlagen (%s), verbleibt in Queue", exc)
            if self._status is not None:
                self._status.update_sender_error(str(exc))
            return False

        if 200 <= resp.status_code < 300:
            self._store.mark_sent(ids)
            log.info("Batch mit %d Einträgen erfolgreich übertragen", len(ids))
            if self._status is not None:
                self._status.update_sender_success()
            return True

        log.warning(
            "Server lehnte Batch ab (HTTP %d): %s", resp.status_code, resp.text[:300]
        )
        if self._status is not None:
            self._status.update_sender_error(f"HTTP {resp.status_code}")
        return False

    def _upload_captures(self) -> None:
        """Mitschnitte fehlgeschlagener Tests (FailureCapture in wifi_ops.py) hochladen, aelteste
        zuerst; geloescht wird erst nach einer 2xx-Antwort. Bei einem Fehler
        abbrechen und im naechsten Zyklus erneut versuchen."""
        for capture_id in capture_pending()[:5]:
            pcap, events, wpa_log = capture_files(capture_id)
            files = {"events": ("events.txt", events.read_bytes() if events.exists() else b"", "text/plain")}
            if wpa_log is not None:
                files["wpa_log"] = ("wpa_supplicant.txt", wpa_log.read_bytes(), "text/plain")
            if pcap is not None:
                files["pcap"] = (f"{capture_id}.pcap", pcap.read_bytes(), "application/vnd.tcpdump.pcap")
            try:
                resp = self._session.post(
                    self._captures_url + capture_id,
                    files=files,
                    headers={"Authorization": f"Bearer {self._api_key}"},
                    timeout=max(self._timeout, 30),
                    verify=self._verify_tls,
                )
            except requests.RequestException as exc:
                log.warning("Mitschnitt %s nicht uebertragen (%s), neuer Versuch spaeter", capture_id, exc)
                return
            if 200 <= resp.status_code < 300:
                capture_remove(capture_id)
                log.info("Mitschnitt %s uebertragen", capture_id)
            elif resp.status_code == 413:
                capture_remove(capture_id)
                log.warning("Mitschnitt %s vom Server als zu gross abgelehnt - verworfen", capture_id)
            elif resp.status_code in (404, 405):
                # Dashboard kennt den Endpunkt noch nicht (aelter als 1.0.1.48):
                # liegen lassen, das Spool-Verzeichnis begrenzt sich selbst.
                if not self._captures_unsupported_logged:
                    log.warning("Dashboard nimmt noch keine Mitschnitte an (HTTP %d) - bleiben vorerst liegen",
                                resp.status_code)
                    self._captures_unsupported_logged = True
                return
            elif resp.status_code == 400:
                # Inhalt ungueltig - ein neuer Versuch aendert daran nichts, und
                # liegen gelassen wuerde er alle spaeteren Mitschnitte blockieren.
                capture_remove(capture_id)
                log.warning("Mitschnitt %s abgelehnt (HTTP 400: %s) - verworfen", capture_id, resp.text[:200])
            else:
                # 401 (API-Key), 5xx usw.: liegen lassen, spaeter erneut versuchen.
                log.warning("Mitschnitt %s abgelehnt (HTTP %d): %s", capture_id, resp.status_code, resp.text[:200])
                return


# --------------------------------------------------------------------------
# Heartbeat mit Systemwerten (Probe Health)
# --------------------------------------------------------------------------

def _read_first_line(path: str) -> str | None:
    try:
        with open(path, encoding="ascii", errors="replace") as fh:
            return fh.readline().strip()
    except OSError:
        return None


def _cpu_times() -> tuple[int, int] | None:
    """(gesamt, untaetig) aus der ersten Zeile von /proc/stat - die CPU-Last
    in Prozent ergibt sich aus der Differenz zweier Aufrufe."""
    line = _read_first_line("/proc/stat")
    if not line or not line.startswith("cpu "):
        return None
    try:
        values = [int(v) for v in line.split()[1:9]]
    except ValueError:
        return None
    idle = values[3] + (values[4] if len(values) > 4 else 0)   # idle + iowait
    return sum(values), idle


def _meminfo() -> dict[str, int]:
    out: dict[str, int] = {}
    try:
        with open("/proc/meminfo", encoding="ascii") as fh:
            for line in fh:
                key, _, rest = line.partition(":")
                if key in ("MemTotal", "MemAvailable"):
                    out[key] = int(rest.split()[0])   # kB
    except (OSError, ValueError, IndexError):
        pass
    return out


def _temperature_c() -> float | None:
    """Hoechste Temperatur aller Thermal-Zonen (SoC/CPU), in Grad Celsius."""
    temps = []
    for zone in glob.glob("/sys/class/thermal/thermal_zone*/temp"):
        raw = _read_first_line(zone)
        try:
            value = int(raw) / 1000 if raw else None
        except ValueError:
            value = None
        if value is not None and -40 < value < 150:   # unplausible Sensoren ignorieren
            temps.append(value)
    return round(max(temps), 1) if temps else None


def _vcgencmd(*args: str) -> str | None:
    """Ausgabe von vcgencmd (Raspberry-Pi-Firmware) oder None, wenn es das
    Programm nicht gibt oder der Befehl auf diesem Modell fehlschlaegt."""
    if not shutil.which("vcgencmd"):
        return None
    try:
        res = subprocess.run(["vcgencmd", *args], capture_output=True, text=True, timeout=5)
    except (OSError, subprocess.SubprocessError):
        return None
    return res.stdout.strip() if res.returncode == 0 else None


def _throttled() -> int | None:
    """Raspberry Pi: Unterspannung/Drosselung laut Firmware (Bitmaske von
    "vcgencmd get_throttled", 0 = alles in Ordnung). Auf anderen Boards None."""
    if not shutil.which("vcgencmd"):
        return None
    try:
        res = subprocess.run(["vcgencmd", "get_throttled"], capture_output=True, text=True, timeout=5)
        return int(res.stdout.strip().split("=")[1], 16)
    except (OSError, subprocess.SubprocessError, IndexError, ValueError):
        return None


def _power_info() -> dict:
    """Stromversorgung, soweit erkennbar: ein aufgesteckter HAT mit ID-Speicher
    (z.B. "Raspberry Pi PoE+ HAT", aus dem Device-Tree) und eine PoE-Stromquelle
    des Kernels (/sys/class/power_supply/*poe*). PoE ueber einen Splitter
    (PoE rein, USB-C raus) sieht fuer das Board aus wie ein Netzteil und ist
    nicht erkennbar."""
    info: dict = {}
    try:
        product = Path("/proc/device-tree/hat/product").read_bytes().rstrip(b"\0").decode("utf-8", "replace").strip()
    except OSError:
        product = ""
    if product:
        info["hat"] = product[:80]
    for supply in sorted(Path("/sys/class/power_supply").glob("*")):
        if "poe" in supply.name.lower():
            info["poe_online"] = _read_first_line(str(supply / "online")) == "1"
            break
    if info.get("poe_online") or "poe" in info.get("hat", "").lower():
        info["power_source"] = "poe_hat"

    # Raspberry Pi 5: Spannung am 5-V-Eingang (PMIC) und der Strom, den die
    # Firmware der Quelle zutraut (USB-PD-Aushandlung, sonst 3 A). Bei 3 A
    # begrenzt der Pi 5 die USB-Ports zusammen auf 600 mA - mit einem USB-
    # WLAN-Adapter kann das zu wenig sein. Ein PoE-HAT an den GPIO-Pins
    # (z.B. Waveshare PoE HAT G) kann nicht aushandeln: dann PSU_MAX_CURRENT
    # im Bootloader setzen (rpi-eeprom-config). Auf anderen Boards fehlen die Werte.
    adc = _vcgencmd("pmic_read_adc", "EXT5V_V")
    if adc and "=" in adc:
        try:
            info["ext5v_volts"] = round(float(adc.rsplit("=", 1)[1].rstrip("V")), 2)
        except ValueError:
            pass
    try:
        raw = Path("/proc/device-tree/chosen/power/max_current").read_bytes()
        if len(raw) >= 4:
            info["psu_max_current_ma"] = int.from_bytes(raw[:4], "big")
    except OSError:
        pass
    usb_max = _vcgencmd("get_config", "usb_max_current_enable")
    if usb_max and "=" in usb_max:
        info["usb_max_current_enable"] = usb_max.rsplit("=", 1)[1].strip() == "1"
    return info


def _is_usb_interface(interface: str | None) -> bool | None:
    """Haengt das Netz-Interface am USB-Bus (z.B. mt7921u-Stick)? None, wenn unbekannt."""
    if not interface:
        return None
    try:
        return "/usb" in str(Path(f"/sys/class/net/{interface}/device").resolve())
    except OSError:
        return None


def collect_health(
    store: QueueStore | None, previous_cpu: tuple[int, int] | None, interface: str | None = None
) -> tuple[dict, tuple[int, int] | None]:
    """Systemwerte der Probe, nur aus /proc, /sys und der Standardbibliothek.
    Liefert (Werte, CPU-Zaehlerstand fuer den naechsten Aufruf)."""
    health: dict = {}
    cpu = _cpu_times()
    if cpu and previous_cpu and cpu[0] > previous_cpu[0]:
        total, idle = cpu[0] - previous_cpu[0], cpu[1] - previous_cpu[1]
        health["cpu_percent"] = round(100.0 * (total - idle) / total, 1)
    try:
        health["load"] = [round(v, 2) for v in os.getloadavg()]
    except (OSError, AttributeError):
        pass
    health["cpu_count"] = os.cpu_count()
    mem = _meminfo()
    if mem.get("MemTotal"):
        total_mb = mem["MemTotal"] / 1024
        avail_mb = mem.get("MemAvailable", 0) / 1024
        health.update(mem_total_mb=round(total_mb), mem_available_mb=round(avail_mb),
                      mem_used_percent=round(100.0 * (total_mb - avail_mb) / total_mb, 1))
    try:
        st = os.statvfs("/")
        total_b, free_b = st.f_blocks * st.f_frsize, st.f_bavail * st.f_frsize
        if total_b:
            health.update(disk_total_mb=round(total_b / 2**20), disk_free_mb=round(free_b / 2**20),
                          disk_used_percent=round(100.0 * (total_b - free_b) / total_b, 1))
    except (OSError, AttributeError):
        pass
    temp = _temperature_c()
    if temp is not None:
        health["temperature_c"] = temp
    uptime = _read_first_line("/proc/uptime")
    if uptime:
        try:
            health["uptime_seconds"] = int(float(uptime.split()[0]))
        except (ValueError, IndexError):
            pass
    throttled = _throttled()
    if throttled is not None:
        health["throttled"] = throttled
    health.update(_power_info())
    wifi_usb = _is_usb_interface(interface)
    if wifi_usb is not None:
        health["wifi_usb"] = wifi_usb
    if store is not None:
        try:
            health["queue_unsent"] = store.count_unsent()
        except Exception:
            log.debug("Queue-Laenge nicht ermittelbar", exc_info=True)
    return health, cpu


class Heartbeat(threading.Thread):
    """Lebenszeichen an das Dashboard im festen Takt, auch ohne Messdaten -
    sonst haengt "zuletzt gesehen" am Messtakt. Schickt die Systemwerte mit
    (collect_health) und erfaehrt aus der Antwort, ob sich die zentrale
    Konfiguration geaendert hat (config_version): dann holt der ConfigWatcher
    sie sofort statt beim naechsten Poll."""

    def __init__(
        self,
        server_url: str,
        api_key: str,
        device_id: str,
        probe_version: str,
        verify_tls: bool,
        request_timeout: int,
        interval: int,
        stop_event: threading.Event,
        store: QueueStore | None = None,
        on_config_changed: Callable[[], None] | None = None,
        interface: str | None = None,
    ):
        super().__init__(name="heartbeat", daemon=True)
        self._interface = interface
        self._url = f"{server_url.rstrip('/')}/devices/{device_id}/heartbeat"
        self._headers = {"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"}
        self._device_id = device_id
        self._probe_version = probe_version
        self._verify_tls = verify_tls
        self._timeout = request_timeout
        self._interval = interval
        self._stop_event = stop_event
        self._store = store
        self._on_config_changed = on_config_changed
        self._session = requests.Session()
        self._cpu: tuple[int, int] | None = _cpu_times()
        self._config_version: str | None = None
        self._unsupported_logged = False

    def run(self) -> None:
        # Erster Heartbeat nach kurzer Pause: dann hat die CPU-Messung eine
        # sinnvolle Differenz, und das Dashboard sieht den Start sofort.
        wait: float = min(self._interval, 10)
        while not self._stop_event.wait(wait):
            try:
                wait = self._beat()
            except Exception:
                log.exception("Heartbeat fehlgeschlagen")
                wait = self._interval

    def _beat(self) -> float:
        """Ein Heartbeat; liefert die Wartezeit bis zum naechsten."""
        health, self._cpu = collect_health(self._store, self._cpu, self._interface)
        try:
            resp = self._session.post(
                self._url,
                json={"device_id": self._device_id, "probe_version": self._probe_version, "health": health},
                headers=self._headers,
                timeout=self._timeout,
                verify=self._verify_tls,
            )
        except requests.RequestException as exc:
            log.debug("Heartbeat nicht zugestellt: %s", exc)
            return self._interval
        if resp.status_code in (404, 405):
            # Dashboard kennt den Endpunkt noch nicht: selten nachfragen, einmal melden.
            if not self._unsupported_logged:
                log.info("Dashboard kennt noch keinen Heartbeat (HTTP %d) - neuer Versuch alle 10 min",
                         resp.status_code)
                self._unsupported_logged = True
            return 600
        if not 200 <= resp.status_code < 300:
            log.warning("Heartbeat abgelehnt (HTTP %d): %s", resp.status_code, resp.text[:200])
            return self._interval
        try:
            version = (resp.json() or {}).get("config_version")
        except ValueError:
            version = None
        if isinstance(version, str) and version:
            if self._config_version is not None and version != self._config_version and self._on_config_changed:
                log.info("Dashboard meldet geaenderte Konfiguration - wird sofort abgerufen")
                self._on_config_changed()
            self._config_version = version
        return self._interval
