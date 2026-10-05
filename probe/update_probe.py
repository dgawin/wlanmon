#!/opt/wlanmon-probe/venv/bin/python3
"""
Prueft, ob im lokalen Git-Checkout des Probe-Repos (auf origin/<branch>)
eine neuere Version vorliegt, und installiert sie automatisch:
git fetch + git merge --ff-only -> Dateien nach /opt/wlanmon-probe/ kopieren
(alle *.py, portals/, requirements.txt, VERSION, wlan_watchdog.sh) -> pip
install -> Probelauf (import main) -> systemctl restart wlanmon-probe.
Scheitert der Probelauf, wird der vorherige Stand zurueckgelegt und nicht
neu gestartet. Auch wenn der Checkout schon aktuell ist, wird geprueft, ob
die Installation dazu passt, und bei Bedarf nachgezogen.

Wird periodisch von wlanmon-probe-update.timer aufgerufen (Standard alle
15 Minuten, siehe wlanmon-probe-update.timer). Nur aktiv, wenn
auto_update.enabled: true in /etc/wlanmon-probe/config.yaml steht - sonst
sofortiger, folgenloser Exit. Macht nie einen destruktiven Schritt: bei
einem Merge-Konflikt, lokalen Aenderungen im Checkout oder einem fehlenden/
ungueltigen repo_dir wird nur geloggt und abgebrochen (git merge --ff-only
verweigert sich in diesen Faellen von selbst, statt etwas zu ueberschreiben).

Sicherheitshinweis: Das ist automatische Codeausfuehrung auf einem Feld-
geraet aus einem Git-Repo heraus. auto_update nur aktivieren, wenn der
Branch, auf den hier verwiesen wird (Standard: main), tatsaechlich nur das
enthaelt, was auf den Geraeten laufen soll - kein Test-/Feature-Branch.
"""

from __future__ import annotations

import filecmp
import json
import os
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

import yaml

CONFIG_PATH = "/etc/wlanmon-probe/config.yaml"
INSTALL_DIR = Path("/opt/wlanmon-probe")
SERVICE_NAME = "wlanmon-probe"
# Ergebnis des letzten Laufs fuer die Anzeige im Dashboard (liest main.py
# und schickt es mit). Liegt unter /var/lib statt im Checkout, weil der
# Probe-Dienst mit ProtectHome=true laeuft und /home gar nicht sieht.
STATE_PATH = Path("/var/lib/wlanmon-probe/update_state.json")

# Wird von main() waehrend des Laufs befuellt und am Ende geschrieben.
STATE: dict = {}

# Was nach /opt/wlanmon-probe/ gehoert, ergibt sich aus dem Checkout (siehe
# installable_files()): alle *.py im Hauptordner und in portals/, dazu diese
# Dateien. Frueher stand hier eine feste Liste - ein neues Modul kam damit
# nicht an, main.py startete nicht mehr, und der naechste Lauf meldete
# "bereits aktuell", weil der Checkout ja aktuell war.
EXTRA_FILES = ["requirements.txt", "VERSION", "wlan_watchdog.sh"]
EXECUTABLE = {"wlan_watchdog.sh"}
# Gesicherter Stand vor dem Kopieren - Ruecksprung, wenn der Probelauf scheitert.
BACKUP_DIR = INSTALL_DIR / ".previous"
VENV_PYTHON = INSTALL_DIR / "venv/bin/python3"
# Gesetzt, wenn sich der Updater nach dem Ersetzen seiner selbst neu gestartet hat;
# enthaelt die Meldung des unterbrochenen Updates ("Update a -> b installiert").
REEXEC_ENV = "WLANMON_UPDATER_REEXEC"


def log(msg: str) -> None:
    print(f"[update_probe] {msg}", flush=True)


def fail(msg: str) -> int:
    """Fehlschlag loggen und als Ergebnis fuer das Dashboard merken.
    fail_count zaehlt aufeinanderfolgende Fehlschlaege (siehe main()) - das
    Dashboard zeigt erst ab 2 in Folge einen roten Update-Fehler, ein
    einzelner Aussetzer (z.B. Netz kurz weg) nur als Hinweis."""
    log(msg)
    STATE.update(result="error", message=msg[:300], fail_count=PREV_FAIL_COUNT + 1)
    return 1


def previous_fail_count() -> int:
    """fail_count aus der Status-Datei des letzten Laufs (0, wenn es keine gibt)."""
    try:
        prev = json.loads(STATE_PATH.read_text(encoding="utf-8"))
        return int(prev.get("fail_count") or 0) if isinstance(prev, dict) else 0
    except (OSError, ValueError, TypeError):
        return 0


