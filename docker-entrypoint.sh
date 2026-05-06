#!/bin/sh
set -eu

if [ "${1:-supervisord}" != "supervisord" ]; then
    exec "$@"
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p \
    /app/storage/database \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs \
    /app/bootstrap/cache

touch /app/storage/database/database.sqlite

php artisan migrate --force

exec supervisord -c /etc/supervisord.conf
