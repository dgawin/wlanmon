"""
OLED-Display (SSD1306, I2C) + 3 Hardware-Buttons der NanoHat OLED.

Hardware auf einem laufenden Geraet verifiziert (siehe README.md):
  - Display: SSD1306-kompatibel, I2C-Adresse 0x3c, Bus i2c-0
    (`i2cdetect -y 0` zeigt 0x3c; kein Framebuffer-Geraet vorhanden,
    d.h. Ansteuerung passiert direkt per I2C aus dem Userspace)
  - Buttons: sysfs-GPIO 0, 2, 3 (im FPMS-Quelltext gpio_d0/d1/d2),
    konfiguriert mit edge=rising

Ersetzt den bisherigen FPMS-Daemon (`fpms.service`/`oled-start`)
vollstaendig fuer dieses Geraet - FPMS muss deaktiviert sein (siehe
setup_wlanmon_probe.sh), sonst konkurrieren beide Prozesse um denselben
I2C-Bus und dieselben GPIOs.

Zeigt den von ProbeStatus gehaltenen Zustand in mehreren Screens, per
Button 1/2 navigierbar; Button 3 loest einen sofortigen Connection-Test
aus (nuetzlich fuer Vor-Ort-Diagnose direkt am Geraet statt auf das
naechste Intervall zu warten).

Nur aktiv, wenn `display.enabled: true` in config.yaml UND die
Bibliotheken `luma.oled`/`luma.core` installiert sind. Fehlt eines von
beidem oder schlaegt die Hardware-Initialisierung fehl (z.B. kein Panel
angeschlossen), loggt DisplayLoop eine Warnung und bleibt inaktiv - der
Rest des Probes laeuft unveraendert weiter.
"""

from __future__ import annotations

import fcntl
import logging
import socket
import struct
import threading
import time
from pathlib import Path

from probe_status import ProbeStatus, ScanStatus, SenderStatus, TestStatus

log = logging.getLogger("wlanmon_probe.display")

GPIO_SYSFS = Path("/sys/class/gpio")

SCREEN_STATUS = 0
SCREEN_SCAN = 1
SCREEN_TEST = 2
SCREEN_COUNT = 3

# Wie oft die Button-GPIOs abgefragt werden. Kein echtes Interrupt/select()
# auf den sysfs-Edge-Dateien, um das ohne Testhardware nicht fehleranfaellig
# zu implementieren - 50ms Poll-Intervall fuehlt sich fuer einen physischen
# Tastendruck weiterhin instantan an.
BUTTON_POLL_SECONDS = 0.05


class Button:
    """Ein einzelner Taster über das sysfs-GPIO-Interface (Polling statt
    Edge-Interrupt, siehe Modul-Docstring)."""

    def __init__(self, gpio: int, debounce_seconds: float = 0.25):
        self.gpio = gpio
        self._debounce = debounce_seconds
        self._last_value = 0
        self._last_trigger = 0.0
        self._value_path = GPIO_SYSFS / f"gpio{gpio}" / "value"

    def export(self) -> None:
        gpio_dir = GPIO_SYSFS / f"gpio{self.gpio}"
        if not gpio_dir.exists():
            (GPIO_SYSFS / "export").write_text(str(self.gpio))
            # Kurz warten, bis udev die sysfs-Dateien mit den richtigen
            # Rechten angelegt hat, bevor wir "direction" schreiben.
            time.sleep(0.1)
        (gpio_dir / "direction").write_text("in")
        try:
            self._last_value = int((gpio_dir / "value").read_text().strip())
        except OSError:
            self._last_value = 0

    def unexport(self) -> None:
        try:
            (GPIO_SYSFS / "unexport").write_text(str(self.gpio))
        except OSError:
            pass

    def poll_pressed(self) -> bool:
        """True, wenn seit dem letzten Aufruf eine (entprellte) steigende
        Flanke aufgetreten ist."""
        try:
            value = int(self._value_path.read_text().strip())
        except OSError:
            return False
        pressed = value == 1 and self._last_value == 0
        self._last_value = value
        if not pressed:
            return False
        now = time.monotonic()
        if now - self._last_trigger < self._debounce:
            return False
        self._last_trigger = now
        return True


