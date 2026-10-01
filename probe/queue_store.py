"""
Lokale, persistente Warteschlange für Messergebnisse.

Alle Messungen (Scans und Connection-Tests) werden zuerst hier
hineingeschrieben. Ein separater Sender-Thread liest unbestätigte
Einträge aus, schickt sie an den Server und markiert sie erst nach
erfolgreicher Bestätigung (HTTP 2xx) als "sent". So gehen bei einem
kurzzeitigen Ausfall der Serververbindung keine Daten verloren.
"""

from __future__ import annotations

import json
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any


SCHEMA = """
CREATE TABLE IF NOT EXISTS measurements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at REAL NOT NULL,
    kind TEXT NOT NULL,           -- 'scan' oder 'connection_test'
    payload TEXT NOT NULL,        -- JSON-serialisierte Messdaten
    sent INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_attempt_at REAL
);
CREATE INDEX IF NOT EXISTS idx_measurements_unsent
    ON measurements (sent, id);
"""


class QueueStore:
    def __init__(self, db_path: str, max_queue_size: int = 100_000):
        self._db_path = db_path
        self._max_queue_size = max_queue_size
        self._lock = threading.Lock()
        Path(db_path).parent.mkdir(parents=True, exist_ok=True)
        self._conn = sqlite3.connect(db_path, check_same_thread=False)
        self._conn.execute("PRAGMA journal_mode=WAL;")
        self._conn.executescript(SCHEMA)
        self._conn.commit()

    def enqueue(self, kind: str, payload: dict[str, Any]) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT INTO measurements (created_at, kind, payload) VALUES (?, ?, ?)",
                (time.time(), kind, json.dumps(payload, ensure_ascii=False)),
            )
            self._conn.commit()
            self._enforce_max_size()

    def _enforce_max_size(self) -> None:
        # Schutz gegen vollaufende Disk, falls der Server sehr lange
        # nicht erreichbar ist: älteste, bereits gesendete Einträge
        # zuerst löschen, danach zur Not auch ungesendete.
        row = self._conn.execute("SELECT COUNT(*) FROM measurements").fetchone()
        count = row[0]
        if count <= self._max_queue_size:
            return
        overflow = count - self._max_queue_size
        self._conn.execute(
            """
            DELETE FROM measurements WHERE id IN (
                SELECT id FROM measurements
                ORDER BY sent DESC, id ASC
                LIMIT ?
            )
            """,
            (overflow,),
        )
        self._conn.commit()

    def fetch_unsent(self, batch_size: int) -> list[tuple[int, str, dict[str, Any]]]:
        with self._lock:
            rows = self._conn.execute(
                """
                SELECT id, kind, payload FROM measurements
                WHERE sent = 0
                ORDER BY id ASC
                LIMIT ?
                """,
                (batch_size,),
            ).fetchall()
        return [(r[0], r[1], json.loads(r[2])) for r in rows]

    def mark_sent(self, ids: list[int]) -> None:
        if not ids:
            return
        with self._lock:
            placeholders = ",".join("?" for _ in ids)
            self._conn.execute(
                f"UPDATE measurements SET sent = 1 WHERE id IN ({placeholders})",
                ids,
            )
            self._conn.commit()

    def mark_attempt(self, ids: list[int]) -> None:
        if not ids:
            return
        with self._lock:
            placeholders = ",".join("?" for _ in ids)
            self._conn.execute(
                f"""
                UPDATE measurements
                SET attempts = attempts + 1, last_attempt_at = ?
                WHERE id IN ({placeholders})
                """,
                [time.time()] + ids,
            )
            self._conn.commit()

    def prune_sent(self, older_than_seconds: float = 86400) -> None:
        # Aufräumen: erfolgreich gesendete, ältere Einträge löschen,
        # damit die SQLite-Datei nicht unbegrenzt wächst.
        cutoff = time.time() - older_than_seconds
        with self._lock:
            self._conn.execute(
                "DELETE FROM measurements WHERE sent = 1 AND created_at < ?",
                (cutoff,),
            )
            self._conn.commit()

    def close(self) -> None:
        self._conn.close()
