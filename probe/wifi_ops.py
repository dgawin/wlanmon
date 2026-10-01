"""
Low-Level WLAN-Operationen für den Probe-Client.

Bewusst ohne NetworkManager/nmcli implementiert - ein zusätzlicher
NetworkManager- oder ifplugd-verwalteter Zugriff auf dasselbe Interface
würde mit der direkten wpa_supplicant/iw-Steuerung hier kollidieren
(siehe setup_wlanmon_probe.sh, das solche Fremdverwaltung deaktiviert).

Benötigt Root-Rechte (iw, wpa_supplicant, dhclient greifen auf
Netzwerk-Interfaces auf niedriger Ebene zu).
"""

from __future__ import annotations

import http.client
import json
import logging
import os
import re
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

from bound_http import BoundHTTPConnection
from portals import PortalContext, select_module

log = logging.getLogger("wlanmon_probe.wifi_ops")


class WifiOpsError(RuntimeError):
    pass


def _utc_iso() -> str:
    """Aktueller Zeitpunkt in UTC als ISO-8601 (wie die Messwert-Zeitstempel)."""
    return datetime.now(timezone.utc).isoformat()


def _run(cmd: list[str], timeout: int = 20, check: bool = True) -> subprocess.CompletedProcess:
    log.debug("exec: %s", " ".join(cmd))
    try:
        result = subprocess.run(
            cmd, capture_output=True, text=True, timeout=timeout
        )
    except FileNotFoundError as exc:
        # Faellt an, wenn das Programm selbst fehlt (z.B. dhclient auf
        # einem System, das nur dhcpcd installiert hat) - ohne das hier
        # abzufangen, wuerde das den ganzen Connection-Test mit einer
        # unbehandelten Exception abbrechen, obwohl check=False dem
        # Aufrufer eigentlich "mir ist ein Fehlschlag hier egal" sagt.
        # 127 wie in der Shell ueblich fuer "command not found".
        if check:
            raise WifiOpsError(f"Programm nicht gefunden: {cmd[0]}") from exc
        return subprocess.CompletedProcess(cmd, 127, "", str(exc))
    if check and result.returncode != 0:
        raise WifiOpsError(
            f"Befehl fehlgeschlagen ({result.returncode}): {' '.join(cmd)}\n"
            f"stderr: {result.stderr.strip()}"
        )
    return result


# --------------------------------------------------------------------------
# Scanning
# --------------------------------------------------------------------------

@dataclass
class ScanResult:
    ssid: str
    bssid: str
    signal_dbm: float | None
    frequency_mhz: int | None
    channel: int | None
    # Aus dem "BSS Load"-Element (802.11e/QBSS) im Beacon, falls der AP es
    # sendet (sonst None): Zahl der assoziierten Clients und die vom AP
    # selbst gemessene Kanalauslastung (iw: "x/255", hier in Prozent).
    station_count: int | None = None
    channel_utilization_pct: float | None = None
    # Faehigkeiten aus den Information Elements, siehe _parse_capabilities().
    wifi_generation: int | None = None      # 4..7 (Wi-Fi 4 = 11n ... 7 = 11be), None = nur a/b/g
    channel_width_mhz: int | None = None    # 20/40/80/160, None = nicht ermittelbar
    # Mitte des belegten Kanalblocks (bei 40/80/160 MHz nicht der Hauptkanal),
    # fuer die Spektrumansicht im Dashboard. None = wie frequency_mhz.
    center_freq_mhz: int | None = None
    security: str | None = None             # z.B. "WPA2/WPA3-Personal", "WPA2-Enterprise", "Open"
    pmf: str | None = None                  # "required" | "optional" | None (kein PMF)
    akm: list[str] = field(default_factory=list)  # RSN-Authentication-Suites wie von iw
    rrm_11k: bool = False                   # 802.11k Radio Measurement
    neighbor_report: bool = False           # 802.11k Neighbor Report (Roaming-Hilfe)
    btm_11v: bool = False                   # 802.11v BSS Transition Management
    ft_11r: bool = False                    # 802.11r Fast Transition (FT-AKM im RSN)


_CELL_RE = re.compile(r"^BSS (?P<bssid>[0-9a-f:]{17})")
_SIGNAL_RE = re.compile(r"^\s*signal:\s*(?P<signal>-?\d+(\.\d+)?)\s*dBm")
_FREQ_RE = re.compile(r"^\s*freq:\s*(?P<freq>\d+)")
_SSID_RE = re.compile(r"^\s*SSID:\s*(?P<ssid>.*)$")
# Zeilen aus dem "BSS Load:"-Block, kommen nur dort vor.
_STATION_COUNT_RE = re.compile(r"^\s*\*\s*station count:\s*(?P<count>\d+)")
_CHAN_UTIL_RE = re.compile(r"^\s*\*\s*channel utilisation:\s*(?P<util>\d+)/255")


_IW_ESCAPE_RE = re.compile(r"\\x([0-9a-fA-F]{2})")


def _decode_iw_ssid(raw: str) -> str:
    """
    `iw` gibt SSIDs maskiert aus: jedes nicht druckbare Byte (auch jedes
    Nicht-ASCII-Byte, z.B. UTF-8-Umlaute), ein Backslash und Leerzeichen am
    Anfang/Ende erscheinen als "\\xNN" - "Büro" also als "B\\xc3\\xbcro".
    Da ein echter Backslash selbst als \\x5c kommt, ist jedes "\\x" eindeutig
    eine Maskierung. Verborgene Netze, die statt einer leeren SSID lauter
    Null-Bytes senden, werden zu "" (wie eine leere SSID).
    """
    data = bytearray()
    pos = 0
    for m in _IW_ESCAPE_RE.finditer(raw):
        data += raw[pos:m.start()].encode("utf-8")
        data.append(int(m.group(1), 16))
        pos = m.end()
    data += raw[pos:].encode("utf-8")
    if data and not data.strip(b"\x00"):
        return ""
    return data.decode("utf-8", errors="replace")


def _freq_to_channel(freq_mhz: int) -> int | None:
    if 2412 <= freq_mhz <= 2472:
        return (freq_mhz - 2412) // 5 + 1
    if freq_mhz == 2484:
        return 14
    if 5000 <= freq_mhz <= 5900:
        return (freq_mhz - 5000) // 5
    if 5955 <= freq_mhz <= 7115:  # 6 GHz (Wi-Fi 6E)
        return (freq_mhz - 5950) // 5
    return None


def scan(interface: str, timeout: int = 30) -> list[ScanResult]:
    """Führt einen aktiven Scan über `iw` durch und parst die Ergebnisse."""
    _run(["ip", "link", "set", interface, "up"], check=False)
    result = _scan_with_retry(interface, timeout)
    return _parse_scan_output(result.stdout)


# Erste "country XX:"-Zeile von `iw reg get` = globale Einstellung.
_REGDOM_COUNTRY_RE = re.compile(r"^country ([0-9A-Z]{2}):", re.MULTILINE)


def ensure_regdomain(country: str | None) -> None:
    """Ländereinstellung (Regulatory Domain) auf `country` setzen, falls sie
    abweicht. Ohne Land ("country 00", Welt-Minimum) darf der Adapter auf
    5 GHz nur passiv scannen und auf den Kanälen 12/13 keine Verbindung
    aufbauen. Leer oder "00" = Systemeinstellung nicht anfassen.
    Günstig genug, um es vor jedem Scan aufzurufen (setzt nur bei Abweichung)."""
    cc = (country or "").strip().upper()
    if cc in ("", "00"):
        return
    if not re.fullmatch(r"[A-Z]{2}", cc):
        log.warning("interface.country %r ist kein Ländercode (z.B. DE, AT, CH) - bleibt unverändert", country)
        return
    current = _REGDOM_COUNTRY_RE.search(_run(["iw", "reg", "get"], check=False, timeout=10).stdout or "")
    if current and current.group(1) == cc:
        return
    res = _run(["iw", "reg", "set", cc], check=False, timeout=10)
    if res.returncode != 0:
        log.warning("Ländereinstellung %s liess sich nicht setzen: %s", cc, (res.stderr or "").strip())
        return
    log.info("Ländereinstellung (Regulatory Domain) auf %s gesetzt (vorher %s)",
             cc, current.group(1) if current else "unbekannt")


def _parse_scan_output(text: str) -> list[ScanResult]:
    """Ausgabe von `iw dev <if> scan` bzw. `scan dump` in ScanResults."""
    results: list[ScanResult] = []
    current: dict | None = None

    for line in text.splitlines():
        m = _CELL_RE.match(line)
        if m:
            if current is not None:
                results.append(_finalize_cell(current))
            current = {"bssid": m.group("bssid"), "ssid": "", "signal": None, "freq": None, "lines": []}
            continue
        if current is None:
            continue
        current["lines"].append(line)
        m = _SIGNAL_RE.match(line)
        if m:
            current["signal"] = float(m.group("signal"))
            continue
        m = _FREQ_RE.match(line)
        if m:
            current["freq"] = int(m.group("freq"))
            continue
        m = _SSID_RE.match(line)
        if m:
            current["ssid"] = _decode_iw_ssid(m.group("ssid").strip())
            continue
        m = _STATION_COUNT_RE.match(line)
        if m:
            current["station_count"] = int(m.group("count"))
            continue
        m = _CHAN_UTIL_RE.match(line)
        if m:
            current["channel_utilization"] = int(m.group("util"))
            continue

    if current is not None:
        results.append(_finalize_cell(current))

    return results


def _scan_with_retry(
    interface: str, timeout: int, attempts: int = 3, retry_delay: float = 2.0
) -> subprocess.CompletedProcess:
    """
    `iw scan` mit kurzem Retry bei "Device or resource busy". Direkt nach
    einem Connection-Test (wpa_supplicant/dhclient gerade erst beendet)
    braucht der Treiber manchmal einen kurzen Moment, um die Schnittstelle
    intern wieder freizugeben, bevor ein neuer Scan möglich ist - unser
    wifi_lock verhindert nur die Überschneidung der eigenen Threads,
    nicht diese kurze treiberinterne Nachlaufzeit.
    """
    last_error: WifiOpsError | None = None
    for attempt in range(1, attempts + 1):
        try:
            return _run(["iw", "dev", interface, "scan"], timeout=timeout)
        except WifiOpsError as exc:
            last_error = exc
            if "busy" not in str(exc).lower() or attempt == attempts:
                raise
            log.warning(
                "Scan auf %s meldet 'busy' (Versuch %d/%d), erneuter Versuch in %.0fs",
                interface, attempt, attempts, retry_delay,
            )
            time.sleep(retry_delay)
    raise last_error  # unreachable, aber für Typprüfer


def _finalize_cell(cell: dict) -> ScanResult:
    freq = cell.get("freq")
    util = cell.get("channel_utilization")
    return ScanResult(
        ssid=cell.get("ssid") or "",
        bssid=cell["bssid"],
        signal_dbm=cell.get("signal"),
        frequency_mhz=freq,
        channel=_freq_to_channel(freq) if freq else None,
        station_count=cell.get("station_count"),
        channel_utilization_pct=round(util * 100 / 255, 1) if util is not None else None,
        **_parse_capabilities(cell.get("lines") or [], freq),
    )


def _ie_sections(lines: list[str]) -> dict[str, str]:
    """
    Zerlegt den `iw scan`-Block eines BSS in Abschnitte je Information
    Element: Kopfzeilen stehen mit genau einem Tab eingerueckt ("\\tRSN:\\t *
    Version: 1"), alles tiefer Eingerueckte gehoert zum Abschnitt davor.
    Rueckgabe: Kopf (z.B. "RSN", "HT operation") -> gesamter Text des
    Abschnitts. Bei doppelten Koepfen gewinnt der erste.
    """
    sections: dict[str, str] = {}
    head: str | None = None
    buf: list[str] = []
    for line in lines:
        if line.startswith("\t") and not line.startswith("\t\t") and ":" in line:
            if head is not None:
                sections.setdefault(head, "\n".join(buf))
            head, rest = line.strip().split(":", 1)
            buf = [rest]
        elif head is not None:
            buf.append(line)
    if head is not None:
        sections.setdefault(head, "\n".join(buf))
    return sections