class DisplayLoop(threading.Thread):
    def __init__(
        self,
        cfg: dict,
        status: ProbeStatus,
        stop_event: threading.Event,
        trigger_test_event: threading.Event,
    ):
        super().__init__(name="display-loop", daemon=True)
        self._cfg = cfg
        self._status = status
        self._stop_event = stop_event
        self._trigger_test_event = trigger_test_event
        self._screen = SCREEN_STATUS
        self._device = None
        self._buttons: list[Button] = []
        # Fest benanntes Management-Interface statt "IP über Default-
        # Route raten": waehrend ein Connection-Test auf dem WLAN-
        # Testinterface aktiv ist, bekommt das per DHCP oft eine eigene
        # Default-Route ins selbe Subnetz/Gateway wie eth0 (siehe
        # README, ARP-Flux) - die Default-Route-Abfrage liefert dann
        # unvorhersehbar mal die eth0-, mal die WLAN-Test-IP.
        self._management_interface = cfg.get("management_interface", "eth0")
        # Display nach so vielen Sekunden ohne Tastendruck abschalten (OLEDs
        # brennen bei dauerhaft statischem Bild ein); 0 = immer an. Jede
        # Taste weckt es wieder, siehe _poll_buttons().
        self._sleep_after = max(0, int(cfg.get("sleep_after_seconds", 120) or 0))
        self._asleep = False
        self._last_activity = time.monotonic()

    def run(self) -> None:
        if not self._init_hardware():
            return
        try:
            self._render()
            while not self._stop_event.is_set():
                redrawn = self._poll_buttons()
                # Auch im Schlaf weiter zeichnen (landet nur im Bildspeicher
                # des Panels) - nach dem Aufwecken ist der Inhalt sofort aktuell.
                if not redrawn and self._status.changed.is_set():
                    self._status.changed.clear()
                    self._render()
                if (
                    not self._asleep and self._sleep_after
                    and time.monotonic() - self._last_activity > self._sleep_after
                ):
                    self._set_asleep(True)
                time.sleep(BUTTON_POLL_SECONDS)
        finally:
            self._cleanup()

    def _set_asleep(self, asleep: bool) -> None:
        try:
            if asleep:
                self._device.hide()
            else:
                self._device.show()
        except Exception:
            log.warning("Display %s fehlgeschlagen", "abschalten" if asleep else "einschalten", exc_info=True)
            # Erst nach dem naechsten Ablauf erneut versuchen, statt im
            # 50-ms-Takt der Hauptschleife das Log zu fluten.
            self._last_activity = time.monotonic()
            return
        self._asleep = asleep
        log.debug("Display %s", "aus (Schlaf)" if asleep else "an")

    def _init_hardware(self) -> bool:
        try:
            from luma.core.interface.serial import i2c
            from luma.oled.device import ssd1306
        except ImportError:
            log.warning(
                "luma.oled/luma.core nicht installiert - Display bleibt "
                "deaktiviert (pip3 install luma.oled)."
            )
            return False

        i2c_port = self._cfg.get("i2c_port", 0)
        i2c_address = self._cfg.get("i2c_address", 0x3C)
        width = self._cfg.get("width", 128)
        height = self._cfg.get("height", 64)
        button_gpios = self._cfg.get("button_gpios", [0, 2, 3])

        try:
            serial = i2c(port=i2c_port, address=i2c_address)
            self._device = ssd1306(serial, width=width, height=height)
        except Exception:
            log.exception(
                "OLED-Initialisierung fehlgeschlagen (I2C-Bus %d, Adresse "
                "0x%02x) - Display bleibt deaktiviert. Laeuft evtl. noch "
                "fpms.service parallel (setup_wlanmon_probe.sh ausgefuehrt)?",
                i2c_port, i2c_address,
            )
            return False

        for gpio in button_gpios:
            button = Button(gpio)
            try:
                button.export()
            except OSError:
                log.exception(
                    "GPIO %d konnte nicht exportiert werden (evtl. noch von "
                    "fpms.service belegt)", gpio,
                )
                continue
            self._buttons.append(button)

        if len(self._buttons) < len(button_gpios):
            log.warning(
                "Nur %d von %d Buttons erfolgreich initialisiert - "
                "Navigation eingeschraenkt.", len(self._buttons), len(button_gpios),
            )
        return True

    def _poll_buttons(self) -> bool:
        # Alle Taster in jedem Durchlauf abfragen, damit keine Flanke
        # liegen bleibt und beim naechsten Durchlauf doppelt zaehlt.
        pressed = [button.poll_pressed() for button in self._buttons]
        if not any(pressed):
            return False
        self._last_activity = time.monotonic()
        if self._asleep:
            # Der Tastendruck weckt nur - sonst loeste z.B. Taste 3 beim
            # blossen Aufwecken einen Connection-Test aus.
            self._set_asleep(False)
            self._render()
            return True

        redrawn = False
        if len(pressed) > 0 and pressed[0]:
            self._screen = (self._screen - 1) % SCREEN_COUNT
            self._render()
            redrawn = True
        if len(pressed) > 1 and pressed[1]:
            self._screen = (self._screen + 1) % SCREEN_COUNT
            self._render()
            redrawn = True
        if len(pressed) > 2 and pressed[2]:
            log.info("Button 3 gedrueckt - loese sofortigen Connection-Test aus")
            self._trigger_test_event.set()
        return redrawn

    def _render(self) -> None:
        from luma.core.render import canvas

        scan, test, sender = self._status.snapshot()
        with canvas(self._device) as draw:
            if self._screen == SCREEN_STATUS:
                _draw_status(draw, self._status, sender, self._management_interface)
            elif self._screen == SCREEN_SCAN:
                _draw_scan(draw, scan)
            else:
                _draw_test(draw, test)

    def _cleanup(self) -> None:
        for button in self._buttons:
            button.unexport()


