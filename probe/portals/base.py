"""Gemeinsame Schnittstelle der Captive-Portal-Login-Module."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass
class PortalContext:
    """Was ein Modul fuer seine Anfragen braucht."""

    interface: str          # WLAN-Interface, ueber das alles laufen muss
    redirect_url: str       # Ziel des Portal-Redirects (enthaelt meist mac= und url=)
    timeout: float = 15.0   # Timeout je HTTP-Anfrage in Sekunden


class PortalModule:
    """
    Ein Login-Modul kennt genau einen Portaltyp.

    Neue Portale: Unterklasse in einer eigenen Datei unter portals/ anlegen,
    `name`/`label` setzen, detect() und login() (optional logoff())
    implementieren und die Klasse in portals/__init__.py eintragen. Module
    duerfen nie eine Exception werfen, sondern melden Fehler im
    Ergebnis-Dict (Schluessel "error").

    login() liefert (ergebnis, sitzung):
      ergebnis: {attempted, ok, error, ...} - geht so ins Dashboard; kein
        Passwort und keine Token hineinschreiben. "ok" setzt der Aufrufer
        nach der Nachpruefung der Detection-URL.
      sitzung: undurchsichtiges Dict, das nur logoff() bekommt (oder None).
    """

    name = ""    # Kennung in der Config, z.B. "cirrus"
    label = ""   # Anzeigename im Dashboard

    def detect(self, redirect_url: str | None) -> bool:
        """True, wenn dieses Modul zum Portal hinter der Redirect-URL passt."""
        return False

    def login(self, ctx: PortalContext, cfg: dict) -> tuple[dict, dict | None]:
        raise NotImplementedError

    def logoff(self, ctx: PortalContext, session: dict) -> dict:
        """Meldet die Sitzung wieder ab. Standard: nicht unterstuetzt."""
        return {"attempted": False, "ok": None, "error": "Abmeldung nicht unterstuetzt"}