def _parse_capabilities(lines: list[str], freq_mhz: int | None = None) -> dict:
    """
    Faehigkeiten eines BSS aus den von `iw scan` dekodierten Information
    Elements (Grundlage fuer Roaming-/Sicherheitsanalysen):

    - Wi-Fi-Generation: EHT/HE/VHT/HT capabilities vorhanden -> 7/6/5/4
    - Kanalbreite: VHT operation (80/160, auch 160 per CCFS1-Signalisierung),
      sonst HT operation (40 bei secondary offset + "any"), sonst 20
    - Sicherheit/PMF: RSN-Authentication-Suites und MFP-Bits; ohne RSN: WPA,
      WEP (Privacy-Bit) oder Open
    - 802.11k: RM enabled capabilities (Neighbor Report separat), 802.11v:
      "BSS Transition" in den Extended capabilities, 802.11r: FT-AKM im RSN
      (zuverlaessiger als das Mobility-Domain-Element, das nicht jede
      iw-Version ausgibt)
    """
    s = _ie_sections(lines)
    caps: dict = {}

    gen = None
    for head, g in (("EHT capabilities", 7), ("HE capabilities", 6),
                    ("VHT capabilities", 5), ("HT capabilities", 4)):
        if head in s:
            gen = g
            break
    # VHT auf 2,4 GHz ist kein Wi-Fi 5 (802.11ac gibt es nur auf 5 GHz),
    # sondern die herstellerspezifische 256-QAM-Erweiterung ("TurboQAM"),
    # die viele APs dort trotzdem ankuendigen - fuer Clients Wi-Fi 4.
    if gen == 5 and freq_mhz is not None and freq_mhz < 3000:
        gen = 4
    caps["wifi_generation"] = gen

    width = None
    center = None
    vht = s.get("VHT operation", "")
    m = re.search(r"channel width:\s*(\d)", vht)
    if m and m.group(1) in ("1", "2", "3"):
        width = 160 if m.group(1) in ("2", "3") else 80
        seg1 = re.search(r"center freq segment 1:\s*(\d+)", vht)
        seg2 = re.search(r"center freq segment 2:\s*(\d+)", vht)
        ccfs0 = int(seg1.group(1)) if seg1 else 0
        ccfs1 = int(seg2.group(1)) if seg2 else 0
        # Segmente sind Kanalnummern im 5-GHz-Raster (5000 + 5 * Kanal).
        if ccfs0:
            center = 5000 + 5 * ccfs0
        if width == 80 and ccfs1 and abs(ccfs1 - ccfs0) == 8:
            # 160 MHz per CCFS1-Signalisierung: CCFS1 ist die 160-MHz-Mitte.
            width = 160
            center = 5000 + 5 * ccfs1
    if width is None and "HT operation" in s:
        ht = s["HT operation"]
        wide = re.search(r"STA channel width:\s*any", ht)
        offset = re.search(r"secondary channel offset:\s*(above|below)", ht)
        width = 40 if (wide and offset) else 20
        if width == 40 and freq_mhz is not None:
            center = freq_mhz + (10 if offset.group(1) == "above" else -10)
    caps["channel_width_mhz"] = width
    caps["center_freq_mhz"] = center if center is not None else freq_mhz

    rsn = s.get("RSN")
    akm: list[str] = []
    pmf = None
    if rsn is not None:
        m = re.search(r"Authentication suites:\s*(.+)", rsn)
        if m:
            # iw schreibt die Enterprise-Suites mit Leerzeichen ("IEEE 802.1X",
            # "FT/IEEE 802.1X", "IEEE 802.1X/SHA-256") - vor dem Aufteilen
            # zusammenziehen, sonst zerfallen sie in zwei Teile.
            akm = m.group(1).replace("IEEE 802.1X", "802.1X").split()
        if "MFP-required" in rsn:
            pmf = "required"
        elif "MFP-capable" in rsn:
            pmf = "optional"
    caps["akm"] = akm
    caps["pmf"] = pmf

    base = {a.removeprefix("FT/") for a in akm}
    sae = any(a.startswith("SAE") for a in base)
    psk = any(a.startswith("PSK") for a in base)
    dot1x = [a for a in base if a.startswith("802.1X")]
    if rsn is not None:
        if "802.1X/SUITE-B-192" in base:
            security = "WPA3-Enterprise 192"
        elif dot1x:
            security = "WPA3-Enterprise" if pmf == "required" else "WPA2-Enterprise"
        elif sae and psk:
            security = "WPA2/WPA3-Personal"
        elif sae:
            security = "WPA3-Personal"
        elif psk:
            security = "WPA2-Personal"
        elif "OWE" in base:
            security = "OWE"
        else:
            security = "RSN (" + " ".join(akm) + ")" if akm else "RSN"
    elif "WPA" in s:
        security = "WPA"
    elif re.search(r"\bPrivacy\b", s.get("capability", "")):
        security = "WEP"
    else:
        security = "Open"
    caps["security"] = security

    rm = s.get("RM enabled capabilities")
    caps["rrm_11k"] = rm is not None or bool(re.search(r"\bRadioMeasure\b", s.get("capability", "")))
    caps["neighbor_report"] = rm is not None and "Neighbor Report" in rm
    caps["btm_11v"] = "BSS Transition" in s.get("Extended capabilities", "")
    caps["ft_11r"] = any(a.startswith("FT/") for a in akm)
    return caps


@dataclass
class ChannelSurvey:
    frequency_mhz: int
    channel: int | None
    in_use: bool
    noise_dbm: int | None
    active_ms: int | None
    busy_ms: int | None
    receive_ms: int | None
    transmit_ms: int | None
    # busy_ms / active_ms in Prozent: Anteil der Zeit, in der das eigene
    # Radio den Kanal als belegt erkannt hat (fremde + eigene Frames,
    # Stoerungen oberhalb der CCA-Schwelle).
    busy_pct: float | None


_SURVEY_FREQ_RE = re.compile(r"^\s*frequency:\s*(?P<freq>\d+)\s*MHz(?P<rest>.*)$")
_SURVEY_NOISE_RE = re.compile(r"^\s*noise:\s*(?P<v>-?\d+)\s*dBm")
_SURVEY_TIME_RE = re.compile(
    r"^\s*channel (?P<kind>active|busy|receive|transmit) time:\s*(?P<v>\d+)\s*ms"
)


def channel_survey(interface: str) -> list[ChannelSurvey]:
    """
    Kanalbelegung aus Sicht des eigenen Radios (`iw dev <if> survey dump`),
    unabhaengig davon, ob die APs ein BSS-Load-Element senden. Direkt nach
    einem Scan aufrufen: dann liegen Werte fuer alle gescannten Kanaele vor.

    Was die Zeiten umfassen, haengt vom Treiber ab - mt76 (z.B. mt7921u)
    setzt die Zaehler bei jedem Kanalwechsel zurueck, dann beschreibt
    busy/active nur die kurze Verweildauer des letzten Scans auf diesem
    Kanal (eine Momentaufnahme von typischerweise 30-150 ms). Andere
    Treiber summieren seit dem Hochfahren des Interfaces (Langzeitmittel).
    active_ms wird deshalb mitgeschickt, damit die Aussagekraft erkennbar
    bleibt. Kanaele ohne active time (vom Treiber nicht gemessen) fallen
    weg. Fehler fuehren zu einer leeren Liste, nie zum Abbruch des Scans.
    """
    surveys: list[ChannelSurvey] = []
    for entry in _survey_entries(interface):
        active, busy = entry.get("active"), entry.get("busy")
        if not active:
            continue
        freq = entry["freq"]
        surveys.append(ChannelSurvey(
            frequency_mhz=freq,
            channel=_freq_to_channel(freq),
            in_use=entry["in_use"],
            noise_dbm=entry.get("noise"),
            active_ms=active,
            busy_ms=busy,
            receive_ms=entry.get("receive"),
            transmit_ms=entry.get("transmit"),
            busy_pct=round(min(busy * 100 / active, 100.0), 1) if busy is not None else None,
        ))
    return surveys


def _survey_entries(interface: str) -> list[dict]:
    """Rohwerte von `iw dev <if> survey dump` je Frequenz: {freq, in_use,
    noise, active, busy, receive, transmit} (Zeiten in ms, fehlende Werte
    fehlen im dict). Leere Liste, wenn der Treiber nichts liefert."""
    result = _run(["iw", "dev", interface, "survey", "dump"], check=False)
    if result.returncode != 0:
        log.debug("survey dump auf %s nicht verfuegbar: %s", interface, result.stderr.strip())
        return []
    entries: list[dict] = []
    current: dict | None = None
    for line in result.stdout.splitlines():
        m = _SURVEY_FREQ_RE.match(line)
        if m:
            current = {"freq": int(m.group("freq")), "in_use": "in use" in m.group("rest")}
            entries.append(current)
            continue
        if current is None:
            continue
        m = _SURVEY_NOISE_RE.match(line)
        if m:
            current["noise"] = int(m.group("v"))
            continue
        m = _SURVEY_TIME_RE.match(line)
        if m:
            current[m.group("kind")] = int(m.group("v"))
    return entries


def _survey_in_use(interface: str) -> dict | None:
    """Zählerstand des gerade genutzten Kanals (Eintrag "[in use]"), oder
    None, wenn der Treiber ihn nicht meldet. Nie eine Exception."""
    try:
        for entry in _survey_entries(interface):
            if entry.get("in_use"):
                return entry
    except Exception:  # noqa: BLE001 - reine Diagnose
        log.debug("survey dump (in use) nicht auslesbar", exc_info=True)
    return None


def _survey_delta(before: dict | None, after: dict | None) -> dict | None:
    """
    Kanalbelegung zwischen zwei Zählerständen des verbundenen Kanals:
    {seconds, busy_pct, tx_pct, rx_pct, other_pct}. busy = Kanal belegt
    (eigene + fremde Frames + Störungen über der CCA-Schwelle), tx = eigene
    Sendezeit, rx = Empfangszeit; other_pct = busy - tx - rx, also Belegung,
    die weder eigenes Senden noch eigener Empfang ist (Nachbarn, Störungen).
    None, wenn der Treiber nicht zählt (Zähler 0), der Kanal zwischendurch
    gewechselt hat oder die Zähler zurückgesetzt wurden.
    """
    if not before or not after or before.get("freq") != after.get("freq"):
        return None
    active = (after.get("active") or 0) - (before.get("active") or 0)
    if active <= 0:
        return None

    def pct(kind: str) -> float | None:
        if kind not in before or kind not in after:
            return None
        d = after[kind] - before[kind]
        return round(min(max(d * 100 / active, 0.0), 100.0), 1) if d >= 0 else None

    busy, tx, rx = pct("busy"), pct("transmit"), pct("receive")
    other = None
    if busy is not None and tx is not None and rx is not None:
        other = round(max(busy - tx - rx, 0.0), 1)
    return {
        "seconds": round(active / 1000, 1),
        "busy_pct": busy, "tx_pct": tx, "rx_pct": rx, "other_pct": other,
    }


# --------------------------------------------------------------------------
# Connection-Tests
# --------------------------------------------------------------------------

@dataclass
class ConnectionTestResult:
    ssid: str
    security: str
    connected: bool
    # Start von wpa_supplicant bis zur 802.11-Assoziation, inkl. Scan.
    assoc_seconds: float | None = None
    # Davon der Scan: Start bis wpa_supplicant einen AP gefunden hat und die
    # Anmeldung beginnt. Reine Assoziation = assoc_seconds - scan_seconds.
    # Bei fehlgeschlagener Assoziation gesetzt, wenn der Scan noch fertig wurde.
    scan_seconds: float | None = None
    # Nur bei fehlgeschlagenem Test mit capture_on_failure: ID des Mitschnitts
    # (pcap + iw-Ereignisse), den der Sender separat hochlaedt (FailureCapture).
    capture_id: str | None = None
    # Strukturierte Fassung von "error" fuer das Dashboard (siehe _err()).
    error_codes: list[dict] | None = None
    # Nur bei 802.1X: Dauer von der Assoziation bis zum abgeschlossenen
    # EAP-/4-Way-Handshake, plus die verwendete EAP-Methode.
    auth_seconds: float | None = None
    eap_method: str | None = None
    dhcp_seconds: float | None = None
    ip_address: str | None = None
    # MAC-Adresse des Interfaces waehrend des Tests; mac_random=True, wenn
    # sie fuer diesen Test zufaellig erzeugt wurde (random_mac je Ziel).
    mac_address: str | None = None
    mac_random: bool = False
    # Tatsaechlich gepingtes Ziel (SSID-Ziel, sonst Default-Gateway, sonst
    # globaler Fallback) und woher es stammt: "ssid" | "gateway" | "fallback";
    # "portal_gateway" = Gateway, weil ein Captive Portal den Rest sperrt.
    ping_target: str | None = None
    ping_target_source: str | None = None
    ping_sent: int = 0
    ping_received: int = 0
    ping_rtt_avg_ms: float | None = None
    iperf3_mbps: float | None = None  # Upload (Name aus Kompatibilitaet behalten)
    iperf3_download_mbps: float | None = None
    # TCP-Retransmits der jeweils sendenden Seite (Upload: die Probe,
    # Download: der iperf3-Server).
    iperf3_retransmits: int | None = None
    iperf3_download_retransmits: int | None = None
    # Zeitraum, in dem iperf3 lief (UTC, ISO-8601), und eine ggf. gesetzte
    # Ratenbegrenzung - fuer die Markierung im Verlauf des Dashboards.
    iperf3_started_at: str | None = None
    iperf3_ended_at: str | None = None
    iperf3_bitrate_mbps: float | None = None
    # True, wenn iperf3 in diesem Test wegen des Mindestabstands
    # (connection_tests.iperf3_min_interval_minutes) bewusst ausgelassen wurde.
    iperf3_deferred: bool = False
    # Kanalbelegung des verbundenen Kanals aus Sicht der Probe (survey dump,
    # siehe _survey_delta()): {"frequency_mhz", "baseline": {...} zwischen
    # DHCP und Ende des Pings (fast ohne eigenen Verkehr), "iperf3": {...}
    # waehrend der Durchsatzmessung}. None, wenn der Treiber nicht zaehlt.
    channel_load: dict | None = None
    # Captive-Portal-Pruefung, siehe check_captive_portal(); None, wenn nicht
    # konfiguriert. Ein erkanntes Portal ist kein Testfehler (connected
    # bleibt True), wird aber im Dashboard hervorgehoben.
    captive_portal: dict | None = None
    # Gesetzt (z.B. "captive_portal"), wenn iperf3 bewusst nicht lief (bei
    # "captive_portal" ab 1.0.1.6 dafuer Ping zum Gateway, siehe oben).
    skipped: str | None = None
    # Link-Details (Signal, Bitraten inkl. MCS, Retries) am Ende des Tests,
    # siehe _wifi_link_info(); None, falls nicht auslesbar.
    link: dict | None = None
    # Differenz der Interface-Zaehler (Pakete/Bytes/Fehler/Drops) ueber die
    # Dauer von Ping + iperf3, siehe _read_counters().
    counters: dict | None = None
    error: str | None = None


# --------------------------------------------------------------------------
# Diagnose-Werte: IP, Interface-Zaehler, WLAN-Link (MCS/Bitrate)
# --------------------------------------------------------------------------

def interface_ipv4(interface: str) -> str | None:
    result = _run(["ip", "-4", "-o", "addr", "show", "dev", interface], check=False)
    m = re.search(r"inet (\d+\.\d+\.\d+\.\d+)", result.stdout)
    return m.group(1) if m else None


_COUNTER_KEYS = (
    "rx_bytes", "tx_bytes", "rx_packets", "tx_packets",
    "rx_errors", "tx_errors", "rx_dropped", "tx_dropped",
)


def _read_counters(interface: str) -> dict[str, int] | None:
    base = Path("/sys/class/net") / interface / "statistics"
    try:
        return {k: int((base / k).read_text()) for k in _COUNTER_KEYS}
    except (OSError, ValueError):
        return None


