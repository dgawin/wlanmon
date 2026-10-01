"""
HTTP(S)-Hilfen, die ueber genau ein Netzwerk-Interface laufen.

Per SO_BINDTODEVICE (wie `ping -I`) gebunden: ohne das ginge eine Anfrage bei
parallel aktivem eth0 ueber das Management-Netz und wuerde das Captive Portal
des WLANs gar nicht sehen. Genutzt vom Portal-Check (wifi_ops.py) und von den
Login-Modulen unter portals/.
"""

from __future__ import annotations

import http.client
import socket
import ssl
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit


def connect_bound(host: str, port: int, interface: str, timeout: float) -> socket.socket:
    """TCP-Verbindung ueber `interface` (Namen werden per DNS aufgeloest)."""
    last_exc: OSError | None = None
    for family, socktype, proto, _canon, addr in socket.getaddrinfo(
        host, port, socket.AF_INET, socket.SOCK_STREAM
    ):
        sock = socket.socket(family, socktype, proto)
        try:
            sock.setsockopt(
                socket.SOL_SOCKET, socket.SO_BINDTODEVICE,
                interface.encode() + b"\0",
            )
            sock.settimeout(timeout)
            sock.connect(addr)
        except OSError as exc:
            sock.close()
            last_exc = exc
            continue
        return sock
    raise last_exc or OSError("keine Adresse aufloesbar")


class BoundHTTPConnection(http.client.HTTPConnection):
    """HTTP-Verbindung ueber ein festes Interface, siehe connect_bound()."""

    def __init__(self, host: str, port: int, interface: str, timeout: float):
        super().__init__(host, port, timeout=timeout)
        self._interface = interface

    def connect(self) -> None:
        self.sock = connect_bound(self.host, self.port, self._interface, self.timeout)


class BoundHTTPSConnection(http.client.HTTPSConnection):
    """HTTPS-Verbindung ueber ein festes Interface. Das Zertifikat wird
    gegen den System-CA-Speicher geprueft (verify=False schaltet das ab)."""

    def __init__(
        self, host: str, port: int, interface: str, timeout: float, verify: bool = True
    ):
        context = ssl.create_default_context()
        if not verify:
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
        super().__init__(host, port, timeout=timeout, context=context)
        self._interface = interface

    def connect(self) -> None:
        sock = connect_bound(self.host, self.port, self._interface, self.timeout)
        self.sock = self._context.wrap_socket(sock, server_hostname=self.host)


@dataclass
class HttpResponse:
    status: int
    body: bytes                       # bis 64 KB
    location: str | None              # Location-Header (Redirect-Ziel)
    headers: list[tuple[str, str]]    # alle Header, Set-Cookie ggf. mehrfach


def bound_request_full(
    interface: str,
    scheme: str,
    host: str,
    port: int,
    path: str,
    method: str = "GET",
    body: bytes | None = None,
    headers: dict[str, str] | None = None,
    timeout: float = 10.0,
    verify: bool = True,
) -> HttpResponse:
    """Eine HTTP(S)-Anfrage ueber `interface`, ohne Redirects zu folgen."""
    if scheme == "https":
        conn: http.client.HTTPConnection = BoundHTTPSConnection(
            host, port, interface, timeout, verify
        )
    else:
        conn = BoundHTTPConnection(host, port, interface, timeout)
    try:
        conn.request(method, path, body=body, headers=headers or {})
        resp = conn.getresponse()
        return HttpResponse(
            status=resp.status,
            body=resp.read(65536),
            location=resp.getheader("Location"),
            headers=resp.getheaders(),
        )
    finally:
        conn.close()


def bound_request(
    interface: str,
    scheme: str,
    host: str,
    port: int,
    path: str,
    method: str = "GET",
    body: bytes | None = None,
    headers: dict[str, str] | None = None,
    timeout: float = 10.0,
    verify: bool = True,
) -> tuple[int, bytes, str | None]:
    """Wie bound_request_full(), Rueckgabe (Status, Body, Location)."""
    r = bound_request_full(
        interface, scheme, host, port, path, method, body, headers, timeout, verify
    )
    return r.status, r.body, r.location


def visit_url(
    interface: str, url: str, timeout: float = 10.0, max_hops: int = 5
) -> tuple[int | None, str | None]:
    """Ruft eine URL ueber das Interface auf und folgt Redirects (wie ein
    Browser nach dem Portal-Login). Das Zertifikat wird hier nicht geprueft:
    Ziel ist oft der AP/Controller mit selbstsigniertem Zertifikat, und
    uebertragen wird nur die vom Portal gelieferte URL. Rueckgabe
    (letzter Status, Fehlertext oder None)."""
    status: int | None = None
    for _ in range(max_hops):
        parts = urlsplit(url)
        if parts.scheme not in ("http", "https") or not parts.hostname:
            return status, "ungueltige Weiterleitungs-URL"
        port = parts.port or (443 if parts.scheme == "https" else 80)
        path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
        try:
            status, _body, location = bound_request(
                interface, parts.scheme, parts.hostname, port, path,
                headers={"User-Agent": "wlanmon-probe/captive-login"},
                timeout=timeout, verify=False,
            )
        except (OSError, http.client.HTTPException) as exc:
            return status, f"{type(exc).__name__}: {exc}"[:200]
        if 300 <= status < 400 and location:
            url = urljoin(url, location)
            continue
        return status, None
    return status, "zu viele Weiterleitungen"
