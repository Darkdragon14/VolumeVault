#!/bin/sh
set -eu

# Grant www-data access to the Docker socket for EVERY invocation — before the
# direct-command early return below — so split-deployment worker containers that
# run an artisan command directly (queue:work, schedule:work) can still reach
# Docker. Backups, restores and shoutrrr notifications all shell out to `docker`,
# and would otherwise fail silently in those containers.
docker_socket="${DOCKER_HOST:-unix:///var/run/docker.sock}"
case "$docker_socket" in
    unix://*) docker_socket="${docker_socket#unix://}" ;;
    *) docker_socket="" ;;
esac

if [ -n "$docker_socket" ] && [ -S "$docker_socket" ]; then
    docker_gid="$(stat -c '%g' "$docker_socket")"

    if ! getent group "$docker_gid" >/dev/null 2>&1; then
        addgroup -g "$docker_gid" docker-socket >/dev/null 2>&1 || true
    fi

    docker_group="$(getent group "$docker_gid" | cut -d: -f1 || true)"

    if [ -n "$docker_group" ]; then
        addgroup www-data "$docker_group" >/dev/null 2>&1 || true
    fi
fi

if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "volumevault:agent" ]; then
    mkdir -p /app/storage/app/agent /app/storage/app/docker-cli /app/storage/logs /app/bootstrap/cache
    chown -R www-data:www-data /app/storage/app/agent /app/storage/app/docker-cli /app/storage/logs /app/bootstrap/cache
    exec /command/s6-setuidgid www-data "$@"
fi

if [ "${1:-/init}" != "/init" ]; then
    exec "$@"
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p \
    /app/storage/database \
    /app/storage/framework/cache/data \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs \
    /app/bootstrap/cache

touch /app/storage/database/database.sqlite
chown -R www-data:www-data /app/storage /app/bootstrap/cache

case "${VOLUMEVAULT_AGENTS_ENABLED:-false}" in
    true|1|\(true\))
        /command/s6-setuidgid www-data php artisan volumevault:agent-tls:prepare
        export SSL_MODE=mixed
        export SSL_CERTIFICATE_FILE=/app/storage/app/private/agent-tls/server.crt
        export SSL_PRIVATE_KEY_FILE=/app/storage/app/private/agent-tls/server.key
        if [ ! -s "$SSL_CERTIFICATE_FILE" ] || [ ! -s "$SSL_PRIVATE_KEY_FILE" ]; then
            echo "Agent TLS certificate files are unavailable." >&2
            exit 1
        fi
        ;;
esac

export SERVERSIDEUP_DEFAULT_COMMAND=true
export S6_INITIALIZED=true
export DOCKER_CMD="$*"

find /etc/entrypoint.d/ -type f -name '*.sh' | sort -V | while IFS= read -r script; do
    sh "$script"
done

if [ "${VOLUMEVAULT_MIGRATIONS_ENABLED:-true}" = "true" ]; then
    /command/s6-setuidgid www-data php artisan migrate --force
fi

# Recover runs orphaned by a crash/restart on every boot, regardless of the
# scheduler's health. The command checks container liveness, so a backup whose
# container is still running (only the VolumeVault container restarted) is left
# untouched, while genuinely dead runs are failed and their stopped application
# containers restarted. Never block boot if Docker or the database is briefly
# unavailable.
/command/s6-setuidgid www-data php artisan volumevault:reconcile-stale-runs || true

exec /init