def _counter_delta(
    before: dict[str, int] | None, after: dict[str, int] | None
) -> dict[str, int] | None:
    if before is None or after is None:
        return None
    delta = {k: after[k] - before[k] for k in _COUNTER_KEYS}
    # Negativ = Zaehler wurde zwischendurch zurueckgesetzt (z.B. Treiber-
    # Reload) - dann ist die Differenz wertlos statt irrefuehrend.
    return delta if all(v >= 0 for v in delta.values()) else None


def _parse_bitrate(line_rest: str, mbps: str) -> dict:
    """Zerlegt z.B. '866.7 MBit/s VHT-MCS 9 80MHz short GI VHT-NSS 2' bzw.
    '... HE-MCS 11 HE-NSS 2 HE-GI 0' oder '... MCS 15 40MHz short GI'."""
    rate: dict = {"mbps": float(mbps)}
    mcs = re.search(r"(VHT-|HE-|EHT-)?MCS (\d+)", line_rest)
    if mcs:
        rate["mcs"] = int(mcs.group(2))
        rate["standard"] = (mcs.group(1) or "HT-").rstrip("-")
    else:
        rate["standard"] = "legacy"
    nss = re.search(r"NSS (\d+)", line_rest)
    if nss:
        rate["nss"] = int(nss.group(1))
    width = re.search(r"(\d+)MHz", line_rest)
    if width:
        rate["width_mhz"] = int(width.group(1))
    return rate


def _wifi_link_info(interface: str) -> dict | None:
    """
    Aktuelle Link-Qualitaet des verbundenen WLAN: Signal, Rx/Tx-Bitrate mit
    MCS/NSS/Kanalbreite und Tx-Retries. Nur waehrend der Verbindung
    auslesbar; am sinnvollsten nach Datenverkehr (iperf3), denn im
    Leerlauf passt die Rate-Control die MCS nicht an.
    """
    link = _run(["iw", "dev", interface, "link"], check=False).stdout
    if "Connected to" not in link:
        return None

    info: dict = {}
    m = re.search(r"Connected to ([0-9a-f:]{17})", link)
    if m:
        info["bssid"] = m.group(1)
    m = re.search(r"freq:\s*([\d.]+)", link)
    if m:
        info["frequency_mhz"] = int(float(m.group(1)))
    m = re.search(r"signal:\s*(-?\d+)", link)
    if m:
        info["signal_dbm"] = int(m.group(1))
    for direction in ("rx", "tx"):
        m = re.search(rf"{direction} bitrate:\s*([\d.]+) MBit/s(.*)", link)
        if m:
            info[f"{direction}_rate"] = _parse_bitrate(m.group(2), m.group(1))

    station = _run(["iw", "dev", interface, "station", "dump"], check=False).stdout
    m = re.search(r"tx retries:\s*(\d+)", station)
    if m:
        info["tx_retries"] = int(m.group(1))
    m = re.search(r"tx failed:\s*(\d+)", station)
    if m:
        info["tx_failed"] = int(m.group(1))
    m = re.search(r"signal avg:\s*(-?\d+)", station)
    if m:
        info["signal_avg_dbm"] = int(m.group(1))
    return info


# --------------------------------------------------------------------------
# 802.1X / WPA2-Enterprise
# --------------------------------------------------------------------------

EAP_METHODS = {"peap": "PEAP", "ttls": "TTLS", "tls": "TLS"}
# Innere Authentifizierung (Phase 2) je Methode: erlaubte Werte, erster = Standard.
_PHASE2 = {
    "peap": ("MSCHAPV2",),
    "ttls": ("PAP", "MSCHAPV2", "MSCHAP", "CHAP"),
}


def _validate_eap(eap: dict | None) -> tuple[str, dict] | None:
    """Prueft die 802.1X-Angaben eines Ziels, bevor irgendetwas gestartet
    wird. Rueckgabe: (Fehlertext, Fehlercode) oder None, wenn alles Noetige
    da ist."""
    if not eap:
        return "Am Ziel fehlt der Block 'eap' (Methode, Benutzername ...)", _err("eap_block_missing")
    method = str(eap.get("method") or "peap").lower()
    if method not in EAP_METHODS:
        return (f"Unbekannte EAP-Methode '{method}' (erlaubt: peap, ttls, tls)",
                _err("eap_method_unknown", method=method))
    if not eap.get("identity"):
        return "Benutzername/Identity fehlt", _err("eap_identity_missing")
    if method in ("peap", "ttls") and not eap.get("password"):
        return "Passwort fehlt", _err("eap_password_missing")
    if method == "tls" and not (eap.get("client_cert") and eap.get("private_key")):
        return "EAP-TLS braucht Client-Zertifikat und privaten Schluessel", _err("eap_tls_cert_missing")
    if not shutil.which("wpa_cli"):
        return "wpa_cli nicht installiert (Paket wpasupplicant)", _err("wpa_cli_missing")
    return None


def _hexstr(value: str) -> str:
    """wpa_supplicant nimmt Strings auch hex-kodiert. Damit sind beliebige
    Zeichen in Benutzername/Passwort (Anfuehrungszeichen, Umlaute, Zeilen-
    umbrueche) unkritisch - sie koennen die Config-Datei nicht aufbrechen."""
    return str(value).encode("utf-8").hex()


# --------------------------------------------------------------------------
# Fehlercodes
# --------------------------------------------------------------------------
# Neben dem deutschen Fehlertext (fuer das Probe-Log) schickt jeder
# fehlgeschlagene Test "error_codes": eine Liste von Bausteinen
# {"code": ..., Parameter ...}, z.B. [{"code": "assoc_failed"},
# {"code": "sae_password_wrong", "status": 1}]. Das Dashboard formuliert
# daraus den Text in der Sprache der Oberflaeche bzw. des Alarms
# (src/ProbeError.php) - die Probe liefert nur die Bedeutung.
# Neue Codes dort mit Text (de) und Uebersetzung (lang/en.php) ergaenzen.

def _err(code: str, **params: object) -> dict:
    """Ein Baustein fuer error_codes; Parameter mit None werden weggelassen."""
    return {"code": code, **{k: v for k, v in params.items() if v is not None}}


def _write_secret_file(path: Path, text: str) -> None:
    """PEM-Inhalt nur fuer root lesbar ablegen (CRLF aus dem Web-Formular
    normalisieren)."""
    content = text.replace("\r\n", "\n").replace("\r", "\n").strip() + "\n"
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(content)


def _eap_network_lines(ssid_escaped: str, eap: dict, workdir: Path) -> str:
    method = str(eap.get("method") or "peap").lower()
    lines = [
        f'  ssid="{ssid_escaped}"',
        "  key_mgmt=WPA-EAP",
        f"  eap={EAP_METHODS[method]}",
        f"  identity={_hexstr(eap['identity'])}",
    ]
    if eap.get("anonymous_identity"):
        lines.append(f"  anonymous_identity={_hexstr(eap['anonymous_identity'])}")

    if method in ("peap", "ttls"):
        allowed = _PHASE2[method]
        phase2 = str(eap.get("phase2") or allowed[0]).upper()
        if phase2 not in allowed:
            phase2 = allowed[0]
        lines.append(f"  password={_hexstr(eap['password'])}")
        lines.append(f'  phase2="auth={phase2}"')
    else:  # tls
        client_path = workdir / "client.pem"
        key_path = workdir / "client.key"
        _write_secret_file(client_path, eap["client_cert"])
        _write_secret_file(key_path, eap["private_key"])
        lines.append(f'  client_cert="{client_path}"')
        lines.append(f'  private_key="{key_path}"')
        if eap.get("private_key_password"):
            lines.append(f"  private_key_passwd={_hexstr(eap['private_key_password'])}")

    # Server-Zertifikat nur pruefen, wenn ein CA-Zertifikat hinterlegt ist
    # UND die Pruefung nicht ausdruecklich abgeschaltet wurde. Ohne Pruefung
    # laeuft der Test trotzdem (Testumgebungen), schuetzt aber nicht vor
    # einem gefaelschten RADIUS-Server.
    if eap.get("ca_cert") and eap.get("verify_server", True):
        ca_path = workdir / "ca.pem"
        _write_secret_file(ca_path, eap["ca_cert"])
        lines.append(f'  ca_cert="{ca_path}"')
        if eap.get("server_name"):
            lines.append(f"  domain_suffix_match={_hexstr(eap['server_name'])}")
    return "\n".join(lines)


