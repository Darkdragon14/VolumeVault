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

    private function paths(): array
    {
        return [
            '/openapi.json' => [
                'get' => $this->operation('Read the OpenAPI document.', [], null, false),
            ],
            '/me' => ['get' => $this->operation('Inspect current authenticated user and token.', ['read'])],
            '/dashboard' => ['get' => $this->operation('Read dashboard stats and recent activity.', ['read'])],
            '/volumes' => ['get' => $this->operation('List Docker volumes.', ['read'])],
            '/host-path-allowlist' => ['get' => $this->operation('Read the configured host-path allowlist (prefixes that host-path backup sources and local destinations may use). Empty/not configured means host paths are refused (fail-closed).', ['read'], null, false, true)],
            '/volumes/sync' => ['post' => $this->operation('Synchronize Docker volumes from the host.', ['write'], null, true, true)],
            '/stacks/backup' => ['post' => $this->operation('Back up a whole stack at once. For every Docker volume in the stack that has no backup job yet, a job is created using the given destination and schedule; then a manual run is queued for every Docker-volume job in the stack. When the stack is already fully configured, omit destination/schedule to just queue a run for every job. Volumes whose job belongs to a backup group are reported under "grouped" and are not run here — they back up on their group\'s own schedule. The 202 response is { data: { created, queued, skipped, grouped } }.', ['write'], ['$ref' => '#/components/schemas/StackBackupRequest'], false, true, 202)],
            '/backup-jobs' => [
                'get' => $this->operation('List backup jobs.', ['read']),
                'post' => $this->operation('Create a backup job.', ['write'], ['$ref' => '#/components/schemas/BackupJobRequest'], true, true),
            ],
            '/backup-jobs/{id}' => [
                'get' => $this->operation('Read a backup job and recent runs.', ['read'], null, true),
                'put' => $this->operation('Update a backup job.', ['write'], ['$ref' => '#/components/schemas/BackupJobRequest'], true, true),
                'delete' => $this->operation('Delete a backup job.', ['write'], null, true, true, 204),
            ],
            '/backup-jobs/{id}/run' => ['post' => $this->operation('Queue a manual backup run.', ['write'], null, true, true, 202)],
            '/backup-jobs/{id}/pause' => ['post' => $this->operation('Pause a backup job.', ['write'], ['$ref' => '#/components/schemas/PauseRequest'], true, true)],
            '/backup-jobs/{id}/resume' => ['post' => $this->operation('Resume a backup job.', ['write'], null, true, true)],
            '/backup-jobs/{id}/backups' => ['get' => $this->operation('List backup objects available for restore.', ['read'], null, true, true)],
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
            '/backup-groups/{id}/pause' => ['post' => $this->operation('Pause a backup group.', ['write'], ['$ref' => '#/components/schemas/PauseRequest'], true, true)],
            '/backup-groups/{id}/resume' => ['post' => $this->operation('Resume a backup group.', ['write'], null, true, true)],
            '/backup-groups/{id}/notifications' => ['patch' => $this->operation('Enable or disable a backup group\'s notifications.', ['write'], ['$ref' => '#/components/schemas/ToggleNotificationsRequest'], true, true)],
            '/backup-group-runs' => ['get' => $this->operation('List recent backup group runs.', ['read'])],
            '/backup-group-runs/{id}' => ['get' => $this->operation('Read a backup group run with its per-volume member runs.', ['read'], null, true)],
            '/backup-runs' => ['get' => $this->operation('List recent backup runs.', ['read'])],
            '/backup-runs/{id}' => ['get' => $this->operation('Read backup run details and logs.', ['read'], null, true)],
            '/restore-runs' => ['get' => $this->operation('List recent restore runs.', ['read'])],
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

    private function operation(string $summary, array $abilities, ?array $body = null, bool $id = false, bool $admin = false, int $status = 200): array
    {
        $operation = [
            'summary' => $summary,
            'description' => trim(($abilities ? 'Requires token abilities: '.implode(', ', $abilities).'. ' : '').($admin ? 'Requires an admin user token.' : '')),
            'responses' => [
                (string) $status => ['description' => 'Successful response.'],
                '401' => ['description' => 'Missing or invalid Bearer token.'],
                '403' => ['description' => 'Missing ability or admin role.'],
                '422' => ['description' => 'Validation or operation error.'],
            ],
        ];

        if ($id) {
            $operation['parameters'] = [[
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ]];
        }

        if ($body) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => $body],
                ],
            ];
        }

        return $operation;
    }

    private function schemas(): array
    {
        return [
            'DockerVolume' => [
                'type' => 'object',
                'properties' => [
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
                    'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'success', 'failed', 'cancelled']],
                    'trigger' => ['type' => 'string', 'enum' => ['scheduled', 'manual', 'pre_restore'], 'description' => 'pre_restore marks a safety backup taken automatically before an in-place restore overwrote the volume.'],
                    'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'finished_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'duration_seconds' => ['type' => ['integer', 'null']],
                    'backup_key' => ['type' => ['string', 'null']],
                    'backup_size_bytes' => ['type' => ['integer', 'null']],
                ],
            ],
            'BackupGroupRun' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'backup_job_group_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'success', 'failed', 'cancelled']],
                    'trigger' => ['type' => 'string', 'enum' => ['scheduled', 'manual']],
                    'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'finished_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'duration_seconds' => ['type' => ['integer', 'null']],
                    'total_members' => ['type' => 'integer'],
                    'succeeded_members' => ['type' => 'integer'],
                    'failed_members' => ['type' => 'integer'],
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
                    'volume_name' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z0-9_.-]+$', 'maxLength' => 255, 'description' => 'Required when source_type is docker_volume. Must match the Docker volume name pattern ^[A-Za-z0-9_.-]+$.'],
                    'host_path' => ['type' => ['string', 'null'], 'description' => 'Required when source_type is host_path. Must be an absolute directory path on the Docker host and match VOLUMEVAULT_HOST_PATH_ALLOWLIST when configured.'],
                    'backup_destination_id' => ['type' => 'integer'],
                    'schedule_type' => ['type' => ['string', 'null'], 'enum' => ['hourly', 'daily', 'weekly', 'cron', null], 'description' => 'Required for a standalone job (planning_mode omitted or standalone). Ignored when planning_mode=group, where the group owns the schedule.'],
                    'schedule_config' => ['type' => 'object'],
                    'retention_days' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'retention_count' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'backup_exclude_regexp' => ['type' => ['string', 'null'], 'maxLength' => 1000, 'description' => 'Go regular expression passed to BACKUP_EXCLUDE_REGEXP for offen/docker-volume-backup. Matching full file paths are excluded.'],
                    'backup_filename_template' => ['type' => ['string', 'null'], 'maxLength' => 180, 'description' => 'Optional archive filename template without extension. Supported tokens: {name}, {source}, {id}, {run}, {year}, {month}, {day}, {time}, {hour}, {minute}, {second}. Existing jobs with null keep the legacy volumevault-{source}-run-{id}.tar.gz naming.'],
                    'notifications_enabled' => ['type' => 'boolean', 'default' => true],
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
                    'selected_backup_key' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Object key of the backup to restore. Must be one of the keys returned by GET /backup-jobs/{id}/backups; it is checked against the destination listing (fail-closed), so arbitrary or path-traversal keys such as "../../etc/passwd" are rejected.'],
                    'mode' => ['type' => 'string', 'enum' => ['new_volume', 'inplace', 'safe_inplace'], 'description' => 'new_volume restores into a fresh volume (never destructive). inplace and safe_inplace overwrite the source volume and are only valid for Docker-volume sources; safe_inplace also stops the affected containers during the restore and restarts them afterwards. Both in-place modes require confirmation_text to equal the source volume name.'],
                    'target_volume_name' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z0-9_.-]+$', 'maxLength' => 128, 'description' => 'Name for the new volume created by a new_volume restore. Must match ^[A-Za-z0-9_.-]+$. Ignored by the in-place modes, which always target the source volume.'],
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

    private function destinationProperties(bool $secretsRequired): array
    {
        return [
            'name' => ['type' => 'string'],
            'provider' => ['type' => 'string', 'enum' => BackupDestination::PROVIDERS],
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
