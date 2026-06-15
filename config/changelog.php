<?php

return [
    'unreleased' => [
        [
            'type' => 'feature',
            'key' => 'restore_in_place_modes',
        ],
        [
            'type' => 'feature',
            'key' => 'restore_notifications',
        ],
    ],
    'releases' => [
        'v1.13.0' => [
            'date' => '2026-06-15',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.13.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'stack_bulk_backup',
                ],
            ],
        ],
        'v1.12.1' => [
            'date' => '2026-06-14',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.12.1',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'busybox_restore_tar_compat',
                ],
                [
                    'type' => 'change',
                    'key' => 'stable_stack_volume_search',
                ],
            ],
        ],
        'v1.12.0' => [
            'date' => '2026-06-13',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.12.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'backup_archive_name_templates',
                ],
                [
                    'type' => 'change',
                    'key' => 'russian_translation_revisions',
                ],
            ],
        ],
        'v1.11.1' => [
            'date' => '2026-06-13',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.11.1',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'complete_i18n_coverage',
                ],
            ],
        ],
        'v1.11.0' => [
            'date' => '2026-06-12',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.11.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'per_job_schedule_timezone',
                ],
                [
                    'type' => 'change',
                    'key' => 'local_destination_path_error_feedback',
                ],
                [
                    'type' => 'change',
                    'key' => 'reliable_run_logs',
                ],
                [
                    'type' => 'change',
                    'key' => 'stale_run_liveness_reconcile',
                ],
                [
                    'type' => 'change',
                    'key' => 'local_destination_listing_cap',
                ],
                [
                    'type' => 'change',
                    'key' => 'http_security_headers',
                ],
            ],
        ],
        'v1.10.1' => [
            'date' => '2026-06-11',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.10.1',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'russian_translation_consistency',
                ],
            ],
        ],
        'v1.10.0' => [
            'date' => '2026-06-11',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.10.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'customizable_dashboard',
                ],
            ],
        ],
        'v1.9.0' => [
            'date' => '2026-06-10',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.9.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'host_path_stop_containers',
                ],
                [
                    'type' => 'change',
                    'key' => 'self_container_backup_guard',
                ],
            ],
        ],
        'v1.8.0' => [
            'date' => '2026-06-09',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.8.0',
            'items' => [
                [
                    'type' => 'breaking',
                    'key' => 'host_path_allowlist_fail_closed',
                ],
                [
                    'type' => 'breaking',
                    'key' => 'api_token_expiration',
                ],
                [
                    'type' => 'breaking',
                    'key' => 'ssrf_destination_guard',
                ],
                [
                    'type' => 'feature',
                    'key' => 'sftp_host_key_pinning',
                ],
                [
                    'type' => 'change',
                    'key' => 'auth_rate_limiting',
                ],
                [
                    'type' => 'change',
                    'key' => 'restore_input_hardening',
                ],
            ],
        ],
        'v1.7.3' => [
            'date' => '2026-06-08',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.7.3',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'alert_check_isolation',
                ],
                [
                    'type' => 'change',
                    'key' => 'stale_run_reconciliation',
                ],
                [
                    'type' => 'change',
                    'key' => 'restore_volume_cleanup',
                ],
                [
                    'type' => 'change',
                    'key' => 'schedule_drift_prevention',
                ],
                [
                    'type' => 'change',
                    'key' => 'destination_usage_efficiency',
                ],
                [
                    'type' => 'change',
                    'key' => 'run_log_integrity',
                ],
                [
                    'type' => 'change',
                    'key' => 'safer_default_environment_settings',
                ],
            ],
        ],
        'v1.7.2' => [
            'date' => '2026-06-07',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.7.2',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'shareable_filter_urls',
                ],
            ],
        ],
        'v1.7.1' => [
            'date' => '2026-06-06',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.7.1',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'dark_pagination_menu',
                ],
                [
                    'type' => 'change',
                    'key' => 'filter_toolbar_action_buttons',
                ],
            ],
        ],
        'v1.7.0' => [
            'date' => '2026-06-06',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.7.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'advanced_alerting',
                ],
                [
                    'type' => 'feature',
                    'key' => 'destination_storage_limit_alerts',
                ],
                [
                    'type' => 'feature',
                    'key' => 'pagination_with_user_preference',
                ],
                [
                    'type' => 'change',
                    'key' => 'mobile_navigation_redesign',
                ],
            ],
        ],
        'v1.6.0' => [
            'date' => '2026-05-29',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.6.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'keyboard_shortcuts',
                ],
            ],
        ],
        'v1.5.0' => [
            'date' => '2026-05-28',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.5.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'in_app_update_summaries',
                ],
                [
                    'type' => 'feature',
                    'key' => 'available_update_checks',
                ],
                [
                    'type' => 'change',
                    'key' => 'backup_job_detail_deletion',
                ],
            ],
        ],
        'v1.4.0' => [
            'date' => '2026-05-27',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.4.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'per_job_notification_channels',
                ],
                [
                    'type' => 'migration',
                    'key' => 'notification_defaults_migration',
                ],
            ],
        ],
        'v1.3.0' => [
            'date' => '2026-05-26',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.3.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'host_path_backup_sources',
                ],
                [
                    'type' => 'change',
                    'key' => 'host_path_safety_controls',
                ],
            ],
        ],
        'v1.2.0' => [
            'date' => '2026-05-24',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.2.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'stack_backup_coverage',
                ],
                [
                    'type' => 'change',
                    'key' => 'backup_archive_metadata',
                ],
            ],
        ],
        'v1.1.2' => [
            'date' => '2026-05-17',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.1.2',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'trusted_proxy_support',
                ],
            ],
        ],
        'v1.1.1' => [
            'date' => '2026-05-08',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.1.1',
            'items' => [
                [
                    'type' => 'change',
                    'key' => 'cleaner_docker_volume_sync',
                ],
            ],
        ],
        'v1.1.0' => [
            'date' => '2026-05-08',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.1.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'list_search_and_filters',
                ],
                [
                    'type' => 'change',
                    'key' => 'php_85_container_runtime',
                ],
            ],
        ],
        'v1.0.0' => [
            'date' => '2026-05-06',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.0.0',
            'items' => [
                [
                    'type' => 'feature',
                    'key' => 'first_stable_release',
                ],
            ],
        ],
    ],
];
