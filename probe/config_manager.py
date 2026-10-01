"""
Konfigurationsverwaltung mit optionaler zentraler Verwaltung.

Es gibt zwei Ebenen:
  - Bootstrap-Config (lokal, in config.yaml): alles, was nötig ist, um
    überhaupt mit dem Server sprechen zu können (device.id,
    interface.name, server.*, queue.*, logging.*). Diese Werte werden
    NIE vom Server überschrieben.
  - Remote-Config (optional, vom Server gezogen): scan.*,
    connection_tests.* und der Standortname (site) – also alles, was sich im laufenden Betrieb
    zentral ändern lassen soll (Intervalle, Ziel-SSIDs, Ping-Ziel...).
    Wird lokal gecacht, damit der Client auch bei Serverausfall mit der
    zuletzt bekannten Konfiguration weiterläuft.

Bei einer Änderung der Remote-Config beendet sich der Prozess sauber
(os._exit), damit systemd (Restart=always) ihn mit frischer, vollständig
neu geladener Konfiguration neu startet. Das ist deutlich robuster als
Threads/Loops zur Laufzeit umzukonfigurieren.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import threading
import time
from pathlib import Path

import requests
import yaml

log = logging.getLogger("wlanmon_probe.config_manager")

# Diese Top-Level-Keys dürfen vom Server zentral gesteuert werden.
# "site" ist nur der Standortname zur Anzeige (Log, OLED), vom Dashboard aus
# devices.site_id abgeleitet - frueher stand er lokal als device.site in der
# config.yaml und blieb nach einer Neuinstallation auf dem Vorlagenwert stehen.
REMOTE_MANAGED_KEYS = ("scan", "connection_tests", "site")


def load_bootstrap(path: str) -> dict:
    with open(path, "r", encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def _config_hash(cfg: dict) -> str:
    blob = json.dumps(cfg, sort_keys=True).encode("utf-8")
    return hashlib.sha256(blob).hexdigest()


def _read_cache(cache_path: str) -> dict | None:
    p = Path(cache_path)
    if not p.exists():
        return None
    try:
        with p.open("r", encoding="utf-8") as fh:
            return yaml.safe_load(fh)
    except Exception:
        log.exception("Config-Cache konnte nicht gelesen werden, ignoriere ihn")
        return None


def _write_cache(cache_path: str, cfg: dict) -> None:
    p = Path(cache_path)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(".tmp")
    # Der Cache enthaelt WLAN-Zugangsdaten (PSK, 802.1X-Passwoerter,
    # Client-Schluessel) - nur fuer root lesbar anlegen, nicht mit den
    # Standardrechten (0644) des Prozesses.
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        yaml.safe_dump(cfg, fh)
    tmp.replace(p)
    os.chmod(p, 0o600)


def fetch_remote_config(
    server_url: str, api_key: str, device_id: str, verify_tls: bool, timeout: int
) -> dict | None:
    url = server_url.rstrip("/") + f"/devices/{device_id}/config"
    try:
        resp = requests.get(
            url,
            headers={"Authorization": f"Bearer {api_key}"},
            timeout=timeout,
            verify=verify_tls,
        )
    except requests.RequestException as exc:
        log.warning("Remote-Config nicht abrufbar: %s", exc)
        return None
    if resp.status_code == 404:
        # Server kennt (noch) keine spezifische Konfiguration für dieses
        # Gerät -> lokale/gecachte Werte weiterverwenden.
        return None
    if not (200 <= resp.status_code < 300):
        log.warning("Remote-Config-Abruf fehlgeschlagen: HTTP %d", resp.status_code)
        return None
    try:
        data = resp.json()
    except ValueError:
        log.warning("Remote-Config-Antwort war kein gültiges JSON")
        return None
    return {k: v for k, v in data.items() if k in REMOTE_MANAGED_KEYS}


def build_effective_config(bootstrap_cfg: dict, remote_cfg: dict | None) -> dict:
    """Bootstrap-Config + (Remote-Config falls vorhanden, sonst lokale Defaults)."""
    effective = dict(bootstrap_cfg)
    if remote_cfg:
        for key in REMOTE_MANAGED_KEYS:
            if key in remote_cfg:
                effective[key] = remote_cfg[key]
    return effective


def resolve_initial_config(bootstrap_cfg: dict) -> dict:
    """
    Wird einmal beim Start aufgerufen: versucht frische Remote-Config zu
    holen, fällt bei Fehlschlag auf den lokalen Cache zurück, und wenn
    auch der fehlt, auf die in config.yaml lokal hinterlegten Werte
    (scan/connection_tests müssen dafür in der config.yaml vorhanden sein).
    """
    rc_cfg = bootstrap_cfg.get("remote_config", {})
    if not rc_cfg.get("enabled", False):
        return bootstrap_cfg

    cache_path = rc_cfg["cache_path"]
    remote_cfg = fetch_remote_config(
        server_url=bootstrap_cfg["server"]["url"],
        api_key=bootstrap_cfg["server"]["api_key"],
        device_id=bootstrap_cfg["device"].get("id", ""),
        verify_tls=bootstrap_cfg["server"].get("verify_tls", True),
        timeout=bootstrap_cfg["server"].get("request_timeout", 10),
    )

    if remote_cfg:
        _write_cache(cache_path, remote_cfg)
        log.info("Remote-Config beim Start erfolgreich geladen")
        return build_effective_config(bootstrap_cfg, remote_cfg)

    cached = _read_cache(cache_path)
    if cached:
        log.info("Remote-Config nicht erreichbar, nutze lokalen Cache")
        return build_effective_config(bootstrap_cfg, cached)

    log.warning(
        "Weder Remote-Config noch Cache verfügbar, nutze lokale Werte aus config.yaml"
    )
    return bootstrap_cfg


class ConfigWatcher(threading.Thread):
    """
    Prüft periodisch, ob sich die Remote-Config geändert hat. Bei einer
    Änderung wird der Prozess sauber beendet, damit systemd ihn mit der
    neuen Konfiguration frisch neu startet (Restart=always vorausgesetzt).
    """

    def __init__(self, bootstrap_cfg: dict, current_effective_cfg: dict, stop_event: threading.Event):
        super().__init__(name="config-watcher", daemon=True)
        self._bootstrap_cfg = bootstrap_cfg
        self._stop_event = stop_event
        rc_cfg = bootstrap_cfg.get("remote_config", {})
        self._enabled = rc_cfg.get("enabled", False)
        self._poll_interval = rc_cfg.get("poll_interval_seconds", 300)
        self._cache_path = rc_cfg.get("cache_path")
        managed_subset = {
            k: current_effective_cfg.get(k) for k in REMOTE_MANAGED_KEYS
        }
        self._current_hash = _config_hash(managed_subset)

    def run(self) -> None:
        if not self._enabled:
            return
        while not self._stop_event.wait(self._poll_interval):
            remote_cfg = fetch_remote_config(
                server_url=self._bootstrap_cfg["server"]["url"],
                api_key=self._bootstrap_cfg["server"]["api_key"],
                device_id=self._bootstrap_cfg["device"].get("id", ""),
                verify_tls=self._bootstrap_cfg["server"].get("verify_tls", True),
                timeout=self._bootstrap_cfg["server"].get("request_timeout", 10),
            )
            if not remote_cfg:
                continue

            new_hash = _config_hash(remote_cfg)
            if new_hash == self._current_hash:
                continue

            log.info("Neue Remote-Config erkannt, schreibe Cache und starte neu")
            _write_cache(self._cache_path, remote_cfg)
            # Sauberer Neustart über systemd statt Live-Reload der Loops.
            import os

            os._exit(75)
