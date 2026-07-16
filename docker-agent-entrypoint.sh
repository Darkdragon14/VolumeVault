#!/bin/sh
set -eu

credential_path="${VOLUMEVAULT_AGENT_CREDENTIAL_PATH:-/app/storage/agent-token}"
credential_dir="$(dirname "$credential_path")"
mkdir -p "$credential_dir"
chown www-data:www-data "$credential_dir"

if [ -f "$credential_path" ]; then
    chown www-data:www-data "$credential_path"
    chmod 600 "$credential_path"
fi

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

exec su-exec www-data "$@"
