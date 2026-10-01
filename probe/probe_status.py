"""
Thread-sicherer, gemeinsamer Status-Speicher fuer das OLED-Display.

Scan-Loop, Connection-Test-Loop und Sender schreiben hier nach jedem
Zyklus ihren jeweils letzten Zustand rein; DisplayLoop liest ihn zum
Rendern. Das `changed`-Event signalisiert Aenderungen, damit DisplayLoop
ereignisgesteuert statt in festem Rhythmus neu zeichnet - ohne eigene
Kenntnis der Scan-/Test-Intervalle.
"""

from __future__ import annotations

import threading
import time
from dataclasses import dataclass


@dataclass
class ScanStatus:
    timestamp: float = 0.0
    network_count: int = 0


@dataclass
class TestStatus:
    timestamp: float = 0.0
    ssid: str = ""
    connected: bool = False
    assoc_seconds: float | None = None
    dhcp_seconds: float | None = None
    ping_sent: int = 0
    ping_received: int = 0
    ping_rtt_avg_ms: float | None = None
    iperf3_mbps: float | None = None
    error: str | None = None


@dataclass
class SenderStatus:
    last_success_at: float = 0.0
    last_error_at: float = 0.0
    last_error: str = ""


class ProbeStatus:
    def __init__(self, device_id: str, site: str) -> None:
        self.device_id = device_id
        self.site = site
        self.started_at = time.monotonic()
        self.changed = threading.Event()
        self._lock = threading.Lock()
        self._scan = ScanStatus()
        self._test = TestStatus()
        self._sender = SenderStatus()

    def update_scan(self, network_count: int) -> None:
        with self._lock:
            self._scan = ScanStatus(timestamp=time.time(), network_count=network_count)
        self.changed.set()

    def update_test(
        self,
        ssid: str,
        connected: bool,
        assoc_seconds: float | None,
        dhcp_seconds: float | None,
        ping_sent: int,
        ping_received: int,
        ping_rtt_avg_ms: float | None,
        iperf3_mbps: float | None,
        error: str | None,
    ) -> None:
        with self._lock:
            self._test = TestStatus(
                timestamp=time.time(),
                ssid=ssid,
                connected=connected,
                assoc_seconds=assoc_seconds,
                dhcp_seconds=dhcp_seconds,
                ping_sent=ping_sent,
                ping_received=ping_received,
                ping_rtt_avg_ms=ping_rtt_avg_ms,
                iperf3_mbps=iperf3_mbps,
                error=error,
            )
        self.changed.set()

    def update_sender_success(self) -> None:
        with self._lock:
            self._sender.last_success_at = time.time()
        self.changed.set()

    def update_sender_error(self, message: str) -> None:
        with self._lock:
            self._sender.last_error_at = time.time()
            self._sender.last_error = message
        self.changed.set()

    def snapshot(self) -> tuple[ScanStatus, TestStatus, SenderStatus]:
        with self._lock:
            return self._scan, self._test, self._sender

    def uptime_seconds(self) -> float:
        return time.monotonic() - self.started_at
