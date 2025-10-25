#!/bin/bash
set -e

# Docker entrypoint for PHP-FPM container
# Substitutes environment variables into PHP configuration files

echo "Starting AFS-MappingXT PHP-FPM container..."

# Set defaults for environment variables if not provided
export PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-256M}"
export PHP_MAX_EXECUTION_TIME="${PHP_MAX_EXECUTION_TIME:-300}"
export TZ="${TZ:-Europe/Berlin}"

echo "Configuring PHP with:"
echo "  - PHP_MEMORY_LIMIT: ${PHP_MEMORY_LIMIT}"
echo "  - PHP_MAX_EXECUTION_TIME: ${PHP_MAX_EXECUTION_TIME}"
echo "  - TZ: ${TZ}"

# Update php.ini with environment variables
cat > /usr/local/etc/php/conf.d/custom.ini << EOF
; Custom PHP Configuration for AFS-MappingXT
; Optimized for performance and security
; Environment variables are substituted at container startup

[PHP]
; Error handling
display_errors = Off
display_startup_errors = Off
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
log_errors = On
error_log = /var/log/php-errors.log

; Resource limits (configured via environment variables)
max_execution_time = ${PHP_MAX_EXECUTION_TIME}
max_input_time = ${PHP_MAX_EXECUTION_TIME}
memory_limit = ${PHP_MEMORY_LIMIT}
post_max_size = 50M
upload_max_filesize = 50M

; Date/Time (configured via environment variable)
date.timezone = ${TZ}

; OPcache configuration for optimal performance
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 2
opcache.fast_shutdown = 1
opcache.enable_file_override = 1

; JIT (Just-In-Time) compilation for PHP 8.0+
; tracing JIT for best performance
opcache.jit = tracing
opcache.jit_buffer_size = 128M

; Realpath cache optimization
realpath_cache_size = 4096K
realpath_cache_ttl = 600

; Session configuration
session.save_handler = files
session.save_path = "/tmp"
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.cookie_secure = 0
session.use_only_cookies = 1
session.cookie_lifetime = 0
session.gc_maxlifetime = 1440

; Security settings
expose_php = Off
allow_url_fopen = On
allow_url_include = Off

; Output buffering for better performance
output_buffering = 4096

; File uploads
file_uploads = On
upload_tmp_dir = /tmp

; YAML extension settings (if available)
yaml.decode_php = 0
yaml.output_canonical = 0
yaml.output_indent = 2
yaml.output_width = 80
EOF

# Update php-fpm.conf with environment variables
cat > /usr/local/etc/php-fpm.d/zz-custom.conf << EOF
; PHP-FPM Pool Configuration for AFS-MappingXT
; Optimized for Apache mpm_event
; Environment variables are substituted at container startup

[afs-mappingxt]

; Unix socket for better performance than TCP
listen = /run/php/php-fpm.sock

; Socket permissions
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process management: dynamic for better resource utilization
pm = dynamic

; Maximum number of child processes
pm.max_children = 50

; Number of child processes created on startup
pm.start_servers = 10

; Minimum number of spare server processes
pm.min_spare_servers = 5

; Maximum number of spare server processes  
pm.max_spare_servers = 15

; Maximum number of requests each child process should execute before respawning
; This helps prevent memory leaks from accumulating
pm.max_requests = 500

; Status page
pm.status_path = /fpm-status

; Ping page
ping.path = /fpm-ping
ping.response = pong

; Request timeout (configured via environment variable)
request_terminate_timeout = ${PHP_MAX_EXECUTION_TIME}s

; Slow log for requests taking longer than 10 seconds
slowlog = /var/log/php-fpm-slow.log
request_slowlog_timeout = 10s

; Environment variables - pass through to PHP scripts
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[TMP] = /tmp
env[TMPDIR] = /tmp
env[TEMP] = /tmp
env[AFS_MSSQL_HOST] = \$AFS_MSSQL_HOST
env[AFS_MSSQL_PORT] = \$AFS_MSSQL_PORT
env[AFS_MSSQL_DB] = \$AFS_MSSQL_DB
env[AFS_MSSQL_USER] = \$AFS_MSSQL_USER
env[AFS_MSSQL_PASS] = \$AFS_MSSQL_PASS

; PHP ini values (configured via environment variables)
php_admin_value[error_log] = /var/log/php-fpm-errors.log
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = ${PHP_MEMORY_LIMIT}
php_admin_value[post_max_size] = 50M
php_admin_value[upload_max_filesize] = 50M
php_admin_value[max_execution_time] = ${PHP_MAX_EXECUTION_TIME}
php_admin_value[max_input_time] = ${PHP_MAX_EXECUTION_TIME}

; Security: disable dangerous functions
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

; Access log for monitoring
access.log = /var/log/php-fpm-access.log
access.format = "%R - %u %t \"%m %r%Q%q\" %s %f %{mili}d %{kilo}M %C%%"

; Clear environment variables for security (except those explicitly allowed)
clear_env = no
EOF

echo "PHP configuration updated successfully"
echo "Starting PHP-FPM..."

# Execute the main container command
exec "$@"
