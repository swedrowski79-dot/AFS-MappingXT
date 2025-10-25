# ------------------------------------------------------------
# Multi-Stage Dockerfile for AFS-MappingXT
# PHP-FPM (Debian bookworm) + Apache (mpm_event) via FCGI
# ------------------------------------------------------------

# =========================
# Stage 1: PHP-FPM Runtime
# =========================
FROM php:8.3-fpm-bookworm AS php-base

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/Berlin

# System & build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates gnupg curl \
    libpng-dev libjpeg-dev libfreetype6-dev \
    libzip-dev libicu-dev libonig-dev libxml2-dev \
    libsqlite3-dev unixodbc-dev libyaml-dev \
 && rm -rf /var/lib/apt/lists/*

# Microsoft ODBC Driver for SQL Server (msodbcsql18)
RUN set -eux; \
    curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/microsoft.gpg; \
    echo "deb [arch=amd64] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; \
    ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18; \
    rm -rf /var/lib/apt/lists/*

# PHP-Erweiterungen
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
      gd pdo pdo_sqlite zip intl mbstring opcache pcntl

# PECL-Erweiterungen
RUN pecl install sqlsrv pdo_sqlsrv yaml \
 && docker-php-ext-enable sqlsrv pdo_sqlsrv yaml

# Konfig-Templates & Entrypoint
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf.template
COPY docker/php.ini      /usr/local/etc/php/conf.d/custom.ini.template
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# WICHTIG:
#  - +x setzen
#  - CRLF -> LF konvertieren (fix für "no such file or directory")
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
 && sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh

# Arbeitsverzeichnis
WORKDIR /var/www/html

# App-Code
COPY --chown=www-data:www-data . /var/www/html/

# Verzeichnisse (Logs, DB, Files)
RUN mkdir -p /var/www/html/db /var/www/html/logs /var/www/html/Files/Bilder /var/www/html/Files/Dokumente \
 && chown -R www-data:www-data /var/www/html

# Healthcheck: PHP-FPM Prozess ok?
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
  CMD php -v || exit 1

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]


# =========================
# Stage 2: Apache (Frontend)
# =========================
FROM debian:bookworm-slim AS apache

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/Berlin

# Apache + FCGI
RUN apt-get update && apt-get install -y --no-install-recommends \
      apache2 libapache2-mod-fcgid curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Module aktivieren
RUN a2dismod mpm_prefork || true \
 && a2enmod mpm_event proxy proxy_fcgi rewrite headers deflate expires

# Apache-Konfiguration
COPY docker/apache2.conf       /etc/apache2/apache2.conf
COPY docker/afs-mappingxt.conf /etc/apache2/sites-available/000-default.conf

# App-Inhalt aus PHP-Stage übernehmen (falls kein Volume gemountet wird)
COPY --from=php-base /var/www/html /var/www/html

WORKDIR /var/www/html
EXPOSE 80

# Healthcheck: einfache API-Health-Route
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD curl -fsS http://localhost/api/health.php || exit 1

CMD ["apache2ctl", "-D", "FOREGROUND"]