PREV_FAIL_COUNT = previous_fail_count()

# Netzwerkzugriffe von git (fetch/pull) sollen bei einer haengenden
# Verbindung schnell aufgeben statt erst nach dem 120-s-Timeout von run():
# live beobachtet, dass ein fetch ueber SSH haengen blieb, als er zeitgleich
# mit einem Connection-Test lief (wlan0 im selben Subnetz wie eth0 bekommt
# dann kurz eine eigene Default-Route, die Verbindung reisst beim Trennen
# ab). SSH: Verbindungsaufbau max. 20 s, tote Verbindung nach ~30 s erkannt;
# HTTPS: Abbruch, wenn 30 s lang weniger als 1 KB/s fliesst.
SSH_TIMEOUT_OPTS = "-o ConnectTimeout=20 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 -o BatchMode=yes"
GIT_NET_OPTS = ["-c", "http.lowSpeedLimit=1000", "-c", "http.lowSpeedTime=30"]


def git_net_env(repo_dir: Path) -> dict:
    """Umgebung fuer git fetch/pull: die Timeouts an den SSH-Befehl anhaengen,
    den git ohnehin benutzen wuerde, statt ihn zu ersetzen. GIT_SSH_COMMAND hat
    in git Vorrang vor core.sshCommand - ein fest gesetztes "ssh -o ..." hat
    (Vorfall 1.0.1.12, Test-Probe) eine per core.sshCommand eingerichtete
    Schluessel-/known_hosts-Datei verdraengt: "Host key verification failed".
    Reihenfolge wie bei git: GIT_SSH_COMMAND, dann core.sshCommand (Repo,
    global, system), sonst schlicht "ssh"."""
    base = os.environ.get("GIT_SSH_COMMAND", "").strip()
    if not base:
        base = run(["git", "config", "--get", "core.sshCommand"], cwd=repo_dir).stdout.strip()
    return {
        **os.environ,
        "GIT_SSH_COMMAND": f"{base or 'ssh'} {SSH_TIMEOUT_OPTS}",
        "GIT_TERMINAL_PROMPT": "0",
    }
FETCH_ATTEMPTS = 2
FETCH_RETRY_WAIT = 45  # s - ein laufender Connection-Test ist bis dahin meist vorbei


def origin_url(repo_dir: Path) -> str | None:
    """URL von remote "origin", Zugangsdaten in HTTP(S)-URLs
    (https://user:token@...) entfernt - sie geht ans Dashboard."""
    url = run(["git", "remote", "get-url", "origin"], cwd=repo_dir).stdout.strip()
    if not url:
        return None
    parts = urlsplit(url)
    if parts.scheme in ("http", "https") and "@" in parts.netloc:
        url = urlunsplit(parts._replace(netloc=parts.netloc.rsplit("@", 1)[1]))
    return url


def write_state() -> None:
    """STATE atomar nach STATE_PATH schreiben; ein Fehler hier darf das
    Update selbst nie beeinflussen."""
    STATE["run_at"] = datetime.now(timezone.utc).isoformat()
    try:
        STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
        tmp = STATE_PATH.with_suffix(".tmp")
        tmp.write_text(json.dumps(STATE), encoding="utf-8")
        os.replace(tmp, STATE_PATH)
    except OSError as exc:
        log(f"Status-Datei {STATE_PATH} nicht schreibbar: {exc}")


def run(
    cmd: list[str], cwd: Path | None = None, timeout: int = 120, env: dict | None = None
) -> subprocess.CompletedProcess:
    """subprocess.run(), das bei einem Timeout oder einem Start-Fehler
    (z.B. Binary nicht gefunden) NIE eine Exception hochreicht, sondern wie
    ein regulaerer Fehlschlag (returncode != 0, Fehlertext in stderr)
    zurueckkommt - main() behandelt dann jeden git-/pip-/systemctl-Aufruf
    einheitlich ueber .returncode, ohne dass ein einzelner haengender
    Netzwerkzugriff das ganze Skript mit einem unbehandelten Traceback
    abbrechen kann (siehe Vorfall: git fetch nach einem Netz-Haenger)."""
    try:
        return subprocess.run(
            cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout, env=env
        )
    except subprocess.TimeoutExpired:
        return subprocess.CompletedProcess(
            cmd, returncode=1, stdout="", stderr=f"Timeout nach {timeout}s"
        )
    except OSError as exc:
        return subprocess.CompletedProcess(cmd, returncode=1, stdout="", stderr=str(exc))


