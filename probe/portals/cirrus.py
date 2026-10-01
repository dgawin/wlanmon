"""
Alcatel-Lucent OmniVista Cirrus - Guest-/BYOD-Portal
(https://<host>/portalpages/<id>/login.html?mac=...&url=...).

Ablauf wie die Login-Seite selbst:
  1. portal-param.js neben login.html lesen (loginBy, strategyInfo, useFor,
     tenantId),
  2. JSON per POST an /portal/api/ham/login/auth (mit mac/url aus dem
     Redirect). Wie bei der Seite sind die Header Tenant-Id und language
     Pflicht; Fehler kommen als HTTP 200 mit errorCode != 0 im JSON zurueck.
  3. Abmelden: POST /portal/api/ham/logoff/do.

Unterstuetzte Login-Arten (loginBy im Portal):
  account                    Benutzername + Passwort
  accessCode                 Code (im Feld "password" der Config)
  termsOfUse / simplePersona nur Nutzungsbedingungen akzeptieren - ohne
                             Benutzername und Passwort
"""

from __future__ import annotations

import http.client
import json
import logging
import re
import ssl
import time
from urllib.parse import parse_qs, urlsplit

from bound_http import bound_request, visit_url

from .base import PortalContext, PortalModule

log = logging.getLogger("wlanmon_probe.portals.cirrus")

_PORTAL_PARAM_RE = re.compile(r"^\s*(\w+)\s*:\s*'([^']*)'\s*,?\s*$", re.MULTILINE)


def _parse_portal_param(js_text: str) -> dict[str, str]:
    """Liest die einfachen Text-Werte (key: 'value') aus portal-param.js."""
    return {m.group(1): m.group(2) for m in _PORTAL_PARAM_RE.finditer(js_text)}


def _redirect_from_login_body(body: bytes) -> str | None:
    """Weiterleitungs-URL aus der Login-Antwort (JSON-Feld data o.ae.)."""
    text = body.decode("utf-8", errors="replace").strip()
    try:
        data = json.loads(text)
    except ValueError:
        data = text
    if isinstance(data, dict):
        for key in ("data", "redirect", "redirectUrl", "url"):
            value = data.get(key)
            if isinstance(value, str) and value.startswith(("http://", "https://")):
                return value
        return None
    if isinstance(data, str) and data.startswith(("http://", "https://")):
        return data
    return None


def _json_error(status: int, body: bytes) -> str | None:
    """Fehlertext aus einer API-Antwort oder None bei Erfolg. Das Portal
    meldet Fehler mit HTTP 200 und errorCode != 0; nur 0 ist Erfolg."""
    if not 200 <= status < 300:
        return f"Portal lehnte ab (HTTP {status})"
    try:
        reply = json.loads(body.decode("utf-8", errors="replace"))
    except ValueError:
        return None
    if isinstance(reply, dict) and reply.get("errorCode") not in (None, 0):
        return f"Portal: {reply.get('errorMessage') or reply.get('errorCode')}"[:200]
    return None