def _wait_for_wpa_completed(interface: str, timeout: int) -> bool:
    """Wartet, bis wpa_supplicant den kompletten Schluesselaustausch (bei
    802.1X: EAP-Authentifizierung + 4-Way-Handshake, sonst nur den 4-Way-
    Handshake) abgeschlossen hat. Wichtig auch fuer reines WPA2/3-PSK: "iw
    link" zeigt "Connected to" schon nach der reinen 802.11-Assoziation,
    bevor der 4-Way-Handshake ueberhaupt begonnen hat - bei falschem PSK
    bleibt wpa_supplicant dann in einem Zwischenzustand haengen (Pakete
    werden verworfen, DHCP schlaegt fehl), ohne dass "iw link" das anzeigt."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        res = _run(["wpa_cli", "-i", interface, "status"], check=False, timeout=5)
        if "wpa_state=COMPLETED" in res.stdout:
            return True
        # Die Assoziation stand schon (Aufrufer). Faellt wpa_supplicant wieder
        # auf DISCONNECTED/SCANNING zurueck, hat der AP abgelehnt (802.1X-
        # Ablehnung, Deauth Reason 23; falscher PSK, Reason 15) - sofort
        # abbrechen statt bis zum Timeout zu warten. Sonst startet
        # wpa_supplicant einen zweiten Anmeldeversuch (doppelte Last fuer den
        # RADIUS-Server, Kontosperren) und der Test blockiert ~20 s laenger.
        if any(f"wpa_state={s}" in res.stdout for s in ("DISCONNECTED", "SCANNING", "INACTIVE")):
            return False
        time.sleep(0.1)  # Takt = Messgenauigkeit von auth_seconds
    return False


def _stop_wpa(wpa_proc: subprocess.Popen | None) -> None:
    """wpa_supplicant beenden, damit sein (zeilenweise gepuffertes) Log
    vollstaendig auf der Platte liegt, bevor wir es auswerten."""
    if wpa_proc is None:
        return
    wpa_proc.terminate()
    try:
        wpa_proc.wait(timeout=5)
    except subprocess.TimeoutExpired:
        wpa_proc.kill()


# (Regex, deutscher Text, Fehlercode, Namen der Regex-Gruppen als Parameter).
# Gruppen, die keinen Parameter bilden, als "(?:...)" schreiben.
_WPA_ERROR_PATTERNS: tuple[tuple[str, str, str, tuple[str, ...]], ...] = (
    (r"Certificate verification failed, error \d+ \(([^)]*)\)",
     "Server-Zertifikat nicht akzeptiert: {0}", "server_cert_rejected", ("reason",)),
    (r"E=691|MSCHAPV2: Authentication failed|MSCHAPV2.*Failure",
     "vom RADIUS-Server abgelehnt (Benutzername/Passwort falsch oder Konto gesperrt)",
     "radius_rejected", ()),
    (r"CTRL-EVENT-EAP-TIMEOUT-FAILURE|EAP: Timeout",
     "keine Antwort vom RADIUS-Server (EAP-Timeout)", "radius_timeout", ()),
    (r"MD4|Failed to initialize EAP method",
     "EAP-Methode konnte nicht initialisiert werden (fehlt MD4/Legacy-Provider in OpenSSL?)",
     "eap_method_init_failed", ()),
    (r"SSL_connect|TLS: .*(?:handshake failed|alert)",
     "TLS-Handshake mit dem RADIUS-Server fehlgeschlagen", "tls_handshake_failed", ()),
    # EAP-Failure, nachdem das Server-Zertifikat schon da war: der TLS-Tunnel
    # stand, abgelehnt wurde die innere Anmeldung (Phase 2, bei PEAP/TTLS
    # z.B. MSCHAPv2). Ohne -d protokolliert wpa_supplicant den inneren Fehler
    # selbst nicht, die Reihenfolge PEER-CERT ... FAILURE schon. Bei PEAP steht
    # zusaetzlich "EAP-TLV: TLV Result - Failure" im Log (Ergebnis der inneren
    # Anmeldung) - das greift auch bei fortgesetzter TLS-Sitzung ohne
    # Zertifikatszeilen. Mit absichtlich falschem PEAP-Passwort nachgewiesen
    # (pcap: Tunnel aufgebaut, EAP-Failure nach der MSCHAPv2-Antwort; Log
    # mit PEER-CERT depth=3..0 und TLV-Failure).
    (r"(?s)CTRL-EVENT-EAP-PEER-CERT.*CTRL-EVENT-EAP-FAILURE|EAP-TLV: TLV Result - Failure",
     "vom RADIUS-Server in Phase 2 abgelehnt (innere Anmeldung: Benutzername/Passwort, Konto oder Richtlinie)",
     "eap_rejected_phase2", ()),
    (r"CTRL-EVENT-EAP-FAILURE",
     "EAP-Authentifizierung fehlgeschlagen (EAP-Failure)", "eap_failure", ()),
    # WPA3 (SAE, auth_type=3): Commit angenommen, Confirm abgelehnt (Schritt 2,
    # Status 1) - der AP konnte die Bestaetigung nicht pruefen, die Schluessel
    # passen nicht zusammen. Auf einem NanoPi mit absichtlich falschem
    # WPA3-Passwort so nachgewiesen.
    (r"CTRL-EVENT-AUTH-REJECT \S+ auth_type=3 auth_transaction=2 status_code=(1)\b",
     "WPA3-Passwort vermutlich falsch: der AP lehnt die SAE-Bestaetigung ab (Status 1)",
     "sae_password_wrong", ("status",)),
    # Andere SAE-Ablehnungen (meist schon beim Commit): Einstellungen passen
    # nicht, z.B. H2E/Hunting-and-Pecking, Gruppe oder PMF.
    (r"CTRL-EVENT-AUTH-REJECT \S+ auth_type=3 auth_transaction=(\d+) status_code=(\d+)",
     "WPA3 (SAE) vom AP abgelehnt (Schritt {0}, Status {1}) - SAE-Einstellungen pruefen (H2E, Gruppe, PMF)",
     "sae_rejected", ("step", "status")),
    (r"CTRL-EVENT-AUTH-REJECT \S+ auth_type=(\d+) auth_transaction=\d+ status_code=(\d+)",
     "Authentifizierung vom AP abgelehnt (auth_type {0}, Status {1})",
     "auth_rejected", ("auth_type", "status")),
    # Spezifische Ursachen vor der allgemeinen Sperre unten - die steht bei
    # einem falschen PSK ebenfalls im Log und hat frueher immer gewonnen.
    (r"WPA: 4-Way Handshake failed - pre-shared key may be incorrect",
     "PSK vermutlich falsch (4-Way-Handshake fehlgeschlagen)", "psk_wrong", ()),
    (r"CTRL-EVENT-SSID-TEMP-DISABLED.*reason=WRONG_KEY",
     "PSK vermutlich falsch (wpa_supplicant: WRONG_KEY)", "psk_wrong", ()),
    (r"CTRL-EVENT-SSID-TEMP-DISABLED.*reason=(\w+)",
     "Netz voruebergehend gesperrt (reason={0})", "network_disabled", ("reason",)),
    # Zuletzt: der AP hat getrennt, ohne dass oben etwas Genaueres im Log
    # steht (z.B. 802.1X-Ablehnung nur als Deauth sichtbar). Selbst
    # ausgeloeste Trennungen (locally_generated=1) zaehlen nicht.
    (r"CTRL-EVENT-DISCONNECTED bssid=\S+ reason=(\d+)\b(?! locally_generated)",
     "vom AP getrennt (Deauth Reason {0})", "deauth_by_ap", ("reason",)),
)


# Zeilen, an denen man sieht, dass wpa_supplicant ein passendes Netz gefunden
# und einen Verbindungsversuch gestartet hat (ohne -d gibt es davor nur Start-
# und Regdom-Meldungen).
_WPA_ATTEMPT_RE = re.compile(
    r"Trying to (associate|authenticate)|Associated with|CTRL-EVENT-(CONNECTED|ASSOC-REJECT|AUTH-REJECT"
    r"|DISCONNECTED|SSID-TEMP-DISABLED|EAP-)|4-Way Handshake|WRONG_KEY"
)


def _band_label(freq_mhz: int | None) -> str:
    if freq_mhz is None:
        return "?"
    return "2,4 GHz" if freq_mhz < 3000 else ("5 GHz" if freq_mhz < 5925 else "6 GHz")


def _scan_cache_for(interface: str, ssid: str) -> list[ScanResult] | None:
    """BSS dieser SSID aus dem Scan-Cache des Kernels (`iw scan dump`, keine
    neue Messung) - befuellt von den Scans, die wpa_supplicant waehrend des
    Verbindungsversuchs gemacht hat. None = nicht abfragbar."""
    res = _run(["iw", "dev", interface, "scan", "dump"], check=False, timeout=10)
    if res.returncode != 0:
        return None
    return [n for n in _parse_scan_output(res.stdout) if n.ssid == ssid]


def _association_failure_detail(
    log_path: str, ssid: str, security: str, seen: list[ScanResult] | None
) -> tuple[str, list[dict]]:
    """Grund fuer eine gescheiterte Assoziation als (Text, Fehlercodes). Hat
    wpa_supplicant gar keinen Versuch gestartet, sagt das rohe Log nichts -
    dann aus dem Scan-Cache: SSID nicht gesehen (Reichweite/Band/Name) oder
    gesehen, aber der Sicherheitstyp passt nicht zur Konfiguration."""
    try:
        text = Path(log_path).read_text(errors="replace")
    except OSError:
        text = ""
    if _WPA_ATTEMPT_RE.search(text) or seen is None:
        return _wpa_error_summary(log_path)
    log.debug("wpa_supplicant-Log ohne Verbindungsversuch:\n%s", text)
    if not seen:
        return (
            f'SSID "{ssid}" nicht gefunden: im Scan während des Tests nicht gesehen '
            f"(außer Reichweite, anderes Band oder Name weicht ab).",
            [_err("ssid_not_found", ssid=ssid)],
        )
    best = max(seen, key=lambda n: n.signal_dbm if n.signal_dbm is not None else -999.0)
    bands = ", ".join(sorted({_band_label(n.frequency_mhz) for n in seen}))
    net_security = "; ".join(sorted({
        (n.security or "?") + (", PMF erforderlich" if n.pmf == "required" else "")
        for n in seen
    }))
    signal = f", bestes Signal {best.signal_dbm:.0f} dBm" if best.signal_dbm is not None else ""
    return (
        f'SSID "{ssid}" gefunden ({bands}{signal}), aber kein Verbindungsversuch - '
        f"Sicherheitstyp passt vermutlich nicht: konfiguriert {security}, Netz meldet {net_security}.",
        [_err(
            "security_mismatch", ssid=ssid,
            # Frequenzen statt Band-Texten - das Dashboard formuliert die Baender.
            frequencies_mhz=sorted({n.frequency_mhz for n in seen if n.frequency_mhz}),
            signal_dbm=round(best.signal_dbm) if best.signal_dbm is not None else None,
            configured=security,
            network=sorted({n.security or "?" for n in seen}),
            pmf_required=any(n.pmf == "required" for n in seen),
        )],
    )


def _wpa_error_summary(log_path: str, waited: float | None = None) -> tuple[str, list[dict]]:
    """Kurze, lesbare Fehlerursache aus dem wpa_supplicant-Log als (Text,
    Fehlercodes). Faellt auf die relevanten Zeilen am Log-Ende zurueck, wenn
    nichts Bekanntes gefunden wird. waited = Sekunden, die auf den Abschluss
    gewartet wurde (fuer die Meldung, wenn der Austausch haengen blieb)."""
    try:
        text = Path(log_path).read_text(errors="replace")
    except OSError:
        return "", []
    log.debug("wpa_supplicant-Log:\n%s", text)
    for pattern, message, code, names in _WPA_ERROR_PATTERNS:
        m = re.search(pattern, text)
        if m:
            params = {
                name: (int(value) if value is not None and value.isdigit() else value)
                for name, value in zip(names, m.groups())
            }
            return message.format(*m.groups()), [_err(code, **params)]
    stalled = _eap_stall_summary(text)
    if stalled:
        message, code = stalled
        waited_s = round(waited) if waited is not None else None
        if waited_s is not None:
            message += f" - nach {waited_s} s ohne Ergebnis abgebrochen"
        code["waited_s"] = waited_s
        return message, [_err(**code)]
    tail = _tail(log_path)
    # Unbekanntes Fehlerbild: rohe Log-Zeilen, die das Dashboard unuebersetzt zeigt.
    return tail, ([_err("wpa_log", text=tail[len("wpa_supplicant-Log: "):])] if tail else [])


def _eap_stall_summary(text: str) -> tuple[str, dict] | None:
    """EAP hat begonnen, aber weder Erfolg noch Fehlschlag gemeldet - der
    Austausch ist irgendwo haengen geblieben (meist antwortet der RADIUS-
    Server nicht mehr). Sagt, wie weit er gekommen ist: (Text, Fehlercode)."""
    if "CTRL-EVENT-EAP-SUCCESS" in text:
        return "EAP erfolgreich, aber 4-Way-Handshake danach nicht abgeschlossen", {"code": "eap_no_4way"}
    cert = re.search(r"CTRL-EVENT-EAP-PEER-CERT depth=0 subject='([^']*)'", text)
    if cert or "CTRL-EVENT-EAP-PEER-CERT" in text:
        subject = cert.group(1) if cert and cert.group(1) else None
        return (
            f"Server-Zertifikat erhalten{f' ({subject})' if subject else ''}, danach keine Antwort mehr vom "
            "RADIUS-Server (Phase 2 / innere Authentifizierung nicht abgeschlossen)",
            {"code": "eap_stalled_after_cert", "subject": subject},
        )
    if "CTRL-EVENT-EAP-METHOD" in text:
        return (
            "EAP-Methode ausgehandelt, aber keine Antwort vom RADIUS-Server "
            "(TLS-Tunnel nicht aufgebaut)",
            {"code": "eap_stalled_method"},
        )
    if "CTRL-EVENT-EAP-STARTED" in text:
        return (
            "EAP gestartet, aber keine Methode ausgehandelt (RADIUS-Server antwortet nicht?)",
            {"code": "eap_stalled_start"},
        )
    return None


def _write_wpa_supplicant_conf(
    ssid: str,
    psk: str,
    security: str,
    eap: dict | None = None,
    eap_workdir: Path | None = None,
) -> Path:
    ssid_escaped = ssid.replace('"', '\\"')
    # Vor dem Anlegen der Conf-Datei berechnen: schlaegt das fehl, bleibt
    # keine halbfertige Datei zurueck.
    eap_block = (
        _eap_network_lines(ssid_escaped, eap or {}, eap_workdir or Path("."))
        if security == "wpa2-eap"
        else None
    )
    tmp = tempfile.NamedTemporaryFile(
        mode="w", suffix=".conf", prefix="wlanmon-probe-", delete=False
    )
    tmp.write("ctrl_interface=/var/run/wpa_supplicant\nupdate_config=0\n\n")

    # scan_ssid=1: aktiv per Probe Request nach genau dieser SSID suchen -
    # sonst findet wpa_supplicant ein Netz mit verborgener SSID nie (es
    # erscheint im Scan ohne Namen). Bei sichtbaren Netzen ohne Nachteil.
    if eap_block is not None:
        tmp.write("network={\n  scan_ssid=1\n" + eap_block + "\n}\n")
    elif security == "open":
        tmp.write(f'network={{\n  ssid="{ssid_escaped}"\n  scan_ssid=1\n  key_mgmt=NONE\n}}\n')
    elif security == "wpa3-psk":
        # Reines WPA3-Personal (SAE). PMF (Protected Management Frames)
        # ist bei WPA3 Pflicht, daher ieee80211w=2.
        psk_escaped = psk.replace('"', '\\"')
        tmp.write(
            f'network={{\n  ssid="{ssid_escaped}"\n  scan_ssid=1\n  psk="{psk_escaped}"\n'
            f'  key_mgmt=SAE\n  ieee80211w=2\n}}\n'
        )
    elif security == "wpa2-wpa3-psk":
        # Transition-/Mixed-Mode (WPA2/WPA3-Personal gleichzeitig
        # angeboten) - inzwischen der Standard bei vielen Routern
        # (Fritz!Box, Speedport...). wpa_supplicant handelt selbst aus,
        # was das jeweilige AP unterstützt. PMF optional (ieee80211w=1),
        # da reine WPA2-Clients im selben Netz sonst nicht verbinden
        # könnten.
        psk_escaped = psk.replace('"', '\\"')
        tmp.write(
            f'network={{\n  ssid="{ssid_escaped}"\n  scan_ssid=1\n  psk="{psk_escaped}"\n'
            f'  key_mgmt=WPA-PSK SAE\n  ieee80211w=1\n}}\n'
        )
    else:  # wpa2-psk (default)
        psk_escaped = psk.replace('"', '\\"')
        tmp.write(
            f'network={{\n  ssid="{ssid_escaped}"\n  scan_ssid=1\n  psk="{psk_escaped}"\n'
            f'  key_mgmt=WPA-PSK\n}}\n'
        )
    tmp.close()
    return Path(tmp.name)


def run_connection_test(
    interface: str,
    ssid: str,
    psk: str,
    security: str,
    connect_timeout: int,
    ping_target: str,
    ping_count: int,
    ssid_ping_target: str = "",
    iperf3_server: str = "",
    iperf3_duration: int = 5,
    iperf3_download: bool = True,
    iperf3_port: int = 5201,
    iperf3_bitrate_mbps: float = 0,
    eap: dict | None = None,
    captive_portal_url: str = "",
    portal_login: dict | None = None,
    random_mac: bool = False,
    capture_on_failure: bool = False,
) -> ConnectionTestResult:
    """
    Führt einen vollständigen Verbindungstest gegen ein SSID durch:
    Assoziieren -> (802.1X) -> DHCP -> (Captive-Portal-Check) -> Ping
    (optional iperf3) -> Aufräumen.
    Das Interface wird am Ende immer wieder in einen sauberen Zustand
    zurückgesetzt, unabhängig vom Testergebnis.
    """
    result = ConnectionTestResult(ssid=ssid, security=security, connected=False)
    is_eap = security == "wpa2-eap"

    eap_workdir: Path | None = None
    if is_eap:
        problem = _validate_eap(eap)
        if problem:
            text, code = problem
            result.error = f"802.1X: {text}"
            result.error_codes = [_err("eap_config_invalid"), code]
            return result
        result.eap_method = str((eap or {}).get("method") or "peap").lower()
        # Zertifikate/Schluessel liegen nur fuer die Dauer des Tests in einem
        # 0700-Verzeichnis und werden im finally wieder geloescht.
        eap_workdir = Path(tempfile.mkdtemp(prefix="wlanmon-probe-eap-"))

    try:
        conf_path = _write_wpa_supplicant_conf(ssid, psk, security, eap, eap_workdir)
    except Exception as exc:  # noqa: BLE001
        if eap_workdir is not None:
            shutil.rmtree(eap_workdir, ignore_errors=True)
        result.error = f"WLAN-Konfiguration nicht erzeugbar: {exc}"
        result.error_codes = [_err("config_failed", detail=str(exc))]
        return result
    wpa_proc: subprocess.Popen | None = None
    wpa_log_path = tempfile.NamedTemporaryFile(
        delete=False, prefix="wlanmon-probe-wpa-", suffix=".log"
    ).name

    restore_mac: str | None = None
    portal_module = None       # Login-Modul, falls ein Portal-Login lief
    portal_ctx: PortalContext | None = None
    portal_session: dict | None = None
    capture: FailureCapture | None = None
    try:
        if random_mac:
            # Vor dem Hochfahren: eine Schnittstelle laesst sich nur im
            # ausgeschalteten Zustand umadressieren. Die Original-MAC wird
            # in _cleanup_connection() wiederhergestellt.
            restore_mac = _permanent_mac(interface)
            new_mac = _random_local_mac()
            if _set_mac(interface, new_mac):
                result.mac_random = True
                log.info("SSID %s: zufaellige MAC %s (Original %s)", ssid, new_mac, restore_mac)
            else:
                log.warning(
                    "SSID %s: zufaellige MAC %s liess sich nicht setzen - "
                    "Test laeuft mit der aktuellen MAC", ssid, new_mac,
                )

        _run(["ip", "link", "set", interface, "up"], check=False)
        result.mac_address = _read_mac(interface)

        # Verwaisten ctrl_interface-Socket aus einem vorherigen, unsauber
        # beendeten wpa_supplicant-Lauf entfernen (z.B. nach SIGKILL/Absturz).
        # Ohne das verweigert wpa_supplicant den Start mit "Failed to
        # initialize control interface", und jede Assoziation läuft in
        # einen Timeout, ohne dass wpa_supplicant überhaupt aktiv wird.
        stale_socket = Path("/var/run/wpa_supplicant") / interface
        stale_socket.unlink(missing_ok=True)

        if capture_on_failure:
            # Vor wpa_supplicant starten, damit der ganze Verbindungsaufbau
            # drin ist; behalten wird er nur bei einem Fehlschlag (finally).
            capture = FailureCapture(interface)
            capture.start()

        t0 = time.monotonic()
        with open(wpa_log_path, "w") as wpa_log:
            wpa_proc = subprocess.Popen(
                [
                    "wpa_supplicant",
                    "-i", interface,
                    "-c", str(conf_path),
                    "-D", "nl80211",
                    # Beim Mitschnitt: EAPOL nicht ueber den nl80211-Control-
                    # Port, sondern klassisch ueber das Interface schicken -
                    # sonst sieht tcpdump auf wlan0 keinen einzigen EAPOL-
                    # Rahmen (auf einem NanoPi/mt7921u nachgewiesen: pcap bei
                    # 802.1X-Fehlschlag leer). Fuer den AP aendert sich nichts.
                    *(["-p", "control_port=0"] if capture is not None else []),
                ],
                stdout=wpa_log,
                stderr=subprocess.STDOUT,
            )

        associated, result.scan_seconds, gave_up = _wait_for_association(interface, connect_timeout, t0)
        if not associated:
            # Scan-Cache lesen, solange die Scans von wpa_supplicant frisch
            # sind; dann wpa_supplicant beenden, damit sein Log vollstaendig
            # in der Datei steht (stdout in eine Datei ist gepuffert).
            seen = _scan_cache_for(interface, ssid)
            _stop_wpa(wpa_proc)
            detail, codes = _association_failure_detail(wpa_log_path, ssid, security, seen)
            # "(Timeout)" nur, wenn wirklich bis zum Ende gewartet wurde - bei
            # einer Sperre durch wpa_supplicant hat der AP aktiv abgelehnt.
            result.error = f"Assoziation fehlgeschlagen{'' if gave_up else ' (Timeout)'}. {detail}"
            result.error_codes = [_err("assoc_failed", timeout=not gave_up)] + codes
            return result
        result.assoc_seconds = round(time.monotonic() - t0, 2)

        # Immer (nicht nur bei 802.1X) auf wpa_state=COMPLETED warten: die
        # reine 802.11-Assoziation oben ("iw link" zeigt "Connected to")
        # sagt bei WPA2/3-PSK nichts ueber einen falschen PSK aus - der
        # wird erst im 4-Way-Handshake danach geprueft. Ohne diese Pruefung
        # wuerde ein falscher PSK faelschlich als "verbunden" gewertet und
        # erst beim anschliessenden DHCP-Fehlschlag auffallen (irrefuehrende
        # Fehlermeldung "kein DHCP-Lease" statt "falscher PSK").
        t_auth = time.monotonic()
        remaining = max(5, connect_timeout - int(time.monotonic() - t0))
        if not _wait_for_wpa_completed(interface, remaining):
            waited = time.monotonic() - t_auth
            _stop_wpa(wpa_proc)
            if is_eap:
                summary, codes = _wpa_error_summary(wpa_log_path, waited)
                result.error = f"802.1X-Authentifizierung fehlgeschlagen: {summary}"
                result.error_codes = [_err("auth_8021x_failed")] + codes
            else:
                summary, codes = _wpa_error_summary(wpa_log_path, waited)
                result.error = f"WPA-Schlüsselaustausch fehlgeschlagen: {summary}"
                result.error_codes = [_err("key_exchange_failed")] + codes
            return result
        if is_eap:
            # Dauer getrennt von der reinen Assoziation ausgewiesen -
            # nur bei 802.1X interessant (EAP + 4-Way-Handshake zusammen).
            result.auth_seconds = round(time.monotonic() - t_auth, 2)

        log.info(
            "SSID %s: Verbindung steht (assoc %.1fs, davon Scan %s%s) - starte DHCP",
            ssid, result.assoc_seconds or 0.0,
            f"{result.scan_seconds:.1f}s" if result.scan_seconds is not None else "?",
            f", 802.1X {result.auth_seconds:.1f}s" if result.auth_seconds is not None else "",
        )
        _clear_promiscuous_mode(interface)
        _disable_power_save(interface)
        t1 = time.monotonic()
        counters_dhcp_before = _read_counters(interface)
        ip_addr = _run_dhcp(interface, timeout=connect_timeout)
        if not ip_addr:
            # Der Link steht (Assoziation/802.1X waren erfolgreich), im Netz
            # dahinter kommt aber nichts zurueck. Damit sich das eingrenzen
            # laesst (anderer AP? VLAN ohne DHCP?), AP, Signal und die Zahl
            # der waehrenddessen empfangenen/gesendeten Pakete mitschicken.
            detail, params = _dhcp_failure_detail(interface, counters_dhcp_before, result)
            result.error = "Kein DHCP-Lease erhalten (Timeout)" + detail
            result.error_codes = [_err("dhcp_timeout", **params)]
            return result
        result.dhcp_seconds = round(time.monotonic() - t1, 2)
        result.ip_address = ip_addr
        result.connected = True

        _log_network_debug(interface)

        # Kanalbelegung des verbundenen Kanals: Zaehlerstand jetzt, nach dem
        # Ping (Grundlast, kaum eigener Verkehr) und nach iperf3 (Last).
        survey_connected = _survey_in_use(interface)

        # Vor Ping/iperf3 und vor dem Zaehler-Snapshot, damit der HTTP-Check
        # weder die Zaehlerdifferenz noch die Ping-Messung verfaelscht.
        portal_blocked = False
        if captive_portal_url:
            cp = check_captive_portal(interface, captive_portal_url)
            result.captive_portal = cp
            if cp.get("detected") is True:
                if portal_login and portal_login.get("enabled"):
                    portal_module = select_module(
                        portal_login.get("type"), cp.get("redirect_url")
                    )
                    if portal_module is None:
                        login = {
                            "attempted": True, "ok": False,
                            "error": "kein Login-Modul fuer dieses Portal "
                                     "(Portal-Typ pruefen)",
                        }
                    else:
                        portal_ctx = PortalContext(
                            interface=interface,
                            redirect_url=cp.get("redirect_url") or "",
                        )
                        login, portal_session = portal_module.login(
                            portal_ctx, portal_login
                        )
                        login["portal_type"] = portal_module.name
                    cp["login"] = login
                    if not login.get("error"):
                        # Erfolg heisst: die Detection-URL liefert jetzt 204.
                        # Der AP schaltet die MAC oft erst nach ein paar
                        # Sekunden frei, daher bis zu 4 Pruefungen.
                        login["ok"] = False
                        for _ in range(4):
                            time.sleep(1.5)
                            recheck = check_captive_portal(interface, captive_portal_url)
                            if recheck.get("detected") is False:
                                login["ok"] = True
                                break
                        if not login["ok"]:
                            login["error"] = "nach dem Login weiterhin Portal"
                    else:
                        login["ok"] = False
                portal_blocked = not (cp.get("login") or {}).get("ok")
        if portal_blocked:
            # Solange das Portal den Verkehr sperrt, scheitern iperf3 und ein
            # Ping ins Internet sicher - sie wuerden nur Zeit kosten und ein
            # irrefuehrendes "alle verloren" liefern. Das Default-Gateway ist
            # dagegen fast immer auch hinter dem Portal erreichbar (oft ist es
            # das Portal selbst) - der Ping dorthin misst die WLAN-Strecke.
            result.skipped = "captive_portal"
            log.info("SSID %s: Captive Portal aktiv - iperf3 uebersprungen, Ping nur zum Gateway", ssid)

        counters_before = _read_counters(interface)

        if portal_blocked:
            # SSID-Ziel und globaler Fallback bewusst ignoriert (liegen meist
            # hinter dem Portal); ohne ermittelbares Gateway kein Ping.
            gateway = _default_gateway(interface)
            target_ip, source = (gateway, "portal_gateway") if gateway else (None, None)
        else:
            target_ip, source = _resolve_ping_target(
                interface, ssid_ping_target, ping_target
            )
        if target_ip:
            result.ping_target = target_ip
            result.ping_target_source = source
            log.info("SSID %s: Ping-Ziel %s (%s)", ssid, target_ip, source)
            sent, received, rtt_avg = _ping(interface, target_ip, ping_count)
            result.ping_sent = sent
            result.ping_received = received
            result.ping_rtt_avg_ms = rtt_avg

        survey_idle_end = _survey_in_use(interface)
        baseline = _survey_delta(survey_connected, survey_idle_end)
        if baseline is not None:
            result.channel_load = {
                "frequency_mhz": survey_idle_end["freq"],
                "channel": _freq_to_channel(survey_idle_end["freq"]),
                "baseline": baseline,
            }

        if not portal_blocked and iperf3_server and shutil.which("iperf3"):
            # Zeitraum der Last merken: das Dashboard markiert ihn im Verlauf,
            # damit eine Auslastungsspitze der eigenen Messung zuordenbar ist.
            result.iperf3_started_at = _utc_iso()
            result.iperf3_bitrate_mbps = iperf3_bitrate_mbps or None
            result.iperf3_mbps, result.iperf3_retransmits = _iperf3(
                interface, ip_addr, iperf3_server, iperf3_duration, port=iperf3_port,
                bitrate_mbps=iperf3_bitrate_mbps,
            )
            # Bei fehlgeschlagenem Upload (Server nicht erreichbar/belegt)
            # lohnt der Download-Versuch nicht - spart die Wartezeit.
            if iperf3_download and result.iperf3_mbps is not None:
                time.sleep(1)
                (
                    result.iperf3_download_mbps,
                    result.iperf3_download_retransmits,
                ) = _iperf3(
                    interface, ip_addr, iperf3_server, iperf3_duration,
                    reverse=True, port=iperf3_port, bitrate_mbps=iperf3_bitrate_mbps,
                )
            result.iperf3_ended_at = _utc_iso()
            under_load = _survey_delta(survey_idle_end, _survey_in_use(interface))
            if under_load is not None:
                result.channel_load = result.channel_load or {
                    "frequency_mhz": survey_idle_end["freq"],
                    "channel": _freq_to_channel(survey_idle_end["freq"]),
                }
                result.channel_load["iperf3"] = under_load

        # Zusatzdaten sind reine Diagnose: ein Fehler beim Auslesen darf den
        # (bis hierhin erfolgreichen) Test nicht als fehlgeschlagen markieren.
        try:
            result.link = _wifi_link_info(interface)
            result.counters = _counter_delta(
                counters_before, _read_counters(interface)
            )
        except Exception:  # noqa: BLE001
            log.debug("Link-/Zaehler-Info nicht auslesbar", exc_info=True)

        return result

    except Exception as exc:  # noqa: BLE001 - Testergebnis statt Absturz
        result.error = str(exc)
        result.error_codes = [_err("internal_error", detail=str(exc))]
        return result

    finally:
        # Portal-Sitzung beenden, solange das Interface noch verbunden ist.
        # Best effort: die Abmeldung darf den Test nie kippen.
        if (
            portal_module is not None and portal_ctx is not None
            and portal_session is not None
            and (portal_login or {}).get("logoff", True)
            and result.captive_portal is not None
        ):
            try:
                result.captive_portal["logoff"] = portal_module.logoff(
                    portal_ctx, portal_session
                )
            except Exception:  # noqa: BLE001
                log.debug("Portal-Abmeldung fehlgeschlagen", exc_info=True)
        # Vor dem Aufraeumen stoppen - dessen Disconnect gehoert nicht zum Fehlerbild.
        if capture is not None:
            if not result.connected:
                # Das Log von wpa_supplicant ist erst nach dessen Ende vollstaendig
                # (gepufferte Ausgabe). Nach Assoziations-/802.1X-Fehlern ist er
                # schon beendet, nach einem DHCP-Fehlschlag laeuft er noch - dann
                # erscheint am Ende der Ereignisse ein eigener Disconnect
                # ("local request"), der das Testende markiert.
                _stop_wpa(wpa_proc)
            result.capture_id = capture.finish(keep=not result.connected, wpa_log_path=wpa_log_path)
        _cleanup_connection(interface, wpa_proc, restore_mac)
        conf_path.unlink(missing_ok=True)
        if eap_workdir is not None:
            shutil.rmtree(eap_workdir, ignore_errors=True)
        Path(wpa_log_path).unlink(missing_ok=True)


def _dhcp_failure_detail(
    interface: str,
    counters_before: dict[str, int] | None,
    result: "ConnectionTestResult",
) -> tuple[str, dict]:
    """Kurzer Zusatz fuer die Fehlermeldung "kein DHCP-Lease": an welchem AP
    (BSSID) der Client haengt, mit welchem Signal, und wie viele Pakete
    waehrend der DHCP-Phase ankamen/rausgingen. Rx = 0 heisst: vom Netz
    kommt gar nichts zurueck. Rueckgabe (Textzusatz, Parameter fuer den
    Fehlercode "dhcp_timeout"). Setzt nebenbei result.link/counters, damit
    das Dashboard die Werte auch fuer fehlgeschlagene Tests zeigt. Reine
    Diagnose - darf nie selbst einen Fehler ausloesen."""
    try:
        link = _wifi_link_info(interface)
        result.link = link
        delta = _counter_delta(counters_before, _read_counters(interface))
        result.counters = delta
        parts = []
        params: dict = {}
        if link:
            if link.get("bssid"):
                parts.append(f"AP {link['bssid']}")
                params["bssid"] = link["bssid"]
            if link.get("signal_dbm") is not None:
                parts.append(f"{link['signal_dbm']} dBm")
                params["signal_dbm"] = link["signal_dbm"]
            if link.get("frequency_mhz"):
                parts.append(f"{link['frequency_mhz']} MHz")
                params["frequency_mhz"] = link["frequency_mhz"]
        else:
            parts.append("Link bereits getrennt")
            params["link_down"] = True
        if delta:
            parts.append(f"waehrend DHCP Rx {delta['rx_packets']} / Tx {delta['tx_packets']} Pakete")
            params["rx_packets"] = delta["rx_packets"]
            params["tx_packets"] = delta["tx_packets"]
        return (f" [{', '.join(parts)}]" if parts else ""), params
    except Exception:  # noqa: BLE001
        log.debug("DHCP-Fehlerdetails nicht auslesbar", exc_info=True)
        return "", {}


def _log_network_debug(interface: str) -> None:
    """
    Loggt Routing-/rp_filter-Zustand direkt während der kurzen
    Verbindungsphase eines Tests. Das Interface ist nur wenige Sekunden
    verbunden - manuelles Nachschauen per SSH kommt da nicht hinterher,
    daher hier automatisch bei jedem Testlauf. Nur bei aktivem
    DEBUG-Level, um im Normalbetrieb keine zusätzlichen Subprozesse zu
    starten.
    """
    if not log.isEnabledFor(logging.DEBUG):
        return
    for label, cmd in [
        ("ip addr", ["ip", "addr", "show", "dev", interface]),
        ("ip route", ["ip", "route", "show"]),
        ("ip route (alle Tabellen)", ["ip", "route", "show", "table", "all"]),
        (
            "rp_filter",
            [
                "sysctl",
                "net.ipv4.conf.all.rp_filter",
                "net.ipv4.conf.default.rp_filter",
                f"net.ipv4.conf.{interface}.rp_filter",
            ],
        ),
    ]:
        result = _run(cmd, check=False)
        log.debug("%s:\n%s", label, (result.stdout or result.stderr).strip())


# Zeilen im wpa_supplicant-Log ohne Aussage zur Fehlerursache: P2P-Geraet,
# DSCP-Policy und das Herunterfahren, das _stop_wpa() selbst ausloest
# (Deauth reason=3 locally_generated=1, deinit, TERMINATING). Am Log-Ende
# verdraengen sie sonst die eigentlich interessanten EAP-Zeilen.
_WPA_NOISE_RE = re.compile(
    r"^p2p-dev-|CTRL-EVENT-DSCP-POLICY|nl80211: deinit|CTRL-EVENT-TERMINATING"
    r"|CTRL-EVENT-DISCONNECTED .*reason=3 locally_generated=1"
    r"|Successfully initialized wpa_supplicant|CTRL-EVENT-REGDOM-CHANGE"
)


def _tail(path: str, max_chars: int = 500) -> str:
    """Letzte relevante Zeilen einer wpa_supplicant-Log-Datei für
    Fehlermeldungen, gekürzt."""
    try:
        lines = Path(path).read_text(errors="replace").splitlines()
    except OSError:
        return ""
    relevant = [
        re.sub(r" hash=[0-9a-f]+", "", line.strip())
        for line in lines
        if line.strip() and not _WPA_NOISE_RE.search(line)
    ]
    content = " | ".join(relevant)
    if not content:
        return ""
    if len(content) > max_chars:
        content = "..." + content[-max_chars:]
    return f"wpa_supplicant-Log: {content}"


# wpa_state-Werte, die erst nach dem Scan kommen: wpa_supplicant hat einen
# passenden AP gefunden und beginnt die Anmeldung daran.
_POST_SCAN_STATES = (
    "wpa_state=AUTHENTICATING", "wpa_state=ASSOCIATING", "wpa_state=ASSOCIATED",
    "wpa_state=4WAY_HANDSHAKE", "wpa_state=GROUP_HANDSHAKE", "wpa_state=COMPLETED",
)


def _wait_for_association(
    interface: str, timeout: int, t0: float
) -> tuple[bool, float | None, bool]:
    """Wartet auf die 802.11-Assoziation ("iw link" zeigt "Connected to").

    Rueckgabe (verbunden, scan_seconds, aufgegeben):
    - scan_seconds: Sekunden seit t0, bis wpa_supplicant den Scan beendet und
      die Anmeldung an einem AP beginnt (wpa_state verlaesst SCANNING).
      assoc_seconds bleibt wie bisher die Gesamtzeit inkl. Scan; die reine
      Assoziation ist assoc_seconds - scan_seconds. None, wenn das Scan-Ende
      nicht beobachtet wurde (z.B. AP nie gefunden).
    - aufgegeben: wpa_supplicant hat das Netz gesperrt ([TEMP-DISABLED], z.B.
      nach abgelehnter WPA3-Anmeldung) - dann sofort zurueck statt bis zum
      Timeout weitere Anmeldeversuche laufen zu lassen.

    wpa_cli status wird nur bis zum Scan-Ende abgefragt, list_networks danach
    hoechstens einmal pro Sekunde - so bleibt der 0,1-s-Takt von "iw link"
    fuer assoc_seconds praktisch unveraendert."""
    deadline = t0 + timeout
    scan_seconds: float | None = None
    next_disabled_check = 0.0
    while time.monotonic() < deadline:
        if scan_seconds is None:
            status = _run(["wpa_cli", "-i", interface, "status"], check=False, timeout=5)
            if any(state in status.stdout for state in _POST_SCAN_STATES):
                scan_seconds = round(time.monotonic() - t0, 2)
        result = _run(["iw", "dev", interface, "link"], check=False)
        if "Connected to" in result.stdout:
            if scan_seconds is None:
                # Anmeldung lief zwischen zwei Abfragen komplett durch - das
                # Scan-Ende liegt dann kurz vor diesem Zeitpunkt.
                scan_seconds = round(time.monotonic() - t0, 2)
            return True, scan_seconds, False
        if scan_seconds is not None and time.monotonic() >= next_disabled_check:
            next_disabled_check = time.monotonic() + 1.0
            networks = _run(["wpa_cli", "-i", interface, "list_networks"], check=False, timeout=5)
            if "[TEMP-DISABLED]" in networks.stdout:
                return False, scan_seconds, True
        # Kurzer Takt: die Abfrage bestimmt die Messgenauigkeit von
        # assoc_seconds (bei 1 s landeten alle Werte auf vollen Sekunden).
        time.sleep(0.1)
    return False, scan_seconds, False


def _clear_promiscuous_mode(interface: str) -> None:
    """Manche Treiber (beobachtet: brcmfmac auf dem Onboard-WLAN eines
    Raspberry Pi 5) lassen das Interface nach einem Scan vereinzelt im
    Promiscuous-Modus stehen (sichtbar in "dmesg" als "entered
    promiscuous mode" ohne dazu passendes "left promiscuous mode"
    danach) - dadurch empfaengt das Interface waehrend der
    anschliessenden DHCP-Phase JEDEN Unicast-Frame in der Luft statt nur
    die eigenen. In einem belebten Netz mit vielen Clients kann das
    dhclients Duplicate-Address-Detection faelschlich einen IP-Konflikt
    vermuten lassen (Symptom: sofortiges DHCPDECLINE direkt nach
    DHCPACK) oder das Interface unter der zusaetzlichen Paketlast
    "Network is down" melden. Rein defensiv vor jedem DHCP-Versuch
    aufgerufen - wirft nie (check=False), schadet nicht, wenn der Modus
    ohnehin schon aus ist oder das Kommando auf einer Plattform fehlt."""
    _run(["ip", "link", "set", interface, "promisc", "off"], check=False)


def _disable_power_save(interface: str) -> None:
    """WLAN-Power-Management ausschalten, direkt nach jeder Assoziation.
    Bekanntes Problem bei Broadcom/Cypress-Chips (u.a. das Onboard-WLAN
    des Raspberry Pi 5, Treiber "brcmfmac") mit aktivem Power-Save:
    periodische Sleep-/Wake-Zyklen (sichtbar in "dmesg" als wiederkehrendes
    "brcmf_cfg80211_set_power_mgmt: power save enabled", auch ausserhalb
    aktiver Tests) koennen mitten in einer laufenden DHCP-Anfrage zu
    Paketverlust oder einem kurzen Verbindungsabbruch fuehren
    ("receive_packet failed: Network is down"). Rein defensiv, wirft nie
    (check=False) - schadet nicht, wenn Power-Save ohnehin schon aus ist
    oder der Treiber die Einstellung nicht unterstuetzt."""
    _run(["iw", "dev", interface, "set", "power_save", "off"], check=False)


def _dhclient_lease_file(interface: str) -> str:
    return f"/tmp/wlanmon-probe-dhclient-{interface}.leases"


def _run_dhcp(interface: str, timeout: int) -> str | None:
    # dhclient bevorzugt (weit verbreitet auf Armbian/Debian-Images).
    dhcp_bin = "dhclient" if shutil.which("dhclient") else "dhcpcd"
    try:
        if dhcp_bin == "dhclient":
            # Aktuelle isc-dhcp-client-Versionen kennen kein "-timeout"
            # CLI-Flag mehr (nur noch per dhclient.conf konfigurierbar).
            # "-1" lässt dhclient nach einem Versuch beenden statt im
            # Hintergrund weiterzulaufen; die Laufzeit wird stattdessen
            # über das subprocess-Timeout unten begrenzt.
            #
            # "-lf" auf ein eigenes, vor jedem Versuch geleertes Lease-
            # File: ohne das versucht dhclient beim naechsten Aufruf,
            # eine im (System-)Lease-File gespeicherte alte Lease per
            # "INIT-REBOOT" (direktes DHCPREQUEST statt frischem
            # DISCOVER) wiederzuverwenden - live beobachtet: nach einem
            # AP-Wechsel (Roaming auf dieselbe SSID) fuehrte genau das
            # zuverlaessig zu einem DHCP-Timeout, vermutlich weil die
            # Infrastruktur eine solche Wiederverwendung nach einem
            # BSSID-Wechsel nicht sauber beantwortet. Jeder Connection-
            # Test ist ohnehin eine unabhaengige, frische Messung, kein
            # dauerhafter Netzwerkzustand - _cleanup_connection() nutzt
            # dasselbe Lease-File fuer den "-r"-Aufruf.
            lease_file = _dhclient_lease_file(interface)
            Path(lease_file).write_text("")
            _run(["dhclient", "-1", "-lf", lease_file, interface], timeout=timeout + 5)
        else:
            # "-L"/"--noipv4ll": dhcpcd weicht ohne diese Option nach ein
            # paar Sekunden ohne DHCP-Antwort auf eine selbst vergebene
            # IPv4LL-Adresse (169.254.x.x, RFC 3927 "Zeroconf") aus und
            # meldet trotzdem einen Erfolg - eine solche Adresse kann
            # aber nicht ins eigentliche Netz routen (live beobachtet:
            # anschliessender iperf3-Test lief prompt in einen Timeout).
            #
            # "-A"/"--noarp": ohne das prueft dhcpcd die angebotene Adresse
            # erst per ARP auf Doppelvergabe (RFC 5227) und meldet sich
            # erst danach zurueck - mehrere Sekunden, die komplett in
            # dhcp_seconds landen (Pi 5 mit dhcpcd: ~6 s, dhclient-Geraete
            # im selben Netz: ~1 s).
            # "-4": nur IPv4, ausgewertet wird ohnehin nur die IPv4-Adresse.
            # Achtung: eine "-4"-Instanz hat einen eigenen Steuer-Socket,
            # daher in _cleanup_connection() auch "-k" mit "-4".
            _run(["dhcpcd", "-4", "-A", "-L", "-t", str(timeout), interface], timeout=timeout + 5)
    except (WifiOpsError, subprocess.TimeoutExpired):
        return None

    result = _run(["ip", "-4", "-o", "addr", "show", "dev", interface], check=False)
    m = re.search(r"inet (\d+\.\d+\.\d+\.\d+)", result.stdout)
    ip_addr = m.group(1) if m else None
    # Sicherheitsnetz zusaetzlich zu "-L" oben: eine IPv4LL-Adresse zaehlt
    # nie als erfolgreiche DHCP-Zuweisung, egal wie sie zustande kam.
    if ip_addr is not None and ip_addr.startswith("169.254."):
        return None
    return ip_addr


# Standard-Detection-URL (liefert ohne Portal HTTP 204), siehe
# check_captive_portal().
DEFAULT_CAPTIVE_PORTAL_URL = "http://connectivitycheck.gstatic.com/generate_204"


def check_captive_portal(interface: str, url: str, timeout: float = 10.0) -> dict:
    """
    Erkennt ein Captive Portal ueber eine Detection-URL, die ohne Portal
    HTTP 204 (leer) liefert, z.B. http://connectivitycheck.gstatic.com/generate_204.
    Redirects werden bewusst NICHT verfolgt: ein 3xx (oder jede andere
    Antwort als 204) heisst, dass ein Portal die Anfrage abgefangen hat.

    Rueckgabe: {detected: bool|None, http_status, seconds, tcp_connect_ms,
    response_ms, redirect_url, error}. detected=None heisst "nicht pruefbar"
    (DNS-/Verbindungsfehler, Timeout) - das ist ein eigener Befund, nicht
    "kein Portal". Wirft nie.

    tcp_connect_ms (TCP-Handshake) und response_ms (Anfrage gesendet bis
    Antwort-Header da) laufen beide ueber das WLAN-Interface und kommen auch
    durch ein Portal, das ICMP sperrt (die Antwort liefert dann der AP bzw.
    das Portal selbst) - eine Latenz, wo der Ping nichts liefert. Die
    DNS-Aufloesung ist bewusst nicht enthalten: sie laeuft ueber den
    System-Resolver, nicht zwingend ueber das WLAN; "seconds" (gesamt, inkl.
    DNS) bleibt aus Kompatibilitaet erhalten.
    """
    out: dict = {
        "detected": None, "http_status": None, "seconds": None,
        "tcp_connect_ms": None, "response_ms": None,
        "redirect_url": None, "url": url, "error": None,
    }
    try:
        parts = urlsplit(url)
        if parts.scheme != "http" or not parts.hostname:
            # HTTPS wuerde eine Portal-Umleitung nur als Zertifikatsfehler
            # zeigen - Detection-URLs sind deshalb absichtlich Klartext-HTTP.
            out["error"] = "Detection-URL muss mit http:// beginnen"
            return out
        path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
        port = parts.port or 80
        t0 = time.monotonic()
        # Vorab aufloesen, damit die Zeitmessung unten nur die WLAN-Strecke
        # erfasst (Host-Header wird explizit gesetzt, die IP genuegt also).
        host_ip = socket.getaddrinfo(
            parts.hostname, port, socket.AF_INET, socket.SOCK_STREAM
        )[0][4][0]
        conn = BoundHTTPConnection(host_ip, port, interface, timeout)
        try:
            t_connect = time.monotonic()
            conn.connect()
            out["tcp_connect_ms"] = round((time.monotonic() - t_connect) * 1000, 1)
            t_request = time.monotonic()
            conn.request("GET", path, headers={
                "Host": parts.netloc, "Connection": "close",
                "User-Agent": "wlanmon-probe/captive-check",
            })
            resp = conn.getresponse()
            out["response_ms"] = round((time.monotonic() - t_request) * 1000, 1)
            body = resp.read(2048)
            out["seconds"] = round(time.monotonic() - t0, 2)
            out["http_status"] = resp.status
            location = resp.getheader("Location")
        finally:
            conn.close()
        if resp.status == 204 and not body:
            out["detected"] = False
        elif 300 <= resp.status < 400 or resp.status in (200, 511):
            # Redirect auf die Portal-Seite, eingeschobene Portal-HTML
            # (200) oder ausdrueckliches "Network Authentication Required".
            out["detected"] = True
            if location:
                out["redirect_url"] = location[:300]
        else:
            # z.B. 502/503/404: eher ein Problem hinter dem Gateway als ein
            # Portal - nicht als Portal melden, sonst gibt es Fehlalarme.
            out["error"] = f"unerwartete Antwort HTTP {resp.status}"
    except (OSError, http.client.HTTPException) as exc:
        out["error"] = f"{type(exc).__name__}: {exc}"[:200]
    except Exception as exc:  # noqa: BLE001 - reine Diagnose
        log.debug("Captive-Portal-Check fehlgeschlagen", exc_info=True)
        out["error"] = f"{type(exc).__name__}: {exc}"[:200]
    log.info(
        "Captive-Portal-Check %s: detected=%s status=%s redirect=%s error=%s",
        url, out["detected"], out["http_status"], out["redirect_url"], out["error"],
    )
    return out


def _default_gateway(interface: str) -> str | None:
    """Default-Gateway, den DHCP fuer dieses Interface gesetzt hat."""
    try:
        res = _run(
            ["ip", "-4", "route", "show", "default", "dev", interface],
            timeout=5,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return None
    m = re.search(r"default via (\d+\.\d+\.\d+\.\d+)", res.stdout)
    return m.group(1) if m else None


def _resolve_ping_target(
    interface: str, ssid_target: str, fallback: str
) -> tuple[str, str]:
    """Ping-Ziel bestimmen: SSID-Ziel > Default-Gateway > globaler Fallback."""
    ssid_target = (ssid_target or "").strip()
    if ssid_target:
        return ssid_target, "ssid"
    gateway = _default_gateway(interface)
    if gateway:
        return gateway, "gateway"
    return (fallback or "").strip() or "1.1.1.1", "fallback"


def _ping(interface: str, target: str, count: int) -> tuple[int, int, float | None]:
    result = _run(
        ["ping", "-I", interface, "-c", str(count), "-W", "2", target],
        timeout=count * 3 + 5,
        check=False,
    )
    log.debug(
        "ping -I %s -c %d %s (rc=%d):\n%s",
        interface, count, target, result.returncode, result.stdout.strip(),
    )
    sent = count
    received_m = re.search(r"(\d+) received", result.stdout)
    received = int(received_m.group(1)) if received_m else 0
    rtt_m = re.search(r"= [\d.]+/([\d.]+)/", result.stdout)
    rtt_avg = float(rtt_m.group(1)) if rtt_m else None
    return sent, received, rtt_avg


_bind_dev_supported: bool | None = None


def _iperf3_supports_bind_dev() -> bool:
    """--bind-dev (SO_BINDTODEVICE) gibt es erst ab iperf3 3.12; einmal
    prüfen und merken statt bei jedem Test."""
    global _bind_dev_supported
    if _bind_dev_supported is None:
        try:
            res = _run(["iperf3", "--help"], timeout=5, check=False)
            _bind_dev_supported = "--bind-dev" in (res.stdout + res.stderr)
        except (OSError, subprocess.TimeoutExpired):
            _bind_dev_supported = False
        log.debug("iperf3 --bind-dev unterstuetzt: %s", _bind_dev_supported)
    return _bind_dev_supported


def _iperf3_measure(
    server: str,
    duration: int,
    bind_ip: str,
    bind_dev: str | None = None,
    reverse: bool = False,
    port: int = 5201,
    bitrate_mbps: float = 0,
) -> tuple[float | None, int | None, str | None, dict | None]:
    """
    Ein iperf3-Lauf, Rückgabe (Mbit/s, TCP-Retransmits des Senders,
    Fehlertext, Fehlercode). reverse=True misst den Download (Server sendet, -R), sonst
    den Upload; die Retransmits gehoeren jeweils zur sendenden Seite.
    bitrate_mbps > 0 begrenzt die Senderate (-b), sonst sendet iperf3 so
    schnell es geht und lastet den Kanal fuer die Testdauer voll aus.

    -B <bind_ip> statt "0.0.0.0": Letzteres bindet an gar keine bestimmte
    Adresse, der Traffic könnte dann z.B. über eth0 statt über das
    eigentlich zu testende WLAN-Interface laufen, falls beide im selben
    Subnetz hängen (siehe ARP-Flux-Hinweis in wifi_ops.py/README) - der
    Messwert wäre dann wertlos. Die Quelladresse allein legt aber die
    Route nicht sicher fest; --bind-dev <interface> (SO_BINDTODEVICE)
    erzwingt zusätzlich das Ausgangs-Interface, sofern iperf3 es kennt.

    Bei "server is busy" (der Server bedient nur einen Test gleichzeitig,
    z.B. wenn zwei Probes dieselbe NAS testen) wird bis zu dreimal
    versucht. Die Pause richtet sich nach der Testdauer: eine andere Probe
    belegt den Server mit Upload + Download (2 x duration) plus Aufbau -
    feste 5 s haetten bei laengeren Tests aufgegeben, kurz bevor der Server
    frei wird. Haengt der Server dauerhaft auf "busy", hilft das nicht
    (dann iperf3 serverseitig mit --idle-timeout betreiben, siehe README).
    """
    cmd = [
        "iperf3", "-c", server, "-p", str(port), "-t", str(duration),
        "-J", "-B", bind_ip,
    ]
    if bind_dev and _iperf3_supports_bind_dev():
        cmd += ["--bind-dev", bind_dev]
    if reverse:
        cmd.append("-R")
    if bitrate_mbps and bitrate_mbps > 0:
        cmd += ["-b", f"{bitrate_mbps:g}M"]

    error: str | None = None
    attempts = 3
    busy_wait = duration + 5
    for attempt in range(1, attempts + 1):
        try:
            res = _run(cmd, timeout=duration + 15, check=False)
        except subprocess.TimeoutExpired:
            return None, None, "iperf3 Timeout", _err("iperf3_timeout")
        log.debug(
            "%s (rc=%d):\n%s", " ".join(cmd), res.returncode, res.stdout.strip()
        )
        try:
            data = json.loads(res.stdout)
        except ValueError:
            msg = (res.stderr.strip() or res.stdout.strip())[:200]
            if not msg:
                return None, None, "keine Ausgabe", _err("iperf3_no_output")
            return None, None, msg, _err("iperf3_failed", detail=msg)
        error = data.get("error")
        if error is None:
            end = data.get("end") or {}
            summary = end.get("sum_received") or end.get("sum_sent") or {}
            bps = summary.get("bits_per_second")
            if bps is None:
                return None, None, "iperf3-Ausgabe ohne Ergebnis", _err("iperf3_no_result")
            retransmits = (end.get("sum_sent") or {}).get("retransmits")
            return round(float(bps) / 1_000_000, 2), retransmits, None, None
        if "busy" not in error.lower():
            break
        if attempt == attempts:
            # Fehlertext von iperf3 selbst (englisch) bleibt, die Versuche
            # stehen im deutschen Text bzw. als Parameter im Code.
            return (None, None,
                    f"{error} ({attempts} Versuche im Abstand von {busy_wait} s)"[:200],
                    _err("iperf3_failed", detail=str(error)[:200], attempts=attempts, wait_s=busy_wait))
        log.info(
            "iperf3-Server %s:%d belegt (Versuch %d/%d), neuer Versuch in %d s",
            server, port, attempt, attempts, busy_wait,
        )
        time.sleep(busy_wait)
    return None, None, str(error)[:200], _err("iperf3_failed", detail=str(error)[:200])


def _iperf3(
    interface: str,
    bind_ip: str,
    server: str,
    duration: int,
    reverse: bool = False,
    port: int = 5201,
    bitrate_mbps: float = 0,
) -> tuple[float | None, int | None]:
    """Durchsatz über das WLAN-Interface (Connection-Test): (Mbit/s,
    Retransmits). reverse=True misst den Download."""
    mbps, retransmits, error, _code = _iperf3_measure(
        server, duration, bind_ip, bind_dev=interface, reverse=reverse, port=port,
        bitrate_mbps=bitrate_mbps,
    )
    if error:
        log.warning(
            "iperf3 %s (%s -> %s) fehlgeschlagen: %s",
            "Download" if reverse else "Upload", interface, server, error,
        )
    return mbps, retransmits


# --------------------------------------------------------------------------
# LAN-Durchsatztest (unabhängig vom WLAN)
# --------------------------------------------------------------------------

@dataclass
class LanIperfResult:
    interface: str
    server: str
    ip_address: str | None = None
    upload_mbps: float | None = None
    download_mbps: float | None = None
    # TCP-Retransmits der jeweils sendenden Seite (Upload: die Probe,
    # Download: der iperf3-Server) - Hinweis auf Paketverluste im Pfad.
    upload_retransmits: int | None = None
    download_retransmits: int | None = None
    # Differenz der Zaehler des Interface ueber den ganzen Test
    # (Pakete/Bytes/Fehler/Drops), siehe _read_counters().
    counters: dict | None = None
    error: str | None = None
    # Strukturierte Fassung von "error" fuer das Dashboard (siehe _err()).
    error_codes: list[dict] | None = None


def run_lan_iperf3(
    interface: str, server: str, duration: int, port: int = 5201
) -> LanIperfResult:
    """
    iperf3 (Upload + Download) über die kabelgebundene Schnittstelle. Braucht
    weder das Funkmodul noch den wifi_lock - laeuft unabhaengig vom WLAN.
    """
    result = LanIperfResult(interface=interface, server=server)
    if not shutil.which("iperf3"):
        result.error = "iperf3 nicht installiert"
        result.error_codes = [_err("iperf3_missing")]
        return result

    result.ip_address = interface_ipv4(interface)
    if not result.ip_address:
        result.error = f"Interface {interface} hat keine IPv4-Adresse"
        result.error_codes = [_err("no_ipv4", interface=interface)]
        return result

    counters_before = _read_counters(interface)
    try:
        (result.upload_mbps, result.upload_retransmits, up_err, up_code) = _iperf3_measure(
            server, duration, result.ip_address, bind_dev=interface, port=port
        )
        if up_err:
            # Server nicht erreichbar/beschaeftigt: Download waere dasselbe
            # Problem, spart die Wartezeit eines zweiten Fehlversuchs.
            result.error = f"Upload: {up_err}"
            result.error_codes = [_err("lan_upload_failed")] + ([up_code] if up_code else [])
            return result

        time.sleep(1)
        (result.download_mbps, result.download_retransmits, down_err, down_code) = _iperf3_measure(
            server, duration, result.ip_address, bind_dev=interface,
            reverse=True, port=port,
        )
        if down_err:
            result.error = f"Download: {down_err}"
            result.error_codes = [_err("lan_download_failed")] + ([down_code] if down_code else [])
        return result
    finally:
        result.counters = _counter_delta(counters_before, _read_counters(interface))


def _read_mac(interface: str) -> str | None:
    try:
        return Path(f"/sys/class/net/{interface}/address").read_text().strip().lower() or None
    except OSError:
        return None


def _permanent_mac(interface: str) -> str | None:
    """Fest eingebrannte MAC des WLAN-Chips (Wiphy), unabhaengig von einer
    evtl. noch gesetzten zufaelligen Adresse. Faellt auf die aktuelle
    Interface-MAC zurueck."""
    try:
        perm = Path(f"/sys/class/net/{interface}/phy80211/macaddress").read_text().strip().lower()
        if perm:
            return perm
    except OSError:
        pass
    return _read_mac(interface)


def _random_local_mac() -> str:
    """Zufaellige, lokal verwaltete Unicast-MAC (Bit 1 gesetzt, Bit 0 nicht)."""
    octets = list(os.urandom(6))
    octets[0] = (octets[0] & 0xFE) | 0x02
    return ":".join(f"{b:02x}" for b in octets)


def _set_mac(interface: str, mac: str) -> bool:
    """Setzt die MAC des Interfaces (dafuer kurz herunterfahren). True bei
    Erfolg; Treiber, die das nicht erlauben, liefern False."""
    _run(["ip", "link", "set", interface, "down"], check=False)
    res = _run(["ip", "link", "set", "dev", interface, "address", mac], check=False)
    if res.returncode != 0:
        log.warning("MAC-Aenderung auf %s fehlgeschlagen: %s", interface, res.stderr.strip())
        return False
    return _read_mac(interface) == mac


def _cleanup_connection(
    interface: str,
    wpa_proc: subprocess.Popen | None,
    restore_mac: str | None = None,
) -> None:
    if wpa_proc is not None:
        wpa_proc.terminate()
        try:
            wpa_proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            wpa_proc.kill()
    # Beide DHCP-Clients bleiben nach der Anfrage offenbar als
    # Hintergrundprozess aktiv (abweichend von der klassischen Doku) und
    # wuerden sonst den naechsten Scan/Test auf demselben Interface
    # blockieren ("Device or resource busy") - Lease sauber freigeben und
    # den Hintergrundprozess mit beenden. Dieselbe shutil.which()-Logik
    # wie in _run_dhcp(): welcher Client tatsaechlich lief, aendert sich
    # innerhalb eines Testlaufs nicht, so spart sich das Durchreichen
    # extra Zustands. Ohne dieses Matching stuerzte der Aufruf auf
    # Systemen ohne dhclient (z.B. mit nur dhcpcd installiert) mit einer
    # unbehandelten FileNotFoundError ab - "check=False" schuetzt nur vor
    # einem Exitcode != 0, nicht vor einem fehlenden Programm.
    dhcp_bin = "dhclient" if shutil.which("dhclient") else "dhcpcd"
    if dhcp_bin == "dhclient":
        # Dasselbe Lease-File wie in _run_dhcp() (siehe dortiger
        # Kommentar) - "-r" ohne "-lf" wuerde im System-Lease-File nach
        # der Lease suchen, die aber (bewusst) in der eigenen Datei
        # steht, und faende dort nichts zum Freigeben.
        _run(
            ["dhclient", "-r", "-lf", _dhclient_lease_file(interface), interface],
            check=False, timeout=10,
        )
    else:
        # "-4" wie beim Start in _run_dhcp() - sonst sucht dhcpcd den
        # Steuer-Socket der Instanz unter falschem Namen und gibt nichts frei.
        _run(["dhcpcd", "-4", "-k", interface], check=False, timeout=10)
    _run(["ip", "addr", "flush", "dev", interface], check=False)
    _run(["ip", "link", "set", interface, "down"], check=False)
    if restore_mac:
        # Nach einem Test mit zufaelliger MAC die Original-Adresse
        # zurueckgeben, damit Scans und andere SSIDs nicht mit einer
        # uebrig gebliebenen Zufalls-MAC laufen.
        res = _run(["ip", "link", "set", "dev", interface, "address", restore_mac], check=False)
        if res.returncode != 0:
            log.warning("Original-MAC %s liess sich nicht wiederherstellen: %s",
                        restore_mac, res.stderr.strip())
    _run(["ip", "link", "set", interface, "up"], check=False)
    # Kurze Nachlaufzeit, damit der Treiber die Schnittstelle intern
    # vollständig freigibt, bevor der wifi_lock wieder freigegeben wird
    # und z.B. der Scan-Loop sofort einen neuen Scan versucht.
    time.sleep(1.0)

# ---------------------------------------------------------------------
# Mitschnitt fehlgeschlagener Connection-Tests
# ---------------------------------------------------------------------
# Bewusst hier statt in einem eigenen Modul: das Auto-Update kopiert nur
# Dateien aus seiner FILES-Liste (update_probe.py), und auf den Geraeten
# laeuft beim ersten Update noch die alte Liste - ein neues Modul fehlte
# dann, und main.py liesse sich nicht mehr importieren.
#
# Mitschnitt fehlgeschlagener Connection-Tests.
#
# Waehrend jedes Tests laufen zwei Aufzeichnungen mit:
#   - tcpdump auf dem Test-Interface (pcap): EAPOL (802.1X, 4-Way-Handshake),
#     DHCP, ARP, DNS, ICMP. Der Adapter laeuft im Client-Modus ("managed") -
#     die 802.11-Verwaltungsrahmen der Assoziation selbst (Authentication,
#     Association, Deauth) sind darin NICHT enthalten, dafuer braeuchte es
#     einen zweiten Adapter im Monitor-Modus.
#   - "iw event -t" (Text): die Kernel-Ereignisse genau dieser Rahmen -
#     authenticate/associate/connect/deauth/disconnect mit Status- bzw.
#     Reason-Code. Ergaenzt das pcap um den Teil, den tcpdump nicht sieht.
#
# Gelingt der Test, wird beides verworfen. Scheitert er, landen pcap und
# Ereignisse unter einer zufaelligen capture_id im Spool-Verzeichnis; der
# Sender laedt sie ans Dashboard hoch (sender.py) und loescht sie danach.
# Die capture_id steht im Testergebnis, so ordnet das Dashboard den
# Mitschnitt der Testzeile zu.
#
# Fehlen tcpdump oder iw, laeuft der Test ohne die jeweilige Aufzeichnung -
# ein Mitschnitt darf einen Test nie stoeren.
CAPTURE_SPOOL_DIR = Path("/var/lib/wlanmon-probe/captures")
# Unter dem PHP-Standard upload_max_filesize (2 MB) bleiben.
CAPTURE_MAX_PCAP_BYTES = 1_500_000
CAPTURE_MAX_EVENTS_BYTES = 64_000
# wpa_supplicant-Log (ohne -d: Zustaende, EAP-Methode, Zertifikat, Fehler -
# keine Passwoerter/PSKs); vom Ende her gekuerzt.
CAPTURE_MAX_WPA_LOG_BYTES = 128_000
# Haelt das Spool-Verzeichnis klein, falls der Server laenger nichts annimmt.
CAPTURE_MAX_SPOOL_BYTES = 20_000_000
# Nur der Verkehr, der beim Verbindungsaufbau interessiert - kein iperf3.
CAPTURE_PCAP_FILTER = "ether proto 0x888e or arp or udp port 67 or udp port 68 or port 53 or icmp or icmp6"

_capture_warned_missing: set[str] = set()


def _capture_which(tool: str) -> str | None:
    path = shutil.which(tool)
    if path is None and tool not in _capture_warned_missing:
        _capture_warned_missing.add(tool)
        log.warning("%s nicht installiert - Mitschnitt fehlgeschlagener Tests ohne %s", tool, tool)
    return path


class FailureCapture:
    """start() vor dem Verbindungsaufbau, finish(keep) am Ende des Tests."""

    def __init__(self, interface: str, spool_dir: Path = CAPTURE_SPOOL_DIR):
        self._interface = interface
        self._spool = spool_dir
        self._workdir: Path | None = None
        self._tcpdump: subprocess.Popen | None = None
        self._events: subprocess.Popen | None = None
        self._events_file = None
        self._wpa_log_path: str | None = None

    def start(self) -> None:
        try:
            self._workdir = Path(tempfile.mkdtemp(prefix="wlanmon-probe-capture-"))
            tcpdump = _capture_which("tcpdump")
            if tcpdump:
                # -U: jedes Paket sofort schreiben (nichts geht beim Stoppen
                # verloren), -c: harte Obergrenze, falls der Filter doch viel
                # Verkehr durchlaesst. -n: keine DNS-Aufloesung durch tcpdump.
                self._tcpdump = subprocess.Popen(
                    [tcpdump, "-i", self._interface, "-n", "-U", "-c", "5000",
                     "-w", str(self._workdir / "capture.pcap"), CAPTURE_PCAP_FILTER],
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                )
            iw = _capture_which("iw")
            if iw:
                self._events_file = open(self._workdir / "events.txt", "w", encoding="utf-8")
                self._events = subprocess.Popen(
                    [iw, "event", "-t"], stdout=self._events_file, stderr=subprocess.DEVNULL,
                )
        except Exception:  # noqa: BLE001 - Mitschnitt darf den Test nie kippen
            log.warning("Mitschnitt konnte nicht gestartet werden", exc_info=True)
            self._stop_processes()

    def _stop_processes(self) -> None:
        for proc in (self._tcpdump, self._events):
            if proc is None or proc.poll() is not None:
                continue
            try:
                proc.send_signal(signal.SIGINT)  # tcpdump schreibt dann sauber zu Ende
                proc.wait(timeout=3)
            except Exception:  # noqa: BLE001
                proc.kill()
        if self._events_file is not None:
            self._events_file.close()
            self._events_file = None
        self._tcpdump = self._events = None

    def finish(self, keep: bool, wpa_log_path: str | None = None) -> str | None:
        """Stoppt die Aufzeichnung. keep=True: ins Spool-Verzeichnis
        uebernehmen und die capture_id zurueckgeben, sonst verwerfen.
        wpa_log_path: Log von wpa_supplicant - wird mit abgelegt, muss dafuer
        vollstaendig sein (wpa_supplicant beendet, die Ausgabe ist gepuffert)."""
        self._wpa_log_path = wpa_log_path
        if self._workdir is None:
            return None
        self._stop_processes()
        try:
            if not keep:
                return None
            return self._store()
        except Exception:  # noqa: BLE001
            log.warning("Mitschnitt konnte nicht gespeichert werden", exc_info=True)
            return None
        finally:
            shutil.rmtree(self._workdir, ignore_errors=True)
            self._workdir = None

    def _store(self) -> str | None:
        pcap = self._workdir / "capture.pcap"
        events = self._workdir / "events.txt"
        events_text = ""
        if events.exists():
            # iw event zeigt alle Interfaces - nur die Zeilen des Test-Interfaces.
            lines = [line for line in events.read_text(encoding="utf-8", errors="replace").splitlines()
                     if f" {self._interface} " in f" {line} " or f"{self._interface}:" in line]
            events_text = "\n".join(lines)[-CAPTURE_MAX_EVENTS_BYTES:]
        has_pcap = pcap.exists() and pcap.stat().st_size > 24  # 24 Byte = nur pcap-Header
        if pcap.exists() and not has_pcap:
            # tcpdump lief, sah aber kein passendes Paket - z.B. weil
            # wpa_supplicant EAPOL ueber den nl80211-Control-Port schickt und
            # die Rahmen dann nicht ueber das Interface laufen.
            log.info("Mitschnitt: pcap leer (kein EAPOL/DHCP/ARP/DNS/ICMP auf %s gesehen)", self._interface)
        wpa_text = ""
        if self._wpa_log_path:
            try:
                wpa_text = Path(self._wpa_log_path).read_text(encoding="utf-8", errors="replace")
                wpa_text = wpa_text[-CAPTURE_MAX_WPA_LOG_BYTES:]
            except OSError:
                pass
        if not has_pcap and not events_text and not wpa_text:
            return None
        if has_pcap and pcap.stat().st_size > CAPTURE_MAX_PCAP_BYTES:
            log.warning("Mitschnitt mit %d Byte zu gross, nur Ereignisse werden behalten", pcap.stat().st_size)
            has_pcap = False

        capture_id = uuid.uuid4().hex
        self._spool.mkdir(parents=True, exist_ok=True)
        os.chmod(self._spool, 0o700)  # enthaelt MACs und 802.1X-Identitaeten
        if has_pcap:
            shutil.move(str(pcap), self._spool / f"{capture_id}.pcap")
        if wpa_text:
            (self._spool / f"{capture_id}.wpa.txt").write_text(wpa_text, encoding="utf-8")
        # events.txt zuletzt: daran erkennt capture_pending() einen vollstaendigen Mitschnitt.
        (self._spool / f"{capture_id}.events.txt").write_text(events_text, encoding="utf-8")
        capture_prune_spool(self._spool)
        log.info("Mitschnitt des fehlgeschlagenen Tests gespeichert (capture_id %s)", capture_id)
        return capture_id


def capture_pending(spool_dir: Path = CAPTURE_SPOOL_DIR) -> list[str]:
    """capture_ids im Spool-Verzeichnis, aelteste zuerst."""
    if not spool_dir.is_dir():
        return []
    files = sorted(spool_dir.glob("*.events.txt"), key=lambda p: p.stat().st_mtime)
    return [p.name[: -len(".events.txt")] for p in files]


def capture_files(
    capture_id: str, spool_dir: Path = CAPTURE_SPOOL_DIR
) -> tuple[Path | None, Path, Path | None]:
    """(pcap, events, wpa_log) eines Mitschnitts; pcap/wpa_log None, wenn nicht vorhanden."""
    pcap = spool_dir / f"{capture_id}.pcap"
    wpa = spool_dir / f"{capture_id}.wpa.txt"
    return (
        pcap if pcap.exists() else None,
        spool_dir / f"{capture_id}.events.txt",
        wpa if wpa.exists() else None,
    )


def capture_remove(capture_id: str, spool_dir: Path = CAPTURE_SPOOL_DIR) -> None:
    for path in capture_files(capture_id, spool_dir):
        if path is not None:
            path.unlink(missing_ok=True)


def capture_prune_spool(spool_dir: Path = CAPTURE_SPOOL_DIR, max_bytes: int = CAPTURE_MAX_SPOOL_BYTES) -> None:
    """Aelteste Mitschnitte verwerfen, solange das Verzeichnis zu gross ist."""
    ids = capture_pending(spool_dir)
    sizes = {cid: sum(p.stat().st_size for p in capture_files(cid, spool_dir) if p is not None and p.exists())
             for cid in ids}
    total = sum(sizes.values())
    for cid in ids:
        if total <= max_bytes:
            break
        log.warning("Spool fuer Mitschnitte voll - verwerfe aeltesten (%s)", cid)
        capture_remove(cid, spool_dir)
        total -= sizes[cid]
