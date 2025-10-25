#!/bin/sh
set -e

echo "Starting AFS-MappingXT PHP-FPM container..."

: "${PHP_MEMORY_LIMIT:=256M}"
: "${PHP_MAX_EXECUTION_TIME:=300}"
: "${TZ:=Europe/Berlin}"

echo "  PHP_MEMORY_LIMIT=${PHP_MEMORY_LIMIT}"
echo "  PHP_MAX_EXECUTION_TIME=${PHP_MAX_EXECUTION_TIME}"
echo "  TZ=${TZ}"

# Render php.ini from template
if [ -f /usr/local/etc/php/conf.d/custom.ini.template ]; then
  sed \
    -e "s|\${PHP_MEMORY_LIMIT}|${PHP_MEMORY_LIMIT}|g" \
    -e "s|\${PHP_MAX_EXECUTION_TIME}|${PHP_MAX_EXECUTION_TIME}|g" \
    -e "s|\${TZ}|${TZ}|g" \
    /usr/local/etc/php/conf.d/custom.ini.template \
    > /usr/local/etc/php/conf.d/custom.ini
fi

# Render php-fpm pool configuration
if [ -f /usr/local/etc/php-fpm.d/zz-custom.conf.template ]; then
  sed \
    -e "s|\${PHP_MEMORY_LIMIT}|${PHP_MEMORY_LIMIT}|g" \
    -e "s|\${PHP_MAX_EXECUTION_TIME}|${PHP_MAX_EXECUTION_TIME}|g" \
    /usr/local/etc/php-fpm.d/zz-custom.conf.template \
    > /usr/local/etc/php-fpm.d/zz-custom.conf
fi

echo "Configuration rendered. Launching PHP-FPM..."
exec "$@"
