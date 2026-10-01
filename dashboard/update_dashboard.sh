#!/usr/bin/env bash
# Prueft, ob im lokalen Git-Checkout (SRC_DIR) eine neuere Version auf
# origin/<branch> liegt, und installiert sie automatisch: git pull --ff-only
# -> Dateien nach LIVE_DIR (dem tatsaechlichen Docroot-Elternverzeichnis)
# synchronisieren. Gedacht fuer einen periodischen Cronjob, siehe README.md,
# Abschnitt "Automatische Updates".
#
# Kein systemd-Neustart noetig: PHP-Dateien wirken beim naechsten Request
# sofort (OPcache validiert bei Standardeinstellungen die Datei-mtime alle
# paar Sekunden automatisch neu).
#
# Nie destruktiv: git pull --ff-only verweigert sich bei lokalen Aenderungen
# im Checkout statt etwas zu ueberschreiben; rsync laeuft ohne --delete,
# loescht in LIVE_DIR also nie etwas, und config.php (nicht im Repo, siehe
# .gitignore) wird nie angefasst.
#
# Ein-/Ausschalten = Cronjob eintragen/entfernen, siehe README.md.
set -euo pipefail

# Anpassen: SRC_DIR = separater Git-Checkout (NICHT der Docroot selbst) bzw.
# dessen Unterordner dashboard/ beim gemeinsamen wlanmon-Repo,
# LIVE_DIR = das tatsaechlich ausgelieferte Verzeichnis (Docroot-Elternteil,
# also das mit public/, src/, config.php).
SRC_DIR="${WLANMON_DASHBOARD_SRC_DIR:-$HOME/wlanmon/dashboard}"
LIVE_DIR="${WLANMON_DASHBOARD_LIVE_DIR:-/var/www/wlanmon}"
# "stable" statt "main": ein Commit geht erst live, wenn er bewusst von
# main nach stable gemerged wird (siehe README, "Automatische Updates").
BRANCH="${WLANMON_DASHBOARD_BRANCH:-stable}"
LOCK_FILE="/tmp/wlanmon-dashboard-update.lock"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# Verhindert ueberlappende Laeufe (z.B. bei einem langsamen "git fetch"),
# ohne den naechsten Cron-Tick zu blockieren - "-n" gibt sofort auf statt
# zu warten.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Vorheriger Lauf noch aktiv, ueberspringe."
    exit 0
fi

log "Branch: $BRANCH · SRC_DIR: $SRC_DIR · LIVE_DIR: $LIVE_DIR"

if [ ! -d "$SRC_DIR/.git" ] && ! { [ -f "$SRC_DIR/public/index.php" ] && git -C "$SRC_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; }; then
    log "SRC_DIR '$SRC_DIR' ist kein Git-Checkout - einmalig anlegen mit:"
    log "  git clone https://github.com/dgawin/wlanmon.git \$HOME/wlanmon"
    exit 1
fi
if [ ! -d "$LIVE_DIR" ]; then
    log "LIVE_DIR '$LIVE_DIR' existiert nicht, breche ab."
    exit 1
fi

cd "$SRC_DIR"
git fetch --quiet origin "$BRANCH"

local_rev="$(git rev-parse HEAD)"
remote_rev="$(git rev-parse "origin/$BRANCH")"
if [ "$local_rev" = "$remote_rev" ]; then
    log "bereits aktuell."
    exit 0
fi

log "Update verfuegbar: ${local_rev:0:8} -> ${remote_rev:0:8}"
git checkout --quiet "$BRANCH"
if ! git pull --quiet --ff-only origin "$BRANCH"; then
    log "git pull --ff-only fehlgeschlagen (lokale Aenderungen im Checkout?), breche ab."
    exit 1
fi

# --delete bewusst NICHT gesetzt: nie etwas in LIVE_DIR loeschen, das nicht
# (mehr) im Repo steht - nur hinzufuegen/aktualisieren. config.php ist nicht
# im Repo (siehe .gitignore) und bleibt dadurch automatisch unangetastet.
rsync -a --exclude='.git' --exclude='config.php' "$SRC_DIR"/ "$LIVE_DIR"/

log "Update auf ${remote_rev:0:8} nach $LIVE_DIR synchronisiert."
