#!/bin/sh
set -eu

if [ "$(id -u)" = "0" ]; then
    docker_socket="${DOCKER_HOST:-unix:///var/run/docker.sock}"
    case "$docker_socket" in
        unix://*) docker_socket="${docker_socket#unix://}" ;;
        *) docker_socket="" ;;
    esac

    if [ -n "$docker_socket" ] && [ -S "$docker_socket" ]; then
        docker_gid="$(stat -c '%g' "$docker_socket")"
        if ! getent group "$docker_gid" >/dev/null 2>&1; then
            addgroup -g "$docker_gid" docker-socket
        fi
        docker_group="$(getent group "$docker_gid" | cut -d: -f1)"
        addgroup www-data "$docker_group"
    fi

    state_directory="${VOLUMEVAULT_AGENT_STATE_DIRECTORY:-/app/storage/app/agent}"
    mkdir -p "$state_directory/operations" /app/storage/app/agent /app/storage/app/docker-cli/logs /app/storage/logs /app/bootstrap/cache
    chmod 700 "$state_directory" "$state_directory/operations"
    chown -R www-data:www-data "$state_directory"
    chown -R www-data:www-data /app/storage/app/agent /app/storage/app/docker-cli /app/storage/logs /app/bootstrap/cache
    exec su-exec www-data "$@"
fi

exec "$@"