def _draw_lines(draw, lines: list[str]) -> None:
    for i, line in enumerate(lines):
        draw.text((0, i * 12), line, fill="white")


def _format_timestamp(timestamp: float) -> str:
    """Lokale Wanduhrzeit (Geraet laeuft in Europe/Berlin, per NTP synchron)."""
    return time.strftime("%d.%m. %H:%M:%S", time.localtime(timestamp))


def _age(timestamp: float) -> str:
    if timestamp == 0:
        return "nie"
    return _format_duration(int(time.time() - timestamp))


def _format_duration(seconds: int) -> str:
    if seconds < 60:
        return f"{seconds}s"
    if seconds < 3600:
        return f"{seconds // 60}m{seconds % 60:02d}s"
    hours = seconds // 3600
    minutes = (seconds % 3600) // 60
    return f"{hours}h{minutes:02d}m"


def _interface_ip(ifname: str) -> str:
    """
    IPv4-Adresse eines konkret benannten Interfaces per SIOCGIFADDR-ioctl -
    bewusst NICHT über die Default-Route geraten (z.B. per UDP-"connect"-
    Trick): waehrend ein Connection-Test auf dem WLAN-Testinterface aktiv
    ist, bekommt das per DHCP oft eine eigene Default-Route ins selbe
    Subnetz/Gateway wie eth0 (siehe README, ARP-Flux) - die
    Default-Route-Abfrage liefert dann unvorhersehbar mal die eth0-, mal
    die WLAN-Test-IP. Die ioctl-Abfrage ist dagegen unabhängig vom
    Routing immer die Adresse des angegebenen Interfaces.
    """
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        try:
            packed = struct.pack("256s", ifname[:15].encode())
            raw = fcntl.ioctl(s.fileno(), 0x8915, packed)  # SIOCGIFADDR
            return socket.inet_ntoa(raw[20:24])
        except OSError:
            return "-"


def _draw_status(
    draw, status: ProbeStatus, sender: SenderStatus, management_interface: str
) -> None:
    lines = [
        status.device_id,
        f"Site: {status.site or '-'}",
        f"IP: {_interface_ip(management_interface)}",
        f"Up: {_format_duration(int(status.uptime_seconds()))}",
        f"Server: vor {_age(sender.last_success_at)}",
    ]
    _draw_lines(draw, lines)


def _draw_scan(draw, scan: ScanStatus) -> None:
    if scan.timestamp == 0:
        _draw_lines(draw, ["Letzter Scan", "noch keiner"])
        return
    lines = [
        "Letzter Scan",
        f"vor {_age(scan.timestamp)}",
        f"{scan.network_count} Netze",
    ]
    _draw_lines(draw, lines)


def _draw_test(draw, test: TestStatus) -> None:
    if test.timestamp == 0:
        _draw_lines(draw, ["Letzter Test", "noch keiner"])
        return
    lines = [f"Test: {test.ssid}"[:21], f"Status: {'OK' if test.connected else 'FEHLER'}"]
    if test.connected:
        if test.ping_rtt_avg_ms is not None:
            lines.append(
                f"Ping: {test.ping_rtt_avg_ms:.1f}ms ({test.ping_received}/{test.ping_sent})"
            )
        else:
            lines.append(f"Ping: 0/{test.ping_sent} verloren")
        if test.iperf3_mbps is not None:
            lines.append(f"iperf3: {test.iperf3_mbps:.0f} Mbit/s")
    else:
        lines.append((test.error or "")[:21])
    lines.append(_format_timestamp(test.timestamp))
    _draw_lines(draw, lines)
