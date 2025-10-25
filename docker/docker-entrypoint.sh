#!/bin/sh
set -e

echo "Starting AFS-MappingXT PHP-FPM container..."

# Set defaults for PHP configuration
: "${PHP_MEMORY_LIMIT:=256M}"
: "${PHP_MAX_EXECUTION_TIME:=300}"
: "${TZ:=Europe/Berlin}"

# Set defaults for OPcache configuration
: "${OPCACHE_MEMORY_CONSUMPTION:=256}"
: "${OPCACHE_INTERNED_STRINGS_BUFFER:=16}"
: "${OPCACHE_MAX_ACCELERATED_FILES:=10000}"
: "${OPCACHE_REVALIDATE_FREQ:=60}"
: "${OPCACHE_VALIDATE_TIMESTAMPS:=0}"
: "${OPCACHE_HUGE_CODE_PAGES:=0}"

# Set defaults for JIT configuration
: "${OPCACHE_JIT_MODE:=tracing}"
: "${OPCACHE_JIT_BUFFER_SIZE:=128M}"

echo "  PHP_MEMORY_LIMIT=${PHP_MEMORY_LIMIT}"
echo "  PHP_MAX_EXECUTION_TIME=${PHP_MAX_EXECUTION_TIME}"
echo "  TZ=${TZ}"
echo "  OPCACHE_MEMORY_CONSUMPTION=${OPCACHE_MEMORY_CONSUMPTION}M"
echo "  OPCACHE_JIT_MODE=${OPCACHE_JIT_MODE}"
echo "  OPCACHE_JIT_BUFFER_SIZE=${OPCACHE_JIT_BUFFER_SIZE}"

# Render php.ini from template
if [ -f /usr/local/etc/php/conf.d/custom.ini.template ]; then
  sed \
    -e "s|\${PHP_MEMORY_LIMIT}|${PHP_MEMORY_LIMIT}|g" \
    -e "s|\${PHP_MAX_EXECUTION_TIME}|${PHP_MAX_EXECUTION_TIME}|g" \
    -e "s|\${TZ}|${TZ}|g" \
    -e "s|\${OPCACHE_MEMORY_CONSUMPTION}|${OPCACHE_MEMORY_CONSUMPTION}|g" \
    -e "s|\${OPCACHE_INTERNED_STRINGS_BUFFER}|${OPCACHE_INTERNED_STRINGS_BUFFER}|g" \
    -e "s|\${OPCACHE_MAX_ACCELERATED_FILES}|${OPCACHE_MAX_ACCELERATED_FILES}|g" \
    -e "s|\${OPCACHE_REVALIDATE_FREQ}|${OPCACHE_REVALIDATE_FREQ}|g" \
    -e "s|\${OPCACHE_VALIDATE_TIMESTAMPS}|${OPCACHE_VALIDATE_TIMESTAMPS}|g" \
    -e "s|\${OPCACHE_HUGE_CODE_PAGES}|${OPCACHE_HUGE_CODE_PAGES}|g" \
    -e "s|\${OPCACHE_JIT_MODE}|${OPCACHE_JIT_MODE}|g" \
    -e "s|\${OPCACHE_JIT_BUFFER_SIZE}|${OPCACHE_JIT_BUFFER_SIZE}|g" \
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