def ensure_safe_directory(repo_dir: Path) -> None:
    """Traegt repo_dir in Roots globaler git-Konfiguration als
    'safe.directory' ein, falls noch nicht geschehen. Noetig, weil dieses
    Skript als root laeuft, der Checkout aber meist dem Benutzer gehoert,
    der ihn per "git clone" angelegt hat - seit einer Sicherheitsluecke
    (CVE-2022-24765) verweigert git sonst jeden Zugriff mit "detected
    dubious ownership in repository at ...". Idempotent (fuegt den Eintrag
    nicht mehrfach hinzu); ein Fehlschlag hier ist nicht fatal, der
    naechste git-Aufruf zeigt dann ohnehin die eigentliche Fehlermeldung."""
    existing = run(["git", "config", "--global", "--get-all", "safe.directory"])
    already_set = str(repo_dir) in existing.stdout.splitlines()
    if not already_set:
        run(["git", "config", "--global", "--add", "safe.directory", str(repo_dir)])


def git_toplevel(repo_dir: Path) -> Path | None:
    """Verzeichnis mit .git: repo_dir selbst (eigenes Probe-Repo) oder - wenn
    repo_dir der Unterordner probe/ des gemeinsamen wlanmon-Repos ist - ein
    Elternverzeichnis. Ein Elternverzeichnis zaehlt nur, wenn repo_dir
    wirklich die Probe enthaelt (update_probe.py), damit ein falsch gesetztes
    repo_dir nicht z.B. ein Dotfiles-Repo im Home erwischt. Bewusst per
    Dateisystem statt "git rev-parse": vor ensure_safe_directory() verweigert
    git als root den Zugriff auf Checkouts anderer Benutzer."""
    if (repo_dir / ".git").is_dir():
        return repo_dir
    if not (repo_dir / "update_probe.py").is_file():
        return None
    for parent in repo_dir.parents:
        if (parent / ".git").is_dir():
            return parent
    return None


def load_auto_update_config() -> dict | None:
    try:
        with open(CONFIG_PATH, encoding="utf-8") as fh:
            cfg = yaml.safe_load(fh) or {}
    except OSError as exc:
        log(f"config.yaml nicht lesbar, breche ab: {exc}")
        return None
    return cfg.get("auto_update") or {}


def installable_files(repo_dir: Path) -> list[str]:
    """Pfade (relativ) aller Dateien, die aus dem Checkout installiert werden."""
    names = sorted(p.name for p in repo_dir.glob("*.py"))
    names += [f"portals/{p.name}" for p in sorted((repo_dir / "portals").glob("*.py"))]
    names += [n for n in EXTRA_FILES if (repo_dir / n).is_file()]
    return names


def out_of_sync(repo_dir: Path) -> list[str]:
    """Dateien, die in /opt fehlen oder vom Checkout abweichen."""
    return [
        name for name in installable_files(repo_dir)
        if not (INSTALL_DIR / name).is_file()
        or not filecmp.cmp(repo_dir / name, INSTALL_DIR / name, shallow=False)
    ]


