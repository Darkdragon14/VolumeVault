<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use App\Models\NotificationChannel;
use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'VolumeVault API',
                'version' => config('app.version'),
                'description' => 'External JSON API for VolumeVault Docker volume backups and restores.',
            ],
            'servers' => [
                ['url' => url('/api/v1')],
            ],
            'security' => [['bearerAuth' => []]],
            'paths' => $this->paths(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum personal access token',
                    ],
                ],
                'schemas' => $this->schemas(),
            ],
        ]);
    }

    private function hostScopeParameter(): array
    {
        return ['name' => 'docker_host_id', 'in' => 'query', 'required' => false, 'description' => 'Existing host ID; omit for all hosts. Local host is 1. Restore lists filter by target host.', 'schema' => ['type' => 'integer', 'minimum' => 1]];
    }

    private function paths(): array
    {
        return [
            '/openapi.json' => [
                'get' => $this->operation('Read the OpenAPI document.', [], public: true),
            ],
            '/me' => ['get' => $this->operation('Inspect current authenticated user and token.', ['read'])],
            '/settings/docker-label-backups' => [
                'get' => $this->operation('Read Docker label settings, eligible destinations, notification channels and hosts. Omitting docker_host_id selects local host 1.', ['read'], admin: true, queryParameters: [
                    ['name' => 'docker_host_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                ]),
                'put' => $this->operation('Update host-scoped Docker label settings. Remote reconciliation requires a complete docker-labels-v1 inventory; execution requires backup-v1. Destinations must be shared or owned by the selected host.', ['write'], ['$ref' => '#/components/schemas/DockerLabelBackupSettingsRequest'], admin: true),
            ],
            '/dashboard' => ['get' => $this->operation('Read dashboard stats across all hosts by default. Group aggregates describe whole groups with a member in scope. Safe hosts and filters are inside data.', ['read'], queryParameters: [$this->hostScopeParameter()])],
            '/volumes' => ['get' => $this->operation('List accepted Docker volume inventory across all hosts by default. Identities combine host ID and volume name. Includes safe hosts and filters alongside data.', ['read'], queryParameters: [$this->hostScopeParameter()])],
            '/stacks' => ['get' => $this->operation('List host-qualified stacks derived from stored Compose/Swarm volume labels. Remote stack backup is unsupported; complete container labels and mounts are not persisted.', ['read'], queryParameters: [$this->hostScopeParameter()])],
            '/host-path-allowlist' => ['get' => $this->operation('Read the configured host-path allowlist (prefixes that host-path backup sources and local destinations may use). Empty/not configured means host paths are refused (fail-closed).', ['read'], null, false, true)],
            '/volumes/sync' => ['post' => $this->volumeSyncOperation()],
            '/stacks/backup' => ['post' => $this->operation('Back up a whole stack at once. For every Docker volume in the stack that has no backup job yet, a job is created using the given destination and schedule; then a manual run is queued for every Docker-volume job in the stack. When the stack is already fully configured, omit destination/schedule to just queue a run for every job. Volumes whose job belongs to a backup group are reported under "grouped" and are not run here — they back up on their group\'s own schedule. The 202 response is { data: { created, queued, skipped, grouped } }.', ['write'], ['$ref' => '#/components/schemas/StackBackupRequest'], false, true, 202)],
            '/backup-jobs' => [
                'get' => $this->operation('List backup jobs.', ['read'], queryParameters: [
                    [
                        'name' => 'sort',
                        'in' => 'query',
                        'schema' => ['type' => 'string', 'enum' => ['created_at', 'name', 'next_run_at', 'last_run_at'], 'default' => 'created_at'],
                    ],
                    [
                        'name' => 'direction',
                        'in' => 'query',
                        'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                    ],
                    $this->hostScopeParameter(),
                ]),
                'post' => $this->operation('Create a backup job.', ['write'], ['$ref' => '#/components/schemas/BackupJobRequest'], false, true, 201),
            ],
            '/backup-jobs/{id}' => [
                'get' => $this->operation('Read a backup job and recent runs.', ['read'], null, true),
                'put' => $this->operation('Update a backup job.', ['write'], ['$ref' => '#/components/schemas/BackupJobRequest'], true, true),
                'delete' => $this->operation('Delete a backup job.', ['write'], null, true, true, 204),
            ],
            '/backup-jobs/{id}/run' => ['post' => $this->operation('Queue a manual backup run.', ['write'], null, true, true, 202)],
            '/backup-jobs/{id}/pause' => ['post' => $this->operation('Pause a backup job.', ['write'], ['$ref' => '#/components/schemas/PauseRequest'], true, true, bodyRequired: false)],
            '/backup-jobs/{id}/resume' => ['post' => $this->operation('Resume a backup job.', ['write'], null, true, true)],
            '/backup-jobs/{id}/backups' => ['get' => $this->operation('List backup objects available for restore.', ['read'], null, true, true, queryParameters: [$this->backupRunIdParameter()])],
            '/backup-jobs/{id}/restore' => ['post' => $this->operation('Queue a restore run.', ['write'], ['$ref' => '#/components/schemas/RestoreRequest'], true, true, 202)],
            '/backup-groups' => [
                'get' => $this->operation('List backup groups.', ['read']),
                'post' => $this->operation('Create a backup group. The group owns the schedule, notifications and failure policy for its member jobs; attach jobs to it by creating or updating a backup job with planning_mode=group.', ['write'], ['$ref' => '#/components/schemas/BackupJobGroupRequest'], false, true, 201),
            ],
            '/backup-groups/{id}' => [
                'get' => $this->operation('Read a backup group with its member jobs and recent group runs.', ['read'], null, true),
                'put' => $this->operation('Update a backup group. Member jobs inherit the updated schedule.', ['write'], ['$ref' => '#/components/schemas/BackupJobGroupRequest'], true, true),
                'delete' => $this->operation('Delete a backup group. Fails while it still has member jobs; move them back to standalone first.', ['write'], null, true, true, 204),
            ],
            '/backup-groups/{id}/run' => ['post' => $this->operation('Queue a manual group run: every active member volume is backed up and the group emits a single start and success/fail notification.', ['write'], null, true, true, 202)],
            '/backup-groups/{id}/pause' => ['post' => $this->operation('Pause a backup group.', ['write'], ['$ref' => '#/components/schemas/PauseRequest'], true, true, bodyRequired: false)],
            '/backup-groups/{id}/resume' => ['post' => $this->operation('Resume a backup group.', ['write'], null, true, true)],
            '/backup-groups/{id}/notifications' => ['patch' => $this->operation('Enable or disable a backup group\'s notifications.', ['write'], ['$ref' => '#/components/schemas/ToggleNotificationsRequest'], true, true)],
            '/backup-group-runs' => ['get' => $this->operation('List recent backup group runs.', ['read'])],
            '/backup-group-runs/{id}' => ['get' => $this->operation('Read a backup group run with its per-volume member runs.', ['read'], null, true)],
            '/backup-runs' => ['get' => $this->operation('List recent backup runs scoped by historical execution host.', ['read'], queryParameters: [$this->hostScopeParameter()], response: ['$ref' => '#/components/schemas/BackupRunCollectionResponse'])],
            '/backup-runs/{id}' => ['get' => $this->operation('Read backup run details and logs.', ['read'], null, true, response: ['$ref' => '#/components/schemas/BackupRunResponse'])],
            '/restore-runs' => ['get' => $this->operation('List recent restore runs scoped by target host. Source and target summaries are returned separately.', ['read'], queryParameters: [$this->hostScopeParameter()])],
            '/restore-runs/{id}' => ['get' => $this->operation('Read restore run details and logs.', ['read'], null, true)],
            '/destinations' => [
                'get' => $this->operation('List backup destinations without plaintext secrets.', ['read'], null, false, true),
                'post' => $this->operation('Create a backup destination.', ['write'], ['$ref' => '#/components/schemas/DestinationCreateRequest'], false, true, 201),
            ],
            '/destinations/{id}' => [
                'get' => $this->operation('Read one backup destination without plaintext secrets.', ['read'], null, true, true),
                'put' => $this->operation('Update a backup destination.', ['write'], ['$ref' => '#/components/schemas/DestinationUpdateRequest'], true, true),
                'delete' => $this->operation('Delete a backup destination.', ['write'], null, true, true, 204),
            ],
            '/destinations/{id}/test' => ['post' => $this->operation('Test a backup destination.', ['write'], null, true, true)],
            '/destinations/host-key' => ['post' => $this->operation('Read the SSH host key a server presents, to pin it as settings.host_key (trust on first use). Connects without authenticating.', ['write'], ['$ref' => '#/components/schemas/HostKeyRequest'], false, true)],
            '/notifications' => ['get' => $this->operation('List notification channels without plaintext URLs.', ['read'], null, false, true)],
            '/notifications/{id}' => [
                'get' => $this->operation('Read one notification channel without plaintext URL.', ['read'], null, true, true),
                'put' => $this->operation('Update a notification channel.', ['write'], ['$ref' => '#/components/schemas/NotificationChannelUpdateRequest'], true, true),
            ],
            '/notifications/{id}/test' => ['post' => $this->operation('Send a notification test.', ['write'], null, true, true)],
        ];
    }

    private function volumeSyncOperation(): array
    {
        $operation = $this->operation(
            'Synchronize local inventory and return counts (200). An omitted body or host defaults to local host 1. Opt into queued refresh with async=true and explicit docker_host_id=1 (202). Both execution paths use the shared synchronization lock. Local execution must be enabled; remote snapshots refresh through agent polling.',
            ['write'],
            ['type' => 'object', 'properties' => [
                'docker_host_id' => ['type' => 'integer', 'enum' => [1], 'default' => 1, 'description' => 'Required when async is true.'],
                'async' => ['type' => 'boolean', 'default' => false],
            ], 'allOf' => [['if' => ['required' => ['async'], 'properties' => ['async' => ['const' => true]]], 'then' => ['required' => ['docker_host_id']]]]],
            admin: true,
            bodyRequired: false,
            response: ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => [
                'found' => ['type' => 'integer'], 'marked_missing' => ['type' => 'integer'], 'removed' => ['type' => 'integer'],
            ]]]],
        );
        $operation['responses']['202'] = [
            'description' => 'Local inventory synchronization queued.',
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => [
                'docker_host_id' => ['type' => 'integer', 'const' => 1], 'queued' => ['type' => 'boolean', 'const' => true],
            ]]]]]],
        ];

        return $operation;
    }

    private function operation(string $summary, array $abilities, ?array $body = null, bool $id = false, bool $admin = false, int $status = 200, bool $public = false, bool $bodyRequired = true, array $queryParameters = [], ?array $response = null): array
    {
        $operation = [
            'summary' => $summary,
            'description' => $public
                ? 'Public endpoint; no authentication required.'
                : trim(($abilities ? 'Requires token abilities: '.implode(', ', $abilities).'. ' : '').($admin ? 'Requires an admin user token.' : '')),
            'responses' => [
                (string) $status => ['description' => 'Successful response.'],
            ],
        ];

        if ($public) {
            // Override the global bearerAuth requirement so generated clients don't
            // think fetching the schema itself needs a token.
            $operation['security'] = [];
        } else {
            $operation['responses'] += [
                '401' => ['description' => 'Missing or invalid Bearer token.'],
                '403' => ['description' => 'Missing ability or admin role.'],
                '422' => ['description' => 'Validation or operation error.'],
            ];
        }

        $parameters = $queryParameters;

        if ($id) {
            $parameters[] = [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body) {
            $operation['requestBody'] = [
                'required' => $bodyRequired,
                'content' => [
                    'application/json' => ['schema' => $body],
                ],
            ];
        }

        if ($response !== null) {
            $operation['responses'][(string) $status]['content']['application/json']['schema'] = $response;
        }

        return $operation;
    }

    private function schemas(): array
    {
        return [
            'DockerVolume' => [
                'type' => 'object',
                'properties' => [
                    'docker_host_id' => ['type' => 'integer'],
                    'docker_host' => ['$ref' => '#/components/schemas/OperationalHost'],
                    'identity' => ['type' => 'string', 'description' => 'Host ID followed by a colon and the volume name.'],
                    'canSync' => ['type' => 'boolean'],
                    'canBackup' => ['type' => 'boolean'],
                    'backup_unavailable_reason' => ['type' => ['string', 'null']],
                    'create_job_url' => ['type' => 'string', 'description' => 'Web form URL qualified by volume and docker_host_id.'],
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'driver' => ['type' => ['string', 'null']],
                    'mountpoint' => ['type' => ['string', 'null']],
                    'exists' => ['type' => 'boolean'],
                    'stack_name' => ['type' => ['string', 'null']],
                    'related_jobs_count' => ['type' => 'integer'],
                    'backup_state' => ['type' => 'string', 'enum' => ['backed_up', 'configured', 'unprotected']],
                    'last_backup_run_id' => ['type' => ['integer', 'null']],
                    'last_backup_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'last_backup_key' => ['type' => ['string', 'null']],
                    'last_backup_size_bytes' => ['type' => ['integer', 'null']],
                ],
            ],
            'BackupRun' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'backup_job_id' => ['type' => 'integer'],
                    'backup_group_run_id' => ['type' => ['integer', 'null']],
                    'initiated_by_user_id' => ['type' => ['integer', 'null']],
                    'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'success', 'failed', 'cancelled']],
                    'trigger' => ['type' => 'string', 'enum' => ['scheduled', 'manual', 'pre_restore'], 'description' => 'pre_restore marks a safety backup taken automatically before an in-place restore overwrote the volume.'],
                    'scheduled_for' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Scheduled occurrence claimed for this run. Null for non-scheduled and legacy runs.'],
                    'source_type_snapshot' => ['type' => ['string', 'null'], 'enum' => ['docker_volume', 'host_path', null], 'description' => 'Raw source type captured when the run was created. Null for legacy runs.'],
                    'source_volume_name' => ['type' => ['string', 'null'], 'description' => 'Raw Docker volume snapshot. Null for host-path and legacy runs.'],
                    'source_host_path' => ['type' => ['string', 'null'], 'description' => 'Raw host-path snapshot. Null for Docker-volume and legacy runs.'],
                    'backup_destination_id_snapshot' => ['type' => ['integer', 'null'], 'description' => 'Raw destination ID captured when the run was created. Null for legacy runs.'],
                    'backup_destination_name' => ['type' => ['string', 'null'], 'description' => 'Raw destination name captured when the run was created. Null for legacy runs.'],
                    'backup_destination_provider' => ['type' => ['string', 'null'], 'description' => 'Raw destination provider captured when the run was created. Null for legacy runs.'],
                    'source_type' => ['type' => 'string', 'enum' => ['docker_volume', 'host_path'], 'description' => 'Authoritative source type for this run, using its snapshot with a live-job fallback for legacy runs.'],
                    'source_name' => ['type' => 'string', 'description' => 'Authoritative source volume name or host path for this run, using its snapshot with a live-job fallback for legacy runs.'],
                    'destination_id' => ['type' => ['integer', 'null'], 'description' => 'Authoritative destination ID for this run, using its snapshot with a live-job fallback for legacy runs.'],
                    'destination_name' => ['type' => 'string', 'description' => 'Authoritative destination name for this run, using its snapshot with a live-job fallback for legacy runs.'],
                    'destination_provider' => ['type' => ['string', 'null'], 'description' => 'Authoritative destination provider for this run, using its snapshot with a live-job fallback for legacy runs.'],
                    'backup_filename' => ['type' => ['string', 'null'], 'description' => 'Expected archive filename assigned when the run was created.'],
                    'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'last_heartbeat_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'finished_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'duration_seconds' => ['type' => ['integer', 'null']],
                    'logs' => ['type' => ['string', 'null']],
                    'error_message' => ['type' => ['string', 'null']],
                    'docker_container_id' => ['type' => ['string', 'null']],
                    'docker_host_id' => ['type' => 'integer', 'readOnly' => true, 'description' => 'Docker host recorded for this execution. Existing runs are assigned to the built-in local host (1).'],
                    'docker_host' => ['$ref' => '#/components/schemas/OperationalHost'],
                    'docker_container_cleanup_pending' => ['type' => 'boolean', 'description' => 'Whether interrupted backup-helper cleanup still needs to be reconciled.'],
                    'stopped_container_ids' => ['type' => ['array', 'null'], 'items' => ['type' => 'string'], 'description' => 'Application containers still owned by this run until restart recovery completes.'],
                    'backup_key' => ['type' => ['string', 'null']],
                    'backup_size_bytes' => ['type' => ['integer', 'null']],
                    'archive_metadata_pending' => ['type' => 'boolean', 'description' => 'Whether archive key and size metadata are still awaiting asynchronous recording.'],
                    'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'job' => ['type' => ['object', 'null'], 'description' => 'Current backup job relation retained for response compatibility.'],
                ],
            ],
            'OperationalHost' => [
                'type' => ['object', 'null'],
                'description' => 'Allowlisted operational summary. Host identity is stable; name and availability reflect current metadata. No enrollment or agent credentials.',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'is_local' => ['type' => 'boolean'],
                    'status' => ['type' => 'string'],
                    'availability' => ['type' => 'string'],
                    'maintenance_requested' => ['type' => 'boolean'],
                    'capabilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'last_seen_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'last_inventory_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'docker_container_count' => ['type' => ['integer', 'null']],
                    'total_volumes' => ['type' => 'integer'],
                    'existing_volumes' => ['type' => 'integer'],
                    'missing_volumes' => ['type' => 'integer'],
                    'inventory_source' => ['type' => 'string'],
                    'canSync' => ['type' => 'boolean'],
                    'canBackup' => ['type' => 'boolean'],
                    'backup_unavailable_reason' => ['type' => ['string', 'null']],
                ],
            ],
            'BackupRunResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/BackupRun'],
                ],
            ],
            'BackupRunCollectionResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'hosts' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OperationalHost']],
                    'filters' => ['type' => 'object', 'properties' => ['docker_host_id' => ['type' => ['integer', 'null']]]],
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/BackupRun'],
                    ],
                ],
            ],
            'BackupGroupRun' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'backup_job_group_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'success', 'failed', 'cancelled']],
                    'trigger' => ['type' => 'string', 'enum' => ['scheduled', 'manual']],
                    'scheduled_for' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Scheduled occurrence claimed for this group run. Null for manual and legacy runs.'],
                    'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'finished_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'duration_seconds' => ['type' => ['integer', 'null']],
                    'total_members' => ['type' => 'integer'],
                    'succeeded_members' => ['type' => 'integer'],
                    'failed_members' => ['type' => 'integer'],
                    'total_backup_size_bytes' => ['type' => ['integer', 'null'], 'description' => 'Sum of the member runs\' archive sizes in bytes. Null until at least one member archive size is recorded (sizes arrive asynchronously shortly after each volume finishes).'],
                    'error_message' => ['type' => ['string', 'null']],
                ],
            ],
            'BackupJobGroupRequest' => [
                'type' => 'object',
                'required' => ['name', 'schedule_type', 'failure_policy'],
                'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'schedule_type' => ['type' => 'string', 'enum' => ['hourly', 'daily', 'weekly', 'cron']],
                    'schedule_config' => ['type' => 'object', 'description' => 'Schedule details: {everyHours} for hourly, {time} for daily, {dayOfWeek,time} for weekly, {expression} for cron.'],
                    'timezone' => ['type' => ['string', 'null'], 'description' => 'IANA timezone the group schedule is evaluated in. Defaults to the application timezone.'],
                    'failure_policy' => ['type' => 'string', 'enum' => ['continue', 'stop'], 'description' => 'continue backs up every member volume and reports failure if any fails; stop halts the run at the first failed volume. Either way the group reports failure when any volume fails.'],
                    'notifications_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'When enabled the group emits one start notification and one success/fail notification for the whole set of member volumes.'],
                    'notification_channel_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Notification channel IDs used for the group\'s aggregated notifications.'],
                ],
            ],
            'ToggleNotificationsRequest' => [
                'type' => 'object',
                'required' => ['notifications_enabled'],
                'properties' => [
                    'notifications_enabled' => ['type' => 'boolean', 'description' => 'Required. Omitting it is rejected rather than silently disabling notifications.'],
                ],
            ],
            'DockerLabelBackupSettingsRequest' => [
                'type' => 'object',
                'required' => ['enabled', 'schedule_type', 'schedule_config', 'backup_filter_mode', 'notifications_enabled', 'alert_notifications_enabled', 'stop_containers_before_backup'],
                'properties' => [
                    'docker_host_id' => ['type' => 'integer', 'default' => 1],
                    'enabled' => ['type' => 'boolean'],
                    'backup_destination_id' => ['type' => ['integer', 'null'], 'description' => 'An active shared or same-host destination is required when enabled.'],
                    'schedule_type' => ['type' => 'string', 'enum' => ['hourly', 'daily', 'weekly', 'cron']],
                    'schedule_config' => ['type' => 'object'],
                    'timezone' => ['type' => ['string', 'null']],
                    'retention_days' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'retention_count' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'backup_filter_mode' => ['type' => 'string', 'enum' => ['exclude', 'include']],
                    'backup_include_paths' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                    'backup_exclude_regexp' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                    'backup_filename_template' => ['type' => ['string', 'null'], 'maxLength' => 180],
                    'notifications_enabled' => ['type' => 'boolean'],
                    'notification_channel_ids' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer'], 'uniqueItems' => true],
                    'alert_notifications_enabled' => ['type' => 'boolean'],
                    'stop_containers_before_backup' => ['type' => 'boolean'],
                ],
            ],
            'BackupJobRequest' => [
                'type' => 'object',
                'required' => ['name', 'backup_destination_id'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'planning_mode' => ['type' => ['string', 'null'], 'enum' => ['standalone', 'group', null], 'default' => 'standalone', 'description' => 'standalone (default) keeps the job\'s own schedule and notifications. group attaches the job to a backup group that owns the schedule and notifications; the job\'s own schedule_type and notification_channel_ids are then ignored and its next run is driven by the group.'],
                    'group_selection' => ['type' => ['string', 'null'], 'enum' => ['existing', 'new', null], 'description' => 'When planning_mode=group: "existing" attaches to backup_job_group_id; "new" creates the group described by new_group.'],
                    'backup_job_group_id' => ['type' => ['integer', 'null'], 'description' => 'Existing backup group to attach this job to. Required when planning_mode=group and group_selection is "existing" (or omitted).'],
                    'new_group' => ['type' => ['object', 'null'], 'description' => 'Group to create inline when planning_mode=group and group_selection=new. Same fields as BackupJobGroupRequest (name, schedule_type, schedule_config, timezone, failure_policy, notifications_enabled, notification_channel_ids).'],
                    'source_type' => ['type' => 'string', 'enum' => ['docker_volume', 'host_path'], 'default' => 'docker_volume'],
                    'docker_host_id' => ['type' => 'integer', 'description' => 'Source Docker host. Defaults to local host 1 when creating a job; omitted on update preserves the existing host. Remote hosts require backup-v1.'],
                    'volume_name' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z0-9_.-]+$', 'maxLength' => 255, 'description' => 'Required when source_type is docker_volume. Must match the Docker volume name pattern ^[A-Za-z0-9_.-]+$.'],
                    'host_path' => ['type' => ['string', 'null'], 'description' => 'Required when source_type is host_path. Must be an absolute directory path on the Docker host and match VOLUMEVAULT_HOST_PATH_ALLOWLIST when configured.'],
                    'backup_destination_id' => ['type' => 'integer'],
                    'schedule_type' => ['type' => ['string', 'null'], 'enum' => ['hourly', 'daily', 'weekly', 'cron', null], 'description' => 'Required for a standalone job (planning_mode omitted or standalone). Ignored when planning_mode=group, where the group owns the schedule.'],
                    'schedule_config' => ['type' => 'object'],
                    'retention_days' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'retention_count' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'backup_exclude_regexp' => ['type' => ['string', 'null'], 'maxLength' => 1000, 'description' => 'Go regular expression passed to BACKUP_EXCLUDE_REGEXP for offen/docker-volume-backup when backup_filter_mode is "exclude". Matching full file paths are excluded.'],
                    'backup_filter_mode' => ['type' => 'string', 'enum' => ['exclude', 'include'], 'default' => 'exclude', 'description' => 'Filtering mode. "exclude" uses backup_exclude_regexp to drop matching paths (default). "include" keeps only the paths listed in backup_include_paths; VolumeVault generates the matching exclude regexp automatically.'],
                    'backup_include_paths' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Comma-separated list of folders/files to keep, relative to the backup source root (e.g. "Backups, config/app.conf"). Used only when backup_filter_mode is "include"; empty keeps everything. Each individual path must be 200 characters or fewer and cannot contain "." or ".." segments.'],
                    'backup_filename_template' => ['type' => ['string', 'null'], 'maxLength' => 180, 'description' => 'Optional archive filename template without extension. Supported tokens: {name}, {source}, {id}, {run}, {year}, {month}, {day}, {time}, {hour}, {minute}, {second}. Existing jobs with null keep the legacy volumevault-{source}-run-{id}.tar.gz naming.'],
                    'notifications_enabled' => ['type' => 'boolean', 'default' => true],
                    'use_custom_alert_settings' => ['type' => 'boolean', 'default' => false],
                    'alert_notifications_enabled' => ['type' => 'boolean', 'default' => true],
                    'alert_configs' => [
                        'type' => ['array', 'null'],
                        'description' => 'Complete replacement set of per-rule overrides. On update, omit this field to preserve existing overrides. When custom alert settings are enabled, send null or [] to delete all overrides; submitted rules are upserted and omitted rules are deleted. use_custom_alert_settings may be omitted to use the job\'s existing setting. Explicitly setting use_custom_alert_settings to false deletes all overrides.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['alert_rule_id'],
                            'properties' => [
                                'alert_rule_id' => ['type' => 'integer'],
                                'enabled' => ['type' => ['boolean', 'null']],
                                'config' => ['type' => ['object', 'null']],
                            ],
                        ],
                    ],
                    'notification_channel_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Notification channel IDs selected for this backup job. Omit on create to use the default notification channel when one is configured.',
                    ],
                    'stop_containers_before_backup' => ['type' => 'boolean'],
                    'stop_container_names' => [
                        'type' => ['array', 'null'],
                        'items' => ['type' => 'string', 'maxLength' => 255],
                        'description' => 'Names of the containers to stop before backup. Only honoured when source_type is host_path and stop_containers_before_backup is true; ignored for docker_volume sources, which discover containers automatically.',
                    ],
                ],
                // Conditional requirements a generated client must honour, so it
                // cannot send a schema-valid request the API then rejects with 422.
                // Each independent rule is its own if/then/else in allOf.
                'allOf' => [
                    // A Docker-volume source requires volume_name.
                    [
                        'if' => ['properties' => ['source_type' => ['const' => 'docker_volume']]],
                        'then' => [
                            'required' => ['volume_name'],
                            'properties' => ['volume_name' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_.-]+$', 'maxLength' => 255]],
                        ],
                    ],
                    // A host-path source requires host_path.
                    [
                        'if' => ['properties' => ['source_type' => ['const' => 'host_path']], 'required' => ['source_type']],
                        'then' => [
                            'required' => ['host_path'],
                            'properties' => ['host_path' => ['type' => 'string']],
                        ],
                    ],
                    // Standalone (planning_mode omitted/"standalone") requires a
                    // non-null schedule_type; a grouped job delegates it to the group.
                    [
                        'if' => [
                            'properties' => ['planning_mode' => ['const' => 'group']],
                            'required' => ['planning_mode'],
                        ],
                        'else' => [
                            'required' => ['schedule_type'],
                            'properties' => ['schedule_type' => ['type' => 'string', 'enum' => ['hourly', 'daily', 'weekly', 'cron']]],
                        ],
                    ],
                    // Attaching to an existing group (planning_mode=group and
                    // group_selection is "existing" or omitted) requires
                    // backup_job_group_id.
                    [
                        'if' => [
                            'properties' => [
                                'planning_mode' => ['const' => 'group'],
                                'group_selection' => ['not' => ['const' => 'new']],
                            ],
                            'required' => ['planning_mode'],
                        ],
                        'then' => [
                            'required' => ['backup_job_group_id'],
                            'properties' => ['backup_job_group_id' => ['type' => 'integer']],
                        ],
                    ],
                    // Creating a group inline (planning_mode=group, group_selection=new)
                    // requires new_group with its name, schedule_type and failure_policy.
                    [
                        'if' => [
                            'properties' => [
                                'planning_mode' => ['const' => 'group'],
                                'group_selection' => ['const' => 'new'],
                            ],
                            'required' => ['planning_mode', 'group_selection'],
                        ],
                        'then' => [
                            'required' => ['new_group'],
                            'properties' => [
                                'new_group' => [
                                    'type' => 'object',
                                    'required' => ['name', 'schedule_type', 'failure_policy'],
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'schedule_type' => ['type' => 'string', 'enum' => ['hourly', 'daily', 'weekly', 'cron']],
                                        'schedule_config' => ['type' => 'object'],
                                        'timezone' => ['type' => ['string', 'null']],
                                        'failure_policy' => ['type' => 'string', 'enum' => ['continue', 'stop']],
                                        'notifications_enabled' => ['type' => 'boolean'],
                                        'notification_channel_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'StackBackupRequest' => [
                'type' => 'object',
                'properties' => [
                    'docker_host_id' => ['type' => 'integer', 'enum' => [1], 'default' => 1, 'description' => 'Only local stack execution is supported. Explicit remote host IDs are rejected.'],
                    'stack' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'Compose or Swarm stack name (com.docker.compose.project / com.docker.stack.namespace). Null or omitted targets the "no stack" group of volumes that carry no stack label.'],
                    'backup_destination_id' => ['type' => ['integer', 'null'], 'description' => 'Destination for jobs created on the fly. Required only when the stack has volumes without a backup job; ignored when every volume is already covered.'],
                    'schedule_type' => ['type' => ['string', 'null'], 'enum' => ['hourly', 'daily', 'weekly', 'cron'], 'description' => 'Schedule for jobs created on the fly. Required only when the stack has volumes without a backup job. Existing jobs keep their own schedule.'],
                    'schedule_config' => ['type' => 'object', 'description' => 'Schedule details for created jobs: {everyHours} for hourly, {time} for daily, {dayOfWeek,time} for weekly, {expression} for cron.'],
                    'timezone' => ['type' => ['string', 'null'], 'description' => 'IANA timezone the created jobs\' schedule is evaluated in. Defaults to the application timezone.'],
                ],
            ],
            'PauseRequest' => [
                'type' => 'object',
                'properties' => [
                    'pause_reason' => ['type' => 'string'],
                ],
            ],
            'RestoreRequest' => [
                'type' => 'object',
                'required' => ['selected_backup_key', 'mode'],
                'properties' => [
                    'selected_backup_key' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Opaque provider object key of the backup to restore. Submit the exact key returned by GET /backup-jobs/{id}/backups; restore creation checks it against the destination listing (fail-closed). Provider keys may be absolute-looking or contain "..".'],
                    'backup_run_id' => ['type' => ['integer', 'null'], 'description' => 'Optional successful backup run belonging to the route job. When supplied, the run\'s snapshotted destination is used for listing, key validation, and restore even if the job now uses another destination. Supply the same value to GET /backup-jobs/{id}/backups.'],
                    'destination_operation_id' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'Fresh successful destination listing receipt containing the exact selected key. For host-local archive transfers this receipt must have executed on the source destination owner, independently of target_docker_host_id. Remote host-local archives require this receipt or a successful historical backup_run_id.'],
                    'mode' => ['type' => 'string', 'enum' => ['new_volume', 'inplace', 'safe_inplace'], 'description' => 'new_volume restores into a fresh volume (never destructive). inplace and safe_inplace overwrite the source volume and are only valid for Docker-volume sources; safe_inplace also stops the affected containers during the restore and restarts them afterwards. Both in-place modes require confirmation_text to equal the source volume name.'],
                    'target_volume_name' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z0-9_.-]+$', 'maxLength' => 128, 'description' => 'Name for the new volume created by a new_volume restore. Must match ^[A-Za-z0-9_.-]+$. Ignored by the in-place modes, which always target the source volume.'],
                    'target_docker_host_id' => ['type' => 'integer', 'description' => 'Execution target host; defaults to the job host. Cross-host restores require new_volume mode. Host-local archives use a central encrypted temporary relay; both participating remote agents require archive-relay-v1, and the target also requires restore-v1. Shared network destinations remain directly accessible.'],
                    'backup_before_overwrite' => ['type' => ['boolean', 'null'], 'description' => 'Only honoured by the destructive in-place modes: when true, a safety backup of the source volume is taken before it is overwritten. Ignored for new_volume.'],
                    'confirmation_text' => ['type' => ['string', 'null'], 'description' => 'Required for the in-place modes: must equal the source volume name to arm the destructive restore.'],
                ],
            ],
            'DestinationCreateRequest' => [
                'type' => 'object',
                'required' => ['name', 'provider'],
                'properties' => $this->destinationProperties(true),
            ],
            'DestinationUpdateRequest' => [
                'type' => 'object',
                'required' => ['name', 'provider'],
                'properties' => $this->destinationProperties(false),
            ],
            'HostKeyRequest' => [
                'type' => 'object',
                'required' => ['host'],
                'properties' => [
                    'host' => ['type' => 'string', 'description' => 'SSH server hostname or IP.'],
                    'port' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 65535, 'default' => 22],
                ],
            ],
            'NotificationChannelUpdateRequest' => [
                'type' => 'object',
                'required' => ['name', 'service', 'notification_level'],
                'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'service' => ['type' => 'string', 'enum' => NotificationChannel::SERVICES],
                    'notification_level' => ['type' => 'string', 'enum' => NotificationChannel::LEVELS, 'description' => 'error sends failures only; info sends start, success and failure.'],
                    'scope' => ['type' => ['string', 'null'], 'enum' => [NotificationChannel::SCOPE_ALL, NotificationChannel::SCOPE_SPECIFIC, null]],
                    'title_template' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'Backup title template. Tokens: {{ job }}, {{ source }}, {{ status }}, {{ trigger }}, {{ duration }}, {{ backup_size }}, {{ error }}, …'],
                    'body_template' => ['type' => ['string', 'null'], 'maxLength' => 4000],
                    'restore_title_template' => ['type' => ['string', 'null'], 'maxLength' => 255],
                    'restore_body_template' => ['type' => ['string', 'null'], 'maxLength' => 4000],
                    'is_active' => ['type' => 'boolean'],
                    'is_default' => ['type' => 'boolean'],
                    'config' => [
                        'type' => ['object', 'null'],
                        'additionalProperties' => true,
                        'description' => 'Guided setup fields used to (re)build the encrypted delivery URL; leave empty to keep the saved URL. For the webhook service: start_url, success_url, fail_url (any subset of HTTP(S) URLs called on the matching lifecycle event — start, success or failure — for backups and restores).',
                    ],
                ],
            ],
        ];
    }

    private function backupRunIdParameter(): array
    {
        return [
            'name' => 'backup_run_id',
            'in' => 'query',
            'required' => false,
            'description' => 'Use the destination snapshotted by this successful backup run. The run must belong to the route job and have an archive key.',
            'schema' => ['type' => 'integer'],
        ];
    }

    private function destinationProperties(bool $secretsRequired): array
    {
        return [
            'name' => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => BackupDestination::PROVIDERS],
            'docker_host_id' => ['type' => ['integer', 'null'], 'description' => 'Owner of a local filesystem or Docker-volume destination. Network destinations are shared and have no host owner.'],
            'endpoint' => ['type' => ['string', 'null'], 'format' => 'uri'],
            'region' => ['type' => ['string', 'null']],
            'bucket' => ['type' => ['string', 'null'], 'description' => 'Legacy S3 bucket field. Use settings for non-S3 providers.'],
            'path_prefix' => ['type' => ['string', 'null']],
            'access_key_id' => ['type' => $secretsRequired ? 'string' : ['string', 'null']],
            'secret_access_key' => ['type' => $secretsRequired ? 'string' : ['string', 'null']],
            'use_path_style_endpoint' => ['type' => 'boolean'],
            'settings' => [
                'type' => ['object', 'null'],
                'additionalProperties' => true,
                'description' => 'Provider-specific non-secret settings. Examples: WebDAV url/path, SSH host/remote_path, Azure container, Dropbox remote_path, Google Drive folder_id, local archive_path, docker_volume volume_name/path_prefix. For local destinations, archive_path and archive_mount_source must match VOLUMEVAULT_HOST_PATH_ALLOWLIST (fail-closed: refused when the allowlist is empty); read GET /host-path-allowlist for the allowed prefixes. For the docker_volume provider, volume_name (required) is the name of an existing Docker volume of any driver (e.g. NFS) that VolumeVault mounts by name into the temporary backup container; it must match ^[A-Za-z0-9][A-Za-z0-9_.-]*$ (no slashes or colons), and the optional path_prefix is a relative sub-directory (no "..", colon, or leading slash). A volume that does not exist is rejected rather than silently created. For SSH, set host_key (an OpenSSH public host key line or a SHA256: fingerprint) to pin the server and block man-in-the-middle attacks; use POST /destinations/host-key to discover it.',
            ],
            'secrets' => [
                'type' => ['object', 'null'],
                'additionalProperties' => ['type' => ['string', 'null']],
                'description' => 'Provider-specific secrets. Values are encrypted at rest and never returned in responses.',
            ],
            'is_active' => ['type' => 'boolean'],
        ];
    }
}
