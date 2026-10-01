#!/bin/sh
# Start des Web- bzw. Cron-Containers: Schema idempotent einspielen, dann das
# eigentliche Kommando (apache2-foreground bzw. docker/cron.sh) ausführen.
set -e

if [ -z "$WLANMON_DB_PASSWORD" ]; then
    echo "[entrypoint] WLANMON_DB_PASSWORD ist nicht gesetzt - Abbruch." >&2
    exit 1
fi

php /var/www/html/docker/init_db.php

exec "$@"