def sync_files(repo_dir: Path) -> None:
    """Checkout nach /opt kopieren; vorher den bisherigen Stand nach BACKUP_DIR
    sichern (fuer restore_backup(), falls der Probelauf scheitert)."""
    if BACKUP_DIR.exists():
        shutil.rmtree(BACKUP_DIR)
    for name in installable_files(repo_dir):
        dst = INSTALL_DIR / name
        if dst.is_file():
            (BACKUP_DIR / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(dst, BACKUP_DIR / name)
    for name in installable_files(repo_dir):
        dst = INSTALL_DIR / name
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(repo_dir / name, dst)
        if name in EXECUTABLE:
            dst.chmod(0o755)


def restore_backup() -> None:
    """Gesicherten Stand zuruecklegen (neu hinzugekommene Dateien bleiben
    liegen - der alte Code importiert sie nicht)."""
    if not BACKUP_DIR.is_dir():
        return
    for src in BACKUP_DIR.rglob("*"):
        if src.is_file():
            shutil.copy2(src, INSTALL_DIR / src.relative_to(BACKUP_DIR))


def smoke_test() -> str | None:
    """Probelauf vor dem Neustart: main.py mit dem venv der Probe importieren
    (laedt alle Module, startet aber nichts). Liefert die Fehlermeldung oder None."""
    if not VENV_PYTHON.exists():
        return None   # kein venv gefunden - nicht pruefbar, nicht blockieren
    res = run([str(VENV_PYTHON), "-c", "import main"], cwd=INSTALL_DIR, timeout=60)
    if res.returncode == 0:
        return None
    lines = (res.stderr or res.stdout).strip().splitlines()
    return lines[-1] if lines else f"Exitcode {res.returncode}"


def version_tuple(path: Path) -> tuple[int, ...] | None:
    """VERSION-Datei als Zahlentupel ("1.0.1.60" -> (1, 0, 1, 60)), None wenn
    sie fehlt oder nicht nur aus Zahlen besteht."""
    try:
        return tuple(int(part) for part in path.read_text().strip().split("."))
    except (OSError, ValueError):
        return None


def reexec_if_updater_changed(repo_dir: Path, reason: str = "") -> None:
    """Weicht update_probe.py im Checkout von der laufenden Kopie ab, sie
    ersetzen und neu starten - der Rest des Laufs passiert dann schon mit der
    neuen Logik (sonst gaelte jede Aenderung hier erst einen Lauf spaeter).
    Nur einmal pro Lauf (REEXEC_ENV).

    Nicht bei einem Downgrade (Checkout-VERSION aelter als die installierte,
    z.B. Wechsel von main auf einen aelteren stable): Der alte Updater kennt
    die Fortsetzung nach dem Neustart nicht, meldete "bereits aktuell" und
    hinterliess einen Mischstand. Stattdessen installiert dieser Lauf alles,
    den alten Updater eingeschlossen, in einem Rutsch."""
    running = Path(__file__).resolve()
    new = repo_dir / "update_probe.py"
    if os.environ.get(REEXEC_ENV) or not new.is_file() or running.parent != INSTALL_DIR.resolve():
        return
    if filecmp.cmp(new, running, shallow=False):
        return
    checkout_version = version_tuple(repo_dir / "VERSION")
    installed_version = version_tuple(INSTALL_DIR / "VERSION")
    if checkout_version is not None and installed_version is not None and checkout_version < installed_version:
        log("Checkout ist aelter als die Installation - Updater wird ohne Neustart mit ersetzt")
        return
    shutil.copy2(new, running)
    log("Updater selbst aktualisiert - Neustart mit der neuen Version")
    os.environ[REEXEC_ENV] = reason or "1"
    os.execv(sys.executable, [sys.executable, str(running), *sys.argv[1:]])


def install(repo_dir: Path, reason: str) -> int:
    """Dateien installieren, Abhaengigkeiten nachziehen, Probelauf, Neustart.
    Scheitert der Probelauf, wird der vorherige Stand zurueckgelegt und die
    Probe NICHT neu gestartet - sie laeuft mit dem alten Code weiter."""
    sync_files(repo_dir)
    pip = run([
        str(INSTALL_DIR / "venv/bin/pip"), "install", "-q", "-r",
        str(INSTALL_DIR / "requirements.txt"),
    ])
    if pip.returncode != 0:
        log(f"pip install fehlgeschlagen (Update wird trotzdem versucht): {pip.stderr.strip()}")
    error = smoke_test()
    if error is not None:
        restore_backup()
        return fail(f"Probelauf fehlgeschlagen, vorheriger Stand wiederhergestellt: {error}")
    # Status vor dem Neustart schreiben, damit die neu gestartete Probe
    # gleich den neuen Stand meldet.
    STATE.update(result="updated", message=reason[:300])
    write_state()
    restart = run(["systemctl", "restart", SERVICE_NAME])
    if restart.returncode != 0:
        return fail(f"systemctl restart {SERVICE_NAME} fehlgeschlagen: {restart.stderr.strip()}")
    log(f"{reason} - {SERVICE_NAME} neu gestartet.")
    return 0


def main() -> int:
    # Jeder Lauf, der nicht ueber fail() endet, setzt die Fehlerserie zurueck.
    STATE["fail_count"] = 0
    au = load_auto_update_config()
    if au is None:
        return fail("config.yaml nicht lesbar")
    if not au.get("enabled"):
        log("auto_update.enabled ist nicht true, nichts zu tun.")
        STATE.update(result="disabled")
        return 0
    if not isinstance(au.get("enabled"), bool):
        # Jeder nicht-leere Wert zaehlt als "an" (so war es schon immer, eine
        # strengere Pruefung wuerde betroffene Geraete still vom Update
        # abschneiden) - aber sichtbar machen, z.B. "enabled: enabled".
        log(f"Hinweis: auto_update.enabled ist {au.get('enabled')!r} statt true - "
            f"wird als an gewertet, bitte in config.yaml auf true setzen.")

    repo_dir = Path(str(au.get("repo_dir") or "")).expanduser()
    branch = str(au.get("branch") or "main")
    log(f"Branch: {branch} · repo_dir: {repo_dir}")
    toplevel = git_toplevel(repo_dir) if repo_dir.is_dir() else None
    if toplevel is None:
        return fail(f"repo_dir '{repo_dir}' ist kein Git-Checkout (auto_update.repo_dir "
                    f"in config.yaml pruefen), breche ab.")

    # safe.directory gilt fuer die Wurzel des Checkouts, nicht fuer Unterordner.
    ensure_safe_directory(toplevel)
    STATE.update(repo_url=origin_url(repo_dir))
    net_env = git_net_env(repo_dir)

    for attempt in range(1, FETCH_ATTEMPTS + 1):
        fetch = run(
            ["git", *GIT_NET_OPTS, "fetch", "--quiet", "origin", branch],
            cwd=repo_dir, env=net_env,
        )
        if fetch.returncode == 0:
            break
        if attempt < FETCH_ATTEMPTS:
            log(f"git fetch fehlgeschlagen ({fetch.stderr.strip()}), "
                f"neuer Versuch in {FETCH_RETRY_WAIT}s")
            time.sleep(FETCH_RETRY_WAIT)
    else:
        return fail(f"git fetch fehlgeschlagen ({FETCH_ATTEMPTS} Versuche): {fetch.stderr.strip()}")

    local = run(["git", "rev-parse", "HEAD"], cwd=repo_dir).stdout.strip()
    remote = run(["git", "rev-parse", f"origin/{branch}"], cwd=repo_dir).stdout.strip()
    STATE.update(commit=local[:8] or None)
    if not local or not remote:
        return fail("HEAD/origin nicht ermittelbar, breche ab.")
    if local == remote:
        reexec_if_updater_changed(repo_dir)
        # Checkout aktuell - aber passt auch die Installation dazu? Ein frueherer
        # Lauf (z.B. mit der alten festen Dateiliste) kann Dateien ausgelassen
        # haben; ohne diese Pruefung bliebe das Geraet so haengen.
        missing = out_of_sync(repo_dir)
        if missing:
            resumed = os.environ.get(REEXEC_ENV, "")
            if resumed not in ("", "1"):
                # Fortsetzung eines Updates nach dem Neustart des Updaters.
                return install(repo_dir, resumed)
            return install(repo_dir, f"Installation abgeglichen ({', '.join(missing[:5])}"
                                     f"{' …' if len(missing) > 5 else ''})")
        log("bereits aktuell.")
        STATE.update(result="current")
        return 0

    log(f"Update verfuegbar: {local[:8]} -> {remote[:8]}")
    # --ff-only: verweigert sich (statt zu mergen/zu ueberschreiben), falls
    # der Checkout lokal abweicht - der sichere Fehlschlag hier ist
    # ausdruecklich erwuenscht.
    pull = run(["git", "checkout", "--quiet", branch], cwd=repo_dir)
    if pull.returncode != 0:
        return fail(f"git checkout {branch} fehlgeschlagen: {pull.stderr.strip()}")
    # Merge auf den eben geholten Stand statt "git pull": kein zweiter
    # Netzzugriff. Der lief sonst ein paar Sekunden nach dem fetch erneut
    # zu GitHub - 2026-10-02 auf einem NEO3 genau in einen Connection-Test
    # hinein (Default-Route kurz ueber wlan0), die Probe nahm wlan0 die
    # Adresse weg und die SSH-Verbindung hing bis zum Timeout.
    pull = run(["git", "merge", "--ff-only", "--quiet", remote], cwd=repo_dir)
    if pull.returncode != 0:
        return fail(f"git merge --ff-only fehlgeschlagen, breche ab: {pull.stderr.strip()}")

    STATE.update(commit=remote[:8])
    # Neue Updater-Version zuerst: sie uebernimmt den Rest dieses Laufs.
    reason = f"Update {local[:8]} -> {remote[:8]} installiert"
    reexec_if_updater_changed(repo_dir, reason)
    return install(repo_dir, reason)


if __name__ == "__main__":
    try:
        code = main()
    except Exception as exc:  # noqa: BLE001 - Sicherheitsnetz: nie unbehandelt
        # crashen (z.B. beim Datei-Kopieren in sync_files oder einem
        # kaputten config.yaml) - so steht im Log immer eine lesbare Zeile
        # statt eines rohen Python-Tracebacks.
        code = fail(f"unerwarteter Fehler: {type(exc).__name__}: {exc}")
    write_state()
    sys.exit(code)
