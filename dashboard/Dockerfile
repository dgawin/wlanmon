# WLANMON-Dashboard als Container (siehe README, Abschnitt "Docker").
# Web-Container und Cron-Container laufen aus diesem selben Image; die
# Konfiguration kommt aus Umgebungsvariablen (docker/config.php), das
# Datenbankschema spielt docker/entrypoint.sh bei jedem Start idempotent ein.
FROM php:8.3-apache

# mariadb-client: mariadb-dump für das nächtliche Backup (backup_db.php).
RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers remoteip

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY . /var/www/html/
# Umgebungsvariablen-basierte config.php; eine echte config.php aus dem
# Build-Kontext schließt .dockerignore aus (keine Secrets im Image). Wer eine
# eigene Datei will, bindet sie über /var/www/html/config.php ein.
RUN cp /var/www/html/docker/config.php /var/www/html/config.php \
    && chmod +x /var/www/html/docker/entrypoint.sh /var/www/html/docker/cron.sh \
    && chown -R www-data:www-data /var/www/html

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
