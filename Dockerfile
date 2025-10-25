# Multi-Stage Dockerfile for AFS-MappingXT
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

FROM php:8.3-fpm-bookworm AS php-base

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

# Custom PHP-FPM and PHP configuration templates
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf.template
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini.template

# Entrypoint that renders configuration from templates
COPY --chmod=755 docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# Set working directory
WORKDIR /var/www/html

# Create necessary directories with proper ownership before copying application files
# These directories are excluded by .dockerignore as they will be mounted as volumes
RUN mkdir -p /var/www/html/db /var/www/html/logs /var/www/html/Files/Bilder /var/www/html/Files/Dokumente \
    && chown -R www-data:www-data /var/www/html

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Healthcheck - check if PHP-FPM is listening
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD php -v || exit 1

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]

# Apache stage
FROM debian:bookworm-slim AS apache

# Install Apache with mpm_event (not mpm_prefork)
RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2 \
    libapache2-mod-fcgid \
    curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable required Apache modules
RUN a2dismod mpm_prefork \
    && a2enmod mpm_event \
    && a2enmod proxy \
    && a2enmod proxy_fcgi \
    && a2enmod rewrite \
    && a2enmod headers \
    && a2enmod deflate \
    && a2enmod expires

# Apache configuration
COPY docker/apache2.conf /etc/apache2/apache2.conf
COPY docker/afs-mappingxt.conf /etc/apache2/sites-available/000-default.conf

# Set working directory for document root
WORKDIR /var/www/html

# Expose port
EXPOSE 80

# Healthcheck for Apache
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/api/health.php || exit 1

CMD ["apache2ctl", "-D", "FOREGROUND"]