class CirrusPortal(PortalModule):
    name = "cirrus"
    label = "Alcatel-Lucent OmniVista Cirrus"

    def detect(self, redirect_url: str | None) -> bool:
        if not redirect_url:
            return False
        parts = urlsplit(redirect_url)
        host = (parts.hostname or "").lower()
        return host.endswith("ovcirrus.com") or "/portalpages/" in parts.path

    @staticmethod
    def _headers(origin: str, referer: str, tenant_id: str | None) -> dict[str, str]:
        # Tenant-Id und language sendet auch die Portal-Seite bei jedem API-
        # Aufruf; ohne Tenant-Id kommt "portalParametersIllegal".
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/plain, */*",
            "Origin": origin,
            "Referer": referer,
            "User-Agent": "wlanmon-probe/captive-login",
            "language": "en",
        }
        if tenant_id:
            headers["Tenant-Id"] = tenant_id
        return headers

    def login(self, ctx: PortalContext, cfg: dict) -> tuple[dict, dict | None]:
        out: dict = {
            "attempted": True, "ok": None, "login_by": None,
            "http_status": None, "seconds": None, "error": None,
        }
        try:
            return self._do_login(ctx, cfg, out)
        finally:
            # In einem finally statt nach dem try/except: ein "return" aus
            # dem try (die frueheste Fehlerbedingung reicht) wuerde sonst
            # eine Log-Zeile danach ueberspringen - hier laeuft sie immer.
            log.info(
                "Portal-Login (%s): HTTP %s error=%s",
                out["login_by"], out["http_status"], out["error"],
            )

    def _do_login(
        self, ctx: PortalContext, cfg: dict, out: dict
    ) -> tuple[dict, dict | None]:
        session: dict | None = None
        try:
            parts = urlsplit(ctx.redirect_url)
            if parts.scheme not in ("http", "https") or not parts.hostname:
                out["error"] = "Redirect-Ziel ist keine gueltige URL"
                return out, None
            scheme, host = parts.scheme, parts.hostname
            port = parts.port or (443 if scheme == "https" else 80)
            origin = f"{scheme}://{parts.netloc}"
            base_path = parts.path.rsplit("/", 1)[0]  # z.B. /portalpages/<id>
            query = parse_qs(parts.query)
            t0 = time.monotonic()

            status, body, _loc = bound_request(
                ctx.interface, scheme, host, port,
                f"{base_path}/portal-param.js", timeout=ctx.timeout,
            )
            if status != 200:
                out["error"] = f"portal-param.js nicht lesbar (HTTP {status})"
                return out, None
            params = _parse_portal_param(body.decode("utf-8", errors="replace"))
            login_by = params.get("loginBy", "")
            out["login_by"] = login_by

            username = str(cfg.get("username") or "")
            password = str(cfg.get("password") or "")
            extra: dict = {}
            if login_by == "account":
                if not username or not password:
                    out["error"] = "Portal verlangt Benutzername und Passwort (nicht konfiguriert)"
                    return out, None
            elif login_by == "accessCode":
                if not password:
                    out["error"] = "Portal verlangt einen Access-Code (als Passwort eintragen)"
                    return out, None
                username = password
            elif login_by in ("termsOfUse", "simplePersona"):
                # Nur Nutzungsbedingungen: die Seite sendet den festen
                # Benutzer "BuiltInAccount" und eine leere Attributliste.
                username, password = "BuiltInAccount", ""
                extra["loginCustomAttrList"] = []
            else:
                out["error"] = f"Portal-Login-Art '{login_by or '?'}' wird nicht unterstuetzt"
                return out, None

            mac = (query.get("mac") or [None])[0]
            payload = {
                "username": username,
                "password": password or None,
                "url": (query.get("url") or [None])[0],
                "mac": mac,
                "strategyName": params.get("strategyInfo"),
                "useFor": params.get("useFor"),
                **extra,
            }
            payload = {k: v for k, v in payload.items() if v is not None}
            headers = self._headers(origin, ctx.redirect_url, params.get("tenantId"))
            status, body, _loc = bound_request(
                ctx.interface, scheme, host, port, "/portal/api/ham/login/auth",
                method="POST", body=json.dumps(payload).encode("utf-8"),
                headers=headers, timeout=ctx.timeout,
            )
            out["http_status"] = status
            snippet = body[:200].decode("utf-8", errors="replace").strip()
            out["response"] = snippet
            log.info("Portal-Login-Antwort HTTP %s: %s", status, snippet)

            error = _json_error(status, body)
            if error:
                out["error"] = error
            else:
                session = {
                    "scheme": scheme, "host": host, "port": port,
                    "origin": origin, "referer": ctx.redirect_url,
                    "tenant_id": params.get("tenantId"),
                    "username": username, "mac": mac,
                    "use_for": params.get("useFor"),
                }
                # Enthaelt die Antwort eine Weiterleitungs-URL, ruft der
                # Browser sie als Naechstes auf (darueber schaltet u.U. der
                # AP frei). Bei dir kommt "data": null - dann entfaellt das.
                target = _redirect_from_login_body(body)
                if target:
                    follow_status, follow_error = visit_url(ctx.interface, target, ctx.timeout)
                    out["followed"] = urlsplit(target).hostname
                    out["follow_status"] = follow_status
                    if follow_error:
                        out["follow_error"] = follow_error
                    log.info("Portal-Login: Weiterleitung an %s -> HTTP %s %s",
                             out["followed"], follow_status, follow_error or "")
            out["seconds"] = round(time.monotonic() - t0, 2)
        except (OSError, http.client.HTTPException, ssl.SSLError) as exc:
            out["error"] = f"{type(exc).__name__}: {exc}"[:200]
        except Exception as exc:  # noqa: BLE001 - reine Diagnose
            log.debug("Portal-Login fehlgeschlagen", exc_info=True)
            out["error"] = f"{type(exc).__name__}: {exc}"[:200]
        return out, session

    def logoff(self, ctx: PortalContext, session: dict) -> dict:
        out: dict = {"attempted": True, "ok": False, "http_status": None, "error": None}
        try:
            payload = {
                "username": session.get("username"),
                "userMac": session.get("mac"),
                "useFor": session.get("use_for"),
            }
            payload = {k: v for k, v in payload.items() if v is not None}
            status, body, _loc = bound_request(
                ctx.interface, session["scheme"], session["host"], session["port"],
                "/portal/api/ham/logoff/do", method="POST",
                body=json.dumps(payload).encode("utf-8"),
                headers=self._headers(
                    session["origin"], session["referer"], session.get("tenant_id")
                ),
                timeout=min(ctx.timeout, 8.0),
            )
            out["http_status"] = status
            error = _json_error(status, body)
            if error:
                out["error"] = error
            else:
                out["ok"] = True
        except (OSError, http.client.HTTPException, ssl.SSLError) as exc:
            out["error"] = f"{type(exc).__name__}: {exc}"[:200]
        except Exception as exc:  # noqa: BLE001 - reine Diagnose
            log.debug("Portal-Logoff fehlgeschlagen", exc_info=True)
            out["error"] = f"{type(exc).__name__}: {exc}"[:200]
        log.info("Portal-Logoff: ok=%s HTTP %s error=%s",
                 out["ok"], out["http_status"], out["error"])
        return out
