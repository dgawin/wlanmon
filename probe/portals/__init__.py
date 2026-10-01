"""
Captive-Portal-Login-Module.

Jedes Modul kennt einen Portaltyp (siehe base.PortalModule). Ausgewaehlt wird
per Config (`captive_portal_login.type`, z.B. "cirrus") oder, bei "auto",
ueber detect() anhand der Redirect-URL des Portals.
"""

from __future__ import annotations

from .base import PortalContext, PortalModule
from .cirrus import CirrusPortal
from .form import FormPortal

# Reihenfolge = Reihenfolge der Auto-Erkennung. Neue Module hier eintragen,
# spezielle vor dem generischen FormPortal (das passt auf jedes Portal und
# steht deshalb als letzter Ausweg am Ende).
MODULES: dict[str, PortalModule] = {
    m.name: m for m in (CirrusPortal(), FormPortal())
}


def portal_types() -> list[tuple[str, str]]:
    """(Kennung, Anzeigename) aller Module - fuer Dashboard/Doku."""
    return [("auto", "Automatisch erkennen")] + [(m.name, m.label) for m in MODULES.values()]


def select_module(portal_type: str | None, redirect_url: str | None) -> PortalModule | None:
    """Modul fuer ein Portal: festgelegter Typ oder Auto-Erkennung."""
    kind = (portal_type or "auto").strip().lower()
    if kind != "auto":
        return MODULES.get(kind)
    for module in MODULES.values():
        if module.detect(redirect_url):
            return module
    return None


__all__ = ["MODULES", "PortalContext", "PortalModule", "portal_types", "select_module"]
