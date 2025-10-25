# Multi-Stage Dockerfile for AFS-MappingXT
# Apache with mpm_event + PHP-FPM for optimal performance
# Based on Debian bookworm

FROM php:8.3-fpm-bookworm AS php-base

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libsqlite3-dev \
    unixodbc-dev \
    libyaml-dev \
    gnupg \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install Microsoft ODBC Driver for SQL Server (for MSSQL support)
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/microsoft.gpg \
    && echo "deb [arch=amd64] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_sqlite \
        zip \
        intl \
        mbstring \
        opcache \
        pcntl

# Install PECL extensions
RUN pecl install sqlsrv pdo_sqlsrv yaml \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv yaml

# PHP-FPM configuration optimizations
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Create necessary directories
RUN mkdir -p /var/www/html/db /var/www/html/logs /var/www/html/Files/Bilder /var/www/html/Files/Dokumente \
    && chown -R www-data:www-data /var/www/html

# Healthcheck - check if PHP-FPM is listening
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD php -v || exit 1

EXPOSE 9000

CMD ["php-fpm"]

# Apache stage
FROM debian:bookworm-slim AS apache

# Install Apache with mpm_event (not mpm_prefork)
RUN apt-get update && apt-get install -y \
    apache2 \
    libapache2-mod-fcgid \
    curl \
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
