<?php

return [
    'backup_initiator_tracking' => [
        'title' => 'Track who triggered each backup',
        'description' => 'Backups now record which user started them. Manual runs (from the UI or the API) and whole-stack backups are attributed to the signed-in user, the safety backup taken before an in-place restore inherits the user who launched the restore, and scheduled runs stay unattributed. The initiator appears in the job\'s run history and on the backup run details, is included in backup notifications, and is available as a new {{ user }} token for custom notification templates.',
    ],
    'restore_history_on_job' => [
        'title' => 'Restore history on backup jobs',
        'description' => 'Each backup job page now splits its history into two tabs: "Run history" lists the job\'s backups, and a new "Restore history" tab lists every restore performed for that job — with status, mode, source and target volumes, start time, duration, and a link to the full restore details. Both tabs are now paginated, so long histories are no longer capped at 50 rows.',
    ],
    'restore_in_place_modes' => [
        'title' => 'In-place restore modes',
        'description' => 'The restore wizard can now restore a backup straight back into its source Docker volume. "Restore in place" wipes and replaces the volume after you retype its name to confirm; "Safe in-place restore" also stops the containers using the volume during the restore and restarts them afterwards. The backup picker now defaults to the selected job\'s archives, adds name and date filters, flags the latest archive, and a "Restore this backup" button opens the wizard straight from a backup run. Both in-place modes can optionally back up the volume\'s current contents before overwriting it, and the restore is aborted if that safety backup fails.',
    ],
    'restore_notifications' => [
        'title' => 'Restore notifications',
        'description' => 'VolumeVault now notifies you when a restore starts, succeeds, or fails, reusing the notification channels already configured on the backup job. Start and success messages are sent to channels set to receive every run, while failures reach every channel. A notification problem never interrupts the restore itself.',
    ],
    'stack_bulk_backup' => [
        'title' => 'Back up a whole stack at once',
        'description' => 'The Stacks page can now back up an entire stack in one click. Fully configured stacks get a "Run all jobs" button that queues a run for every job; stacks with uncovered volumes get a "Back up stack" dialog that creates a daily (or custom) backup job for each volume without one, then queues a run for the whole stack. The same operation is available through the API (POST /stacks/backup).',
    ],
    'busybox_restore_tar_compat' => [
        'title' => 'Compatible restore extraction',
        'description' => 'Restores to a new Docker volume no longer pass GNU-only tar options and now stream the archive into the restore container, so extraction works with BusyBox tar and containerized deployments whose storage lives in a Docker volume.',
    ],
    'stable_stack_volume_search' => [
        'title' => 'Stable stack and volume search',
        'description' => 'Typing in the stack or volume search now keeps the filter active instead of resetting after the URL is synchronized.',
    ],
    'backup_archive_name_templates' => [
        'title' => 'Custom backup archive names',
        'description' => 'Backup jobs can now define an archive name template using tokens such as {name}, {source}, {id}, {year}, {month}, {day}, and {time}. Existing jobs keep the previous volumevault-source-run-id naming until a template is configured, and the form warns when a template may overwrite earlier archives.',
    ],
    'russian_translation_revisions' => [
        'title' => 'Refined Russian interface text',
        'description' => 'Russian interface translations received additional wording fixes for better consistency and readability. Thanks to @artyomboyko for the translation contribution.',
    ],
    'complete_i18n_coverage' => [
        'title' => 'More complete interface translations',
        'description' => 'Many interface texts that were still shown in English — including the API tokens and installation-save pages — are now fully translated. All nine languages were synchronized and the missing translations filled in, so non-English users no longer see untranslated labels, buttons, and messages.',
    ],
    'reliable_run_logs' => [
        'title' => 'More reliable run logs',
        'description' => 'Backup and restore run logs are now appended atomically, so concurrent writers (for example a failed-job handler firing while a run finishes) can no longer overwrite each other\'s output. Log truncation is also UTF-8 aware, so trimmed logs stay valid and no longer break the run details view.',
    ],
    'stale_run_liveness_reconcile' => [
        'title' => 'Faster recovery of interrupted backups',
        'description' => 'Runs left stuck after a worker crash, timeout or restart are now recovered much faster. The reconciler checks whether the backup container is still alive instead of waiting a fixed delay, so dead runs are failed within minutes while genuinely long backups are left untouched. Recovery also runs automatically on container startup, restarting any application containers left stopped by an interrupted backup.',
    ],
    'local_destination_listing_cap' => [
        'title' => 'Bounded local destination listings',
        'description' => 'Listing backups on a local filesystem destination is now capped at 1000 entries, matching the other storage providers, so a destination with a very large archive directory no longer loads its whole tree into a single response.',
    ],
    'per_job_schedule_timezone' => [
        'title' => 'Per-job schedule timezone',
        'description' => 'Each backup job can now define its own timezone, so a schedule like "daily at 02:00" runs at 02:00 local time instead of in the global application timezone. Leave it on "Application default" to keep the previous behaviour.',
    ],
    'http_security_headers' => [
        'title' => 'HTTP security headers',
        'description' => 'Responses now include defense-in-depth security headers (X-Frame-Options, X-Content-Type-Options and Referrer-Policy), plus HSTS when served over HTTPS. Plain-HTTP and LAN deployments are unaffected — no request is ever forced from HTTP to HTTPS.',
    ],
    'local_destination_path_error_feedback' => [
        'title' => 'Clearer local destination path errors',
        'description' => 'Creating a local filesystem destination now surfaces path validation errors directly in the form — such as a path blocked by the host path allowlist — instead of silently returning to the create page.',
    ],
    'russian_translation_consistency' => [
        'title' => 'Refined Russian translations',
        'description' => 'Russian interface text was updated for better consistency, and the Russian translator glossary was moved out of the shipped locale files into dedicated project documentation. This keeps the bundled language resources cleaner while preserving the glossary for contributors. Thanks to @artyomboyko for the translation contribution.',
    ],
    'customizable_dashboard' => [
        'title' => 'Customizable dashboard',
        'description' => 'You can now choose which dashboard widgets to show and in which order. Click "Customize" on the dashboard to hide or show any statistic card or section, drag them to reorder, then click "Done" to save. Each user keeps their own layout, and "Reset to defaults" restores the original arrangement.',
    ],
    'self_container_backup_guard' => [
        'title' => 'VolumeVault no longer stops its own container during a backup',
        'description' => 'When a backup job has "stop containers before backup" enabled and targets a volume that the VolumeVault container itself also mounts, VolumeVault no longer stops its own container - which would have killed the running backup. The container is auto-detected from its hostname and cgroup; set VOLUMEVAULT_CONTAINER_ID or VOLUMEVAULT_CONTAINER_NAME if autodetection is unreliable (custom hostname or host networking).',
    ],
    'host_path_stop_containers' => [
        'title' => 'Stop selected containers for host path backups',
        'description' => 'Host path backup jobs can now stop containers before the backup and restart them afterwards, just like Docker volume jobs already could. Because a host path cannot be mapped to containers automatically, you pick them by name in the job form. The selection is stored by name so it survives container recreation; containers that no longer exist or are already stopped are skipped, and VolumeVault never stops its own container.',
    ],
    'ssrf_destination_guard' => [
        'title' => 'Private-IP backup destinations are now guarded (SSRF)',
        'description' => 'VolumeVault now refuses by default to connect to a backup destination whose host resolves to a private, loopback or link-local address (including the cloud metadata endpoint 169.254.169.254). This only affects destinations on a private IP, such as a LAN NAS or self-hosted S3/MinIO - cloud destinations on a public URL are unaffected. Scheduled backups still run, but the destination test, restore (listing and download) and the storage-quota alert are blocked until you list the destination\'s range in VOLUMEVAULT_SSRF_ALLOWED_IPS (comma-separated CIDRs, e.g. 192.168.1.0/24). Notification channels are not guarded.',
    ],
    'host_path_allowlist_fail_closed' => [
        'title' => 'Host path allowlist is now fail-closed',
        'description' => 'VOLUMEVAULT_HOST_PATH_ALLOWLIST now denies by default: when it is empty, host-path backup sources and local destinations are refused instead of any path being allowed. The same allowlist now also protects local destinations, and paths are re-checked at run time to block symlink swaps. Existing installations that relied on the previous open default must list their paths - run "php artisan volumevault:host-path-allowlist:audit" for the exact value to set.',
    ],
    'auth_rate_limiting' => [
        'title' => 'Rate-limited sign-in and password reset',
        'description' => 'Sign-in and password-reset requests are now rate-limited to 5 attempts per minute, slowing down brute-force attempts against the admin password. Going over the limit returns a temporary "too many requests" response that clears after a minute.',
    ],
    'restore_input_hardening' => [
        'title' => 'Stricter restore and backup input validation',
        'description' => 'The backup selected for a restore must now match the destination listing, blocking path-traversal keys such as "../../etc/passwd". Docker volume names are limited to safe characters, and restore extraction is confined so a forged archive cannot write outside the target volume.',
    ],
    'sftp_host_key_pinning' => [
        'title' => 'SSH host key pinning for SFTP destinations',
        'description' => 'SSH/SFTP destinations can now pin the server host key to block man-in-the-middle attacks. Use the "Fetch key from server" button - or the new POST /api/v1/destinations/host-key endpoint - to trust the key a server presents, or paste a host key or SHA256 fingerprint. The key is verified before any credentials are sent, for VolumeVault\'s own SFTP operations (test, listing, restore). Leaving it empty keeps the previous behaviour.',
    ],
    'api_token_expiration' => [
        'title' => 'API tokens now expire by default',
        'description' => 'API tokens now expire 60 days after creation by default, limiting the impact of a leaked token. Existing tokens older than this stop working after the upgrade and must be recreated. Set SANCTUM_TOKEN_EXPIRATION (in minutes) to change the window, or to null to keep non-expiring tokens. A per-token expiry can only shorten this window, never extend it.',
    ],
    'alert_check_isolation' => [
        'title' => 'More resilient alert checks',
        'description' => 'A single alert rule that errors out no longer stops the other rules from being checked. Each rule is now evaluated independently and failures are logged, so one misbehaving check can no longer silently disable your remaining alerts.',
    ],
    'restore_volume_cleanup' => [
        'title' => 'Cleaner retries after a failed restore',
        'description' => 'When a restore fails after creating its target volume, VolumeVault now removes the partially-created volume so the next retry starts clean instead of being blocked by an "already exists" error.',
    ],
    'schedule_drift_prevention' => [
        'title' => 'More reliable backup scheduling',
        'description' => 'Scheduled backups no longer skip an occurrence when a worker falls behind. The next run is now anchored to the planned slot instead of the previous run\'s finish time, so a slow or delayed run can no longer cause the schedule to drift.',
    ],
    'destination_usage_efficiency' => [
        'title' => 'More efficient destination storage usage',
        'description' => 'Storage usage for backup destinations is now measured by streaming through the objects instead of loading the whole listing into memory, and SFTP connections are always closed afterwards. Destinations holding many backups are measured more reliably, without exhausting memory or leaking connections.',
    ],
    'run_log_integrity' => [
        'title' => 'More reliable run logs',
        'description' => 'Backup and restore run logs are now appended atomically, so concurrent updates - such as an error message and a container-restart notice - no longer overwrite each other. Logs are also capped in size, keeping the most recent output instead of growing without limit.',
    ],
    'stale_run_reconciliation' => [
        'title' => 'Automatic recovery of interrupted runs',
        'description' => 'Backup and restore runs interrupted by a worker crash, timeout, or restart are now automatically marked as failed instead of staying stuck, so scheduled backups keep running. Application containers stopped for a backup are also restarted automatically if a crash left them down.',
    ],
    'advanced_alerting' => [
        'title' => 'Advanced alerting',
        'description' => 'VolumeVault can monitor backup jobs for stale backups, repeated failures, long-running error states, and unusual archive sizes.',
    ],
    'destination_storage_limit_alerts' => [
        'title' => 'Destination storage limit alerts',
        'description' => 'Backup destinations can now define absolute warning and critical storage thresholds with dedicated alert notification channels.',
    ],
    'mobile_navigation_redesign' => [
        'title' => 'Improved mobile navigation',
        'description' => 'The mobile header now uses a compact menu button and a structured navigation panel instead of stacking every link in the header.',
    ],
    'keyboard_shortcuts' => [
        'title' => 'Keyboard shortcuts',
        'description' => 'On desktop, use Ctrl+K for quick navigation, g-prefixed shortcuts for views, and / to focus list search.',
    ],
    'in_app_update_summaries' => [
        'title' => 'In-app update summaries',
        'description' => 'VolumeVault can now show users what changed after an application update.',
    ],
    'available_update_checks' => [
        'title' => 'Available update checks',
        'description' => 'VolumeVault can now indicate when a newer GitHub release is available.',
    ],
    'backup_job_detail_deletion' => [
        'title' => 'Backup job detail deletion',
        'description' => 'Backup jobs can now be deleted directly from their detail page.',
    ],
    'per_job_notification_channels' => [
        'title' => 'Per-job notification channels',
        'description' => 'Backup jobs can now choose which active notification channels receive their results.',
    ],
    'notification_defaults_migration' => [
        'title' => 'Notification defaults migration',
        'description' => 'This release adds notification settings to backup jobs and default-channel tracking to notification channels.',
    ],
    'host_path_backup_sources' => [
        'title' => 'Host path backup sources',
        'description' => 'Admins can back up selected directories from the Docker host alongside Docker volumes.',
    ],
    'host_path_safety_controls' => [
        'title' => 'Host path safety controls',
        'description' => 'Host paths are mounted read-only and can be restricted with VOLUMEVAULT_HOST_PATH_ALLOWLIST.',
    ],
    'stack_backup_coverage' => [
        'title' => 'Stack backup coverage',
        'description' => 'Docker volumes are grouped by Compose or Swarm stack with backup coverage states.',
    ],
    'backup_archive_metadata' => [
        'title' => 'Backup archive metadata',
        'description' => 'Successful runs can now show archive keys and sizes when destination metadata is available.',
    ],
    'trusted_proxy_support' => [
        'title' => 'Trusted proxy support',
        'description' => 'VolumeVault can trust configured reverse proxies so generated URLs use the public HTTPS scheme.',
    ],
    'cleaner_docker_volume_sync' => [
        'title' => 'Cleaner Docker volume sync',
        'description' => 'Sync now removes stale missing volume records that are no longer referenced by backup jobs.',
    ],
    'list_search_and_filters' => [
        'title' => 'List search and filters',
        'description' => 'Volumes and backup jobs gained search, filters, and a searchable volume selector.',
    ],
    'php_85_container_runtime' => [
        'title' => 'PHP 8.5 container runtime',
        'description' => 'The container moved to the ServerSideUp PHP 8.5 runtime with supervised queue and scheduler services.',
    ],
    'first_stable_release' => [
        'title' => 'First stable release',
        'description' => 'VolumeVault launched with scheduled backups, safe restores, encrypted destinations, notifications, users, API tokens, and installation saves.',
    ],
    'pagination_with_user_preference' => [
        'title' => 'Paginated lists with per-page preference',
        'description' => 'All list views now support pagination with configurable items per page (10, 20, 50, 100, or All). You can set your default in Profile settings.',
    ],
    'dark_pagination_menu' => [
        'title' => 'Dark pagination menu',
        'description' => 'The items-per-page dropdown now keeps a dark theme palette when its menu is open, improving contrast in paginated list views.',
    ],
    'filter_toolbar_action_buttons' => [
        'title' => 'Refreshed primary buttons',
        'description' => 'Primary action buttons now share the same outlined sky style in both light and dark mode across the application.',
    ],
    'shareable_filter_urls' => [
        'title' => 'Shareable filter URLs',
        'description' => 'List filters on Volumes, Stacks, Backup Jobs, and Alerts are now reflected in the URL so you can copy and share filtered views directly.',
    ],
    'safer_default_environment_settings' => [
        'title' => 'Safer default environment settings',
        'description' => '.env.example now defaults new deployments to APP_ENV=production and APP_DEBUG=false. It also adds guidance for SESSION_SECURE_COOKIE so HTTPS deployments can enable secure cookies without accidentally breaking HTTP-only setups.',
    ],
];
