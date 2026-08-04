#!/bin/sh
set -eu

cat > /usr/local/etc/php/conf.d/runtime-limits.ini <<EOF
upload_max_filesize=${PHP_UPLOAD_MAX_FILESIZE:-20M}
post_max_size=${PHP_POST_MAX_SIZE:-25M}
memory_limit=${PHP_MEMORY_LIMIT:-512M}
max_execution_time=${PHP_MAX_EXECUTION_TIME:-180}
EOF

mkdir -p /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache
chown -R www-data:www-data /app/storage /app/bootstrap/cache
chmod -R ug+rwX /app/storage /app/bootstrap/cache

if [ "${1:-}" = "apache2-foreground" ]; then
    port=${PORT:-8000}
    printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
    sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf
fi

exec "$@"
