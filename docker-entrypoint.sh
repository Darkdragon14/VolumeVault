#!/bin/sh
set -eu

# Grant www-data access to the Docker socket for EVERY invocation — before the
# direct-command early return below — so split-deployment worker containers that
# run an artisan command directly (queue:work, schedule:work) can still reach
# Docker. Backups, restores and shoutrrr notifications all shell out to `docker`,
# and would otherwise fail silently in those containers.
if [ -S /var/run/docker.sock ]; then
    docker_gid="$(stat -c '%g' /var/run/docker.sock)"

    if ! getent group "$docker_gid" >/dev/null 2>&1; then
        addgroup -g "$docker_gid" docker-socket >/dev/null 2>&1 || true
    fi

    docker_group="$(getent group "$docker_gid" | cut -d: -f1 || true)"

    if [ -n "$docker_group" ]; then
        addgroup www-data "$docker_group" >/dev/null 2>&1 || true
    fi
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
