#!/bin/sh
# Ersetzt die Cronjobs der klassischen Installation (README: check_alerts.php
# alle 5 Minuten, sync_cirrus_aps.php stündlich, backup_db.php und
# cleanup_data.php nächtlich) - ohne cron-Dienst im Image,
# einfach als Schleife im eigenen Container. Ausgabe landet im Container-Log
# (docker logs / Portainer). update_dashboard.sh entfällt im Docker-Betrieb:
# Updates kommen über ein neues Image (Portainer-Stack aus Git).

cd /var/www/html || exit 1
echo "[cron] gestartet: Alerting alle 5 min, Cirrus-Sync stündlich, Backup 01 Uhr UTC, Aufräumen 02 Uhr UTC"

last_alert=""
last_cirrus=""
last_backup=""
last_cleanup=""
while true; do
    now=$(date -u +%s)
    slot5=$((now / 300))
    hour=$((now / 3600))
    day=$(date -u +%F)
    hour_of_day=$(date -u +%H)

    # Einmal pro Nacht: erst das Backup, eine Stunde später das Aufräumen -
    # was gelöscht wird, steckt so noch im Backup der Nacht.
    if [ "$hour_of_day" = "01" ] && [ "$day" != "$last_backup" ]; then
        last_backup=$day
        php backup_db.php || echo "[cron] backup_db.php Exit-Code $?"
    fi
    if [ "$hour_of_day" = "02" ] && [ "$day" != "$last_cleanup" ]; then
        last_cleanup=$day
        php cleanup_data.php || echo "[cron] cleanup_data.php Exit-Code $?"
    fi

    if [ "$slot5" != "$last_alert" ]; then
        last_alert=$slot5
        php check_alerts.php || echo "[cron] check_alerts.php Exit-Code $?"
    fi
    if [ "$hour" != "$last_cirrus" ]; then
        last_cirrus=$hour
        php sync_cirrus_aps.php || echo "[cron] sync_cirrus_aps.php Exit-Code $?"
    fi

    sleep 30
done
