<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'VolumeVault API',
                'version' => '1.0.0',
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
            '/volumes/sync' => ['post' => $this->operation('Synchronize Docker volumes from the host.', ['write'], null, true, true)],
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
            '/notifications' => ['get' => $this->operation('List notification channels without plaintext URLs.', ['read'], null, false, true)],
            '/notifications/{id}' => ['get' => $this->operation('Read one notification channel without plaintext URL.', ['read'], null, true, true)],
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
            'BackupJobRequest' => [
                'type' => 'object',
                'required' => ['name', 'volume_name', 'backup_destination_id', 'schedule_type'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'volume_name' => ['type' => 'string'],
                    'backup_destination_id' => ['type' => 'integer'],
                    'schedule_type' => ['type' => 'string', 'enum' => ['hourly', 'daily', 'weekly', 'cron']],
                    'schedule_config' => ['type' => 'object'],
                    'retention_days' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'retention_count' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'backup_exclude_regexp' => ['type' => ['string', 'null'], 'maxLength' => 1000, 'description' => 'Go regular expression passed to BACKUP_EXCLUDE_REGEXP for offen/docker-volume-backup. Matching full file paths are excluded.'],
                    'stop_containers_before_backup' => ['type' => 'boolean'],
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
                    'selected_backup_key' => ['type' => 'string'],
                    'mode' => ['type' => 'string', 'enum' => ['new_volume']],
                    'target_volume_name' => ['type' => ['string', 'null']],
                    'confirmation_text' => ['type' => ['string', 'null']],
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
                'description' => 'Provider-specific non-secret settings. Examples: WebDAV url/path, SSH host/remote_path, Azure container, Dropbox remote_path, Google Drive folder_id, local archive_path.',
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
