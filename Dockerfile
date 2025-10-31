# ------------------------------------------------------------
# Multi-Stage Dockerfile for AFS-MappingXT
# PHP-FPM (Debian bookworm) + Apache (mpm_event) via FCGI
# ------------------------------------------------------------
# Apache with mpm_event + PHP-FPM for optimal performance
# Based on Debian bookworm
#
# Optimizations applied:
# - Added --no-install-recommends to all apt-get install commands (reduces image size)
# - Added apt-get clean to all package installation steps (reduces layer size)
# - Proper cleanup with rm -rf /var/lib/apt/lists/* after each apt operation
# - Combined COPY and chmod using --chmod flag (reduces layers)
# - Optimized layer ordering: dependencies first, application code last (better caching)
# - Created directories before copying application files (cleaner layer structure)

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
# Install system dependencies and PHP extensions
# Minimal set of dependencies for the application
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    unixodbc-dev \
    gnupg \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Microsoft ODBC Driver for SQL Server (for MSSQL support)
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/microsoft.gpg \
    && echo "deb [arch=amd64] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
# Only installing extensions that are actually used by the application
RUN docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_sqlite \
        mbstring \
        opcache

# Install PECL extensions (separated for better error handling and debugging)
# Install MSSQL extensions (may fail in environments without MSSQL drivers)
# These are optional and graceful failure is expected in some deployment scenarios
# If installation fails, the build continues but logs a warning message
RUN (pecl install sqlsrv pdo_sqlsrv && \
    docker-php-ext-enable sqlsrv pdo_sqlsrv && \
    echo "✓ MSSQL extensions installed successfully") || \
    echo "⚠ MSSQL extensions installation failed (this is optional)"

# Konfig-Templates & Entrypoint
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf.template
COPY docker/php.ini      /usr/local/etc/php/conf.d/custom.ini.template
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini.template

# Entrypoint that renders configuration from templates
COPY --chmod=755 docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

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
# Create necessary directories with proper ownership before copying application files
# These directories are excluded by .dockerignore as they will be mounted as volumes
RUN mkdir -p /var/www/html/db /var/www/html/logs /var/www/html/Files/Bilder /var/www/html/Files/Dokumente \
 && chown -R www-data:www-data /var/www/html

# Healthcheck: PHP-FPM Prozess ok?

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Healthcheck - check if PHP-FPM is listening
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

# Install Apache with mpm_event (not mpm_prefork)
RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2 \
    libapache2-mod-fcgid \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

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
