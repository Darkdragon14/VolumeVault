<?php

return [
    'mode' => env('VOLUMEVAULT_MODE', 'hybrid'),
    'archive_relay' => [
        // Central chunks are APP_KEY-encrypted; losing APP_KEY makes them unreadable.
        // Byte limits: reserve 3 * max_bytes per relay for encryption and local staging.
        // Source staging requires 2 * max_bytes free; agent staging is private plaintext.
        // Expiry stops new uploads/assignments, never discards an assigned agent's cleanup.
        'directory' => storage_path('app/private/archive-relays'),
        'max_bytes' => (int) env('VOLUMEVAULT_ARCHIVE_RELAY_MAX_BYTES', 10737418240),
        'max_disk_bytes' => (int) env('VOLUMEVAULT_ARCHIVE_RELAY_MAX_DISK_BYTES', 53687091200),
        'ttl_seconds' => (int) env('VOLUMEVAULT_ARCHIVE_RELAY_TTL_SECONDS', 86400),
    ],
    'agents' => [
        'enabled' => (bool) env('VOLUMEVAULT_AGENTS_ENABLED', false),
        'url' => rtrim((string) env('VOLUMEVAULT_AGENT_URL', ''), '/'),
        'tls_directory' => storage_path('app/private/agent-tls'),
        'image' => trim((string) env('VOLUMEVAULT_AGENT_IMAGE', '')) ?: 'ghcr.io/darkdragon14/volumevault-agent:'.(env('APP_VERSION', 'main') === 'main' ? 'latest' : env('APP_VERSION')),
        'client' => [
            'url' => env('VOLUMEVAULT_ORCHESTRATOR_URL', ''),
            'enrollment_token' => env('VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN', ''),
            'ca_certificate' => env('VOLUMEVAULT_AGENT_CA', ''),
            'state_directory' => env('VOLUMEVAULT_AGENT_STATE_DIRECTORY', storage_path('app/agent')),
        ],
    ],

    'docker_host' => env('DOCKER_HOST', 'unix:///var/run/docker.sock'),
    'docker_network' => trim((string) env('VOLUMEVAULT_DOCKER_NETWORK', '')),

    'host_path_allowlist' => array_values(array_filter(array_map(
        fn (string $path): string => trim($path),
        explode(',', env('VOLUMEVAULT_HOST_PATH_ALLOWLIST', ''))
    ))),

    'ssrf' => [
        // CIDR ranges that re-authorise otherwise-blocked (private, loopback or
        // link-local) backup destinations. Only relevant when a DESTINATION is
        // on a private IP (LAN NAS, self-hosted S3/MinIO, LAN SFTP); cloud URLs
        // are unaffected. Deny-by-default: such hosts are blocked for the test
        // button, restore (listing + download) and the storage-quota alert
        // unless listed here. Scheduled backups themselves are not guarded.
        // e.g. VOLUMEVAULT_SSRF_ALLOWED_IPS=192.168.1.0/24,10.0.0.0/8
        'allowed_ips' => array_values(array_filter(array_map(
            fn (string $cidr): string => trim($cidr),
            explode(',', (string) env('VOLUMEVAULT_SSRF_ALLOWED_IPS', ''))
        ))),
    ],

    'self_container' => [
        // Identifiers (id or name) of the VolumeVault container itself. Used to
        // skip it when a backup job targets a volume it also mounts, so the
        // backup never stops the container running it. Hostname and /proc are
        // auto-detected; set these only when autodetection is unreliable (custom
        // --hostname, host networking).
        'id' => trim((string) env('VOLUMEVAULT_CONTAINER_ID', '')),
        'name' => trim((string) env('VOLUMEVAULT_CONTAINER_NAME', '')),
    ],

    'update_check' => [
        'enabled' => (bool) env('VOLUMEVAULT_UPDATE_CHECK_ENABLED', true),
        'cache_ttl_seconds' => (int) env('VOLUMEVAULT_UPDATE_CHECK_CACHE_TTL', 43200),
        'github_api_url' => env('VOLUMEVAULT_UPDATE_CHECK_URL', 'https://api.github.com/repos/Darkdragon14/VolumeVault/releases/latest'),
    ],

    'run_logs' => [
        // Maximum size (in bytes) kept in a run's `logs` column. When exceeded,
        // the oldest output is dropped so the most recent lines (errors usually
        // surface last) are preserved. Set to 0 to disable the cap.
        'max_bytes' => (int) env('VOLUMEVAULT_RUN_LOG_MAX_BYTES', 262144),
    ],

    'alerts' => [
        'enabled' => (bool) env('VOLUMEVAULT_ALERTS_ENABLED', true),
        'defaults' => [
            'check_interval_minutes' => 60,
            'cooldown_minutes' => 1440,
            'reminder_enabled' => false,
            'backup_too_old_days' => 7,
            'job_never_succeeded_min_runs' => 3,
            'job_in_error_days' => 3,
            'backup_size_out_of_range_min_bytes' => 1024,
            'backup_size_out_of_range_max_bytes' => 10737418240,
        ],
    ],
];
