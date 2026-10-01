"""
Generisches Formular-Portal (klassische Captive Portale, z.B. pfSense,
CoovaChilli/Hotspot-Systeme, einfache Click-through-Seiten).

Ablauf: Login-Seite laden (Redirects und Cookies werden verfolgt), das
HTML-Formular auslesen, Felder ausfuellen und absenden:
  - hidden-Felder werden unveraendert mitgeschickt,
  - Passwortfeld = Passwort, Feld mit voucher/code/token im Namen = Passwort
    (Access-Code), Feld mit user/login/name/email = Benutzername,
  - Checkbox mit accept/agree/terms/... im Namen wird angehakt,
  - der Submit-Button ("Continue", "Anmelden", "Accept" ...) wird mitgeschickt.
Ohne Benutzer- und Passwortfeld (reiner Klick auf "Akzeptieren") sind keine
Zugangsdaten noetig. Ob es geklappt hat, prueft der Aufrufer danach mit der
Detection-URL.

Grenzen: Portale, deren Formular erst per JavaScript entsteht (z.B. Cirrus),
brauchen ein eigenes Modul. Bei IP-Adressen als Ziel (Gateway) wird das
TLS-Zertifikat nicht geprueft - solche Portale haben fast immer ein
selbstsigniertes Zertifikat. Eine Abmeldung gibt es nicht (das Portal wirft
die Sitzung selbst per Timeout ab).
"""

from __future__ import annotations

import http.client
import ipaddress
import logging
import re
import ssl
import time
from html.parser import HTMLParser
from urllib.parse import urlencode, urljoin, urlsplit

from bound_http import HttpResponse, bound_request_full

from .base import PortalContext, PortalModule

log = logging.getLogger("wlanmon_probe.portals.form")

_CODE_RE = re.compile(r"voucher|code|token|ticket", re.I)
_USER_RE = re.compile(r"user|login|name|email|account|ident", re.I)
_TERMS_RE = re.compile(r"accept|agree|terms|tos|condition|policy|consent|nutzung|zustimm", re.I)
_SUBMIT_RE = re.compile(
    r"continue|login|log in|sign in|anmeld|weiter|accept|akzept|connect|verbind|submit|senden",
    re.I,
)
_TEXT_TYPES = ("", "text", "email", "tel", "search", "number", "url")


