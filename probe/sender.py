"""
Sender-Thread: liest unbestätigte Einträge aus der lokalen Queue und
überträgt sie in Batches per HTTPS an die Server-API. Bei Fehlern
(Netzwerk, Server nicht erreichbar, 5xx) bleibt der Eintrag in der
Queue und wird beim nächsten Zyklus erneut versucht.
"""

from __future__ import annotations

import logging
import threading
import time
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