class _FormParser(HTMLParser):
    """Sammelt alle <form>-Elemente mit ihren Feldern."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.forms: list[dict] = []
        self._form: dict | None = None
        self._select: dict | None = None
        self._button: dict | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        a = {k.lower(): (v if v is not None else "") for k, v in attrs}
        if tag == "form":
            self._form = {
                "action": a.get("action", ""),
                "method": a.get("method", "get").lower(),
                "fields": [],
            }
            self.forms.append(self._form)
            return
        if self._form is None:
            return
        if tag == "input":
            kind = a.get("type", "text").lower()
            # Manche Portale (u.a. pfSense-Vorlagen) verstecken Felder nur
            # per CSS-Klasse "hidden" statt mit type="hidden" - oft
            # Spam-Honeypots (ein echter Browser sieht/befuellt sie nie).
            # Als echtes hidden-Feld behandeln: Wert unveraendert mitschicken,
            # nie als Pflichtfeld fuer Benutzername/Passwort werten.
            css_hidden = "hidden" in a.get("class", "").lower().split()
            if css_hidden and kind not in ("hidden", "submit", "checkbox", "radio", "button"):
                kind = "hidden"
            self._form["fields"].append({
                "type": kind, "name": a.get("name", ""),
                "value": a.get("value", ""), "checked": "checked" in a, "label": "",
                "css_hidden": css_hidden,
            })
        elif tag == "button":
            self._button = {
                "type": a.get("type", "submit").lower(), "name": a.get("name", ""),
                "value": a.get("value", ""), "checked": False, "label": "",
            }
            self._form["fields"].append(self._button)
        elif tag == "select":
            self._select = {
                "type": "select", "name": a.get("name", ""), "value": None,
                "first": None, "checked": False, "label": "",
            }
            self._form["fields"].append(self._select)
        elif tag == "option" and self._select is not None:
            value = a.get("value", "")
            if self._select["first"] is None:
                self._select["first"] = value
            if "selected" in a:
                self._select["value"] = value

    def handle_data(self, data: str) -> None:
        if self._button is not None:
            self._button["label"] += data.strip()

    def handle_endtag(self, tag: str) -> None:
        if tag == "form":
            self._form = None
        elif tag == "button":
            self._button = None
        elif tag == "select" and self._select is not None:
            if self._select["value"] is None:
                self._select["value"] = self._select["first"] or ""
            self._select = None


def _pick_form(forms: list[dict]) -> dict | None:
    """Das Login-Formular: bevorzugt eines mit Passwortfeld, sonst das erste
    mit Submit-Button, sonst das erste."""
    for form in forms:
        if any(f["type"] == "password" for f in form["fields"]):
            return form
    for form in forms:
        if any(f["type"] == "submit" for f in form["fields"]):
            return form
    return forms[0] if forms else None


def _build_form_data(
    form: dict, username: str, password: str
) -> tuple[list[tuple[str, str]], str | None]:
    """Formularfelder befuellen. Rueckgabe (Daten, Problemtext oder None)."""
    data: list[tuple[str, str]] = []
    needs_user = needs_pass = False
    for f in form["fields"]:
        name, kind = f["name"], f["type"]
        if not name:
            continue
        if kind == "hidden":
            data.append((name, f["value"]))
        elif kind == "password":
            needs_pass = True
            data.append((name, password))
        elif kind in _TEXT_TYPES:
            if _CODE_RE.search(name):
                needs_pass = True
                data.append((name, password))
            elif _USER_RE.search(name):
                needs_user = True
                data.append((name, username))
            else:
                data.append((name, f["value"]))
        elif kind == "checkbox":
            if _TERMS_RE.search(name) or f["checked"]:
                data.append((name, f["value"] or "on"))
        elif kind == "radio":
            if f["checked"]:
                data.append((name, f["value"]))
        elif kind == "select":
            data.append((name, f["value"] or ""))
    if needs_user and not username:
        return data, "Formular verlangt einen Benutzernamen (nicht konfiguriert)"
    if needs_pass and not password:
        return data, "Formular verlangt ein Passwort bzw. einen Code (nicht konfiguriert)"

    submits = [f for f in form["fields"] if f["type"] == "submit"]
    if submits:
        chosen = next(
            (s for s in submits if _SUBMIT_RE.search(s["value"] or s["label"])), submits[0]
        )
        if chosen["name"]:
            data.append((chosen["name"], chosen["value"]))
    return data, None


def _is_ip(host: str) -> bool:
    try:
        ipaddress.ip_address(host)
        return True
    except ValueError:
        return False


def _store_cookies(jar: dict[str, str], headers: list[tuple[str, str]]) -> None:
    for name, value in headers:
        if name.lower() == "set-cookie":
            pair = value.split(";", 1)[0]
            if "=" in pair:
                key, val = pair.split("=", 1)
                jar[key.strip()] = val.strip()


class FormPortal(PortalModule):
    name = "form"
    label = "Formular-Login (generisch, z.B. pfSense)"

    def detect(self, redirect_url: str | None) -> bool:
        # Letzter Ausweg der Auto-Erkennung: jedes Portal mit gueltiger URL.
        # Spezielle Module stehen in portals/__init__.py davor.
        if not redirect_url:
            return False
        parts = urlsplit(redirect_url)
        return parts.scheme in ("http", "https") and bool(parts.hostname)

    def _fetch(
        self,
        ctx: PortalContext,
        url: str,
        jar: dict[str, str],
        method: str = "GET",
        body: bytes | None = None,
        referer: str | None = None,
        only_host: str | None = None,
        max_hops: int = 5,
    ) -> tuple[HttpResponse, str]:
        """Eine Seite laden und Redirects folgen (Cookies werden gesammelt).
        Mit only_host wird nur innerhalb dieses Hosts weitergeleitet."""
        for _ in range(max_hops + 1):
            parts = urlsplit(url)
            scheme, host = parts.scheme, parts.hostname or ""
            port = parts.port or (443 if scheme == "https" else 80)
            path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
            headers = {
                "User-Agent": "wlanmon-probe/captive-login",
                "Accept": "text/html,application/xhtml+xml,*/*;q=0.8",
            }
            if jar:
                headers["Cookie"] = "; ".join(f"{k}={v}" for k, v in jar.items())
            if referer:
                headers["Referer"] = referer
            if method == "POST":
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            resp = bound_request_full(
                ctx.interface, scheme, host, port, path, method=method, body=body,
                headers=headers, timeout=ctx.timeout, verify=not _is_ip(host),
            )
            _store_cookies(jar, resp.headers)
            if 300 <= resp.status < 400 and resp.location:
                target = urljoin(url, resp.location)
                if only_host and urlsplit(target).hostname != only_host:
                    return resp, url
                referer, url, method, body = url, target, "GET", None
                continue
            return resp, url
        return resp, url

    def login(self, ctx: PortalContext, cfg: dict) -> tuple[dict, dict | None]:
        out: dict = {
            "attempted": True, "ok": None, "login_by": "form",
            "http_status": None, "seconds": None, "error": None,
        }
        try:
            return self._do_login(ctx, cfg, out)
        finally:
            # In einem finally statt nach dem try/except: ein "return" aus
            # dem try (z.B. "kein Formular gefunden") wuerde sonst diese
            # Log-Zeile ueberspringen - hier laeuft sie immer.
            log.info(
                "Portal-Login (form): HTTP %s formularfelder=%s gesendet=%s error=%s",
                out["http_status"], out.get("form_fields"), out.get("fields"), out["error"],
            )

    def _do_login(self, ctx: PortalContext, cfg: dict, out: dict) -> tuple[dict, dict | None]:
        try:
            t0 = time.monotonic()
            jar: dict[str, str] = {}
            page, page_url = self._fetch(ctx, ctx.redirect_url, jar)
            if page.status != 200:
                out["error"] = f"Login-Seite nicht lesbar (HTTP {page.status})"
                return out, None
            parser = _FormParser()
            parser.feed(page.body.decode("utf-8", errors="replace"))
            form = _pick_form(parser.forms)
            if form is None:
                out["error"] = (
                    "kein Formular auf der Portal-Seite gefunden "
                    "(JavaScript-Portal? dann ist ein eigenes Modul noetig)"
                )
                return out, None

            # Diagnose: Name:Typ aller Felder des gewaehlten Formulars - nie
            # Werte (kein Passwort, kein Code). Hilft zu sehen, welches Feld
            # die Benutzername-/Passwort-Heuristik unten ausgeloest hat.
            out["form_fields"] = [
                f"{f['name'] or '(ohne Namen)'}:{f['type']}"
                f"{'(css-hidden)' if f.get('css_hidden') else ''}"
                for f in form["fields"]
            ]
            data, problem = _build_form_data(
                form, str(cfg.get("username") or ""), str(cfg.get("password") or "")
            )
            out["fields"] = [name for name, _ in data]  # nur Namen, keine Werte
            if problem:
                out["error"] = problem
                return out, None

            action = urljoin(page_url, form["action"] or "")
            action_host = urlsplit(action).hostname
            encoded = urlencode(data)
            if form["method"] == "post":
                resp, _ = self._fetch(
                    ctx, action, jar, method="POST", body=encoded.encode("utf-8"),
                    referer=page_url, only_host=action_host,
                )
            else:
                sep = "&" if "?" in action else "?"
                resp, _ = self._fetch(
                    ctx, f"{action}{sep}{encoded}", jar, referer=page_url,
                    only_host=action_host,
                )
            out["http_status"] = resp.status
            if resp.status >= 400:
                out["error"] = f"Portal lehnte das Formular ab (HTTP {resp.status})"
            out["seconds"] = round(time.monotonic() - t0, 2)
        except (OSError, http.client.HTTPException, ssl.SSLError) as exc:
            out["error"] = f"{type(exc).__name__}: {exc}"[:200]
        except Exception as exc:  # noqa: BLE001 - reine Diagnose
            log.debug("Formular-Login fehlgeschlagen", exc_info=True)
            out["error"] = f"{type(exc).__name__}: {exc}"[:200]
        return out, None
