<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExternalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_schema_is_public(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('components.schemas.BackupJobRequest.properties.source_type.enum.1', 'host_path')
            ->assertJsonPath('components.schemas.BackupJobRequest.properties.backup_exclude_regexp.maxLength', 1000)
            ->assertJsonPath('components.schemas.BackupJobRequest.properties.notifications_enabled.default', true)
            ->assertJsonPath('components.schemas.BackupJobRequest.properties.notification_channel_ids.items.type', 'integer')
            ->assertJsonPath('components.schemas.DockerVolume.properties.backup_state.enum.0', 'backed_up')
            ->assertJsonPath('components.schemas.BackupRun.properties.backup_size_bytes.type.0', 'integer')
            ->assertJsonPath('components.securitySchemes.bearerAuth.scheme', 'bearer');
    }

    public function test_api_requires_a_bearer_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_endpoint_includes_user_locale(): void
    {
        $user = User::factory()->user()->create(['locale' => 'it']);
        $token = $user->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.locale', 'it');
    }

    public function test_read_token_can_read_volumes(): void
    {
        $user = User::factory()->user()->create();
        DockerVolume::create(['name' => 'app-data', 'exists' => true]);
        $token = $user->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/volumes')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'app-data')
            ->assertJsonPath('data.0.backup_state', 'unprotected')
            ->assertJsonPath('data.0.related_jobs_count', 0);
    }

    public function test_read_only_token_cannot_write(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [])
            ->assertForbidden();
    }

    public function test_non_admin_write_token_cannot_use_admin_api(): void
    {
        $user = User::factory()->user()->create();
        $token = $user->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [])
            ->assertForbidden();
    }

    public function test_admin_write_token_can_create_backup_job(): void
    {
        $admin = User::factory()->admin()->create();
        $destination = BackupDestination::create([
            'name' => 'R2',
            'provider' => BackupDestination::PROVIDER_CLOUDFLARE_R2,
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'volumevault',
            'access_key_id' => 'secret-access-key-id',
            'secret_access_key' => 'secret-access-key',
            'is_active' => true,
        ]);
        $channel = NotificationChannel::create([
            'name' => 'Discord',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/default',
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_default' => true,
        ]);
        DockerVolume::create(['name' => 'app-data', 'exists' => true]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Daily app data',
                'volume_name' => 'app-data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'retention_count' => 7,
                'backup_exclude_regexp' => '\\.log$',
                'stop_containers_before_backup' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Daily app data')
            ->assertJsonPath('data.backup_exclude_regexp', '\\.log$')
            ->assertJsonPath('data.notifications_enabled', true)
            ->assertJsonPath('data.notification_channel_ids.0', $channel->id)
            ->assertJsonPath('data.destination.has_access_key_id', true)
            ->assertJsonMissing(['secret-access-key'])
            ->assertJsonMissing(['secret-access-key-id']);
    }

    public function test_admin_write_token_can_create_host_path_backup_job_when_allowed(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv', '/mnt/data']]);
        $this->app->instance(DockerProcess::class, new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'ok', '');
            }
        });

        $admin = User::factory()->admin()->create();
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
            'is_active' => true,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Daily host path',
                'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
                'host_path' => '/srv/app-data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'retention_count' => 7,
            ])
            ->assertCreated()
            ->assertJsonPath('data.source_type', BackupJob::SOURCE_TYPE_HOST_PATH)
            ->assertJsonPath('data.host_path', '/srv/app-data')
            ->assertJsonPath('data.volume_name', null)
            ->assertJsonPath('data.source_label', '/srv/app-data');
    }

    public function test_host_path_backup_job_can_select_containers_to_stop(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv', '/mnt/data']]);
        $this->app->instance(DockerProcess::class, new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'ok', '');
            }
        });

        $admin = User::factory()->admin()->create();
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
            'is_active' => true,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Daily host path',
                'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
                'host_path' => '/srv/app-data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'stop_containers_before_backup' => true,
                'stop_container_names' => ['app', 'worker'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.stop_containers_before_backup', true)
            ->assertJsonPath('data.stop_container_names', ['app', 'worker']);
    }

    public function test_docker_volume_backup_job_ignores_manually_supplied_container_names(): void
    {
        $admin = User::factory()->admin()->create();
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
            'is_active' => true,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Daily volume',
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => 'app_data',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
                'stop_containers_before_backup' => true,
                'stop_container_names' => ['app'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.stop_containers_before_backup', true)
            ->assertJsonPath('data.stop_container_names', null);
    }

    public function test_host_path_backup_job_outside_allowlist_returns_validation_error(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv']]);

        $admin = User::factory()->admin()->create();
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
            'is_active' => true,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs', [
                'name' => 'Unsafe host path',
                'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
                'host_path' => '/etc',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '02:00'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('host_path');
    }

    public function test_host_path_backup_job_can_be_queued_without_docker_volume_record(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/archive', 'archive_mount_source' => '/host/archive'],
            'is_active' => true,
        ]);
        $job = BackupJob::create([
            'name' => 'Host path backup',
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => '/srv/app-data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/backup-jobs/'.$job->id.'/run')
            ->assertAccepted()
            ->assertJsonPath('data.backup_job_id', $job->id);
    }

    public function test_destination_api_does_not_expose_plaintext_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        BackupDestination::create([
            'name' => 'AWS',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'region' => 'us-east-1',
            'bucket' => 'volumevault',
            'access_key_id' => 'secret-access-key-id',
            'secret_access_key' => 'secret-access-key',
            'is_active' => true,
        ]);
        $token = $admin->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/destinations')
            ->assertOk()
            ->assertJsonPath('data.0.has_access_key_id', true)
            ->assertJsonMissing(['secret-access-key'])
            ->assertJsonMissing(['secret-access-key-id']);
    }

    public function test_admin_read_token_can_read_the_host_path_allowlist(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv//data/', '/mnt/backups']]);
        $token = User::factory()->admin()->create()->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/host-path-allowlist')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.prefixes.0', '/srv/data')
            ->assertJsonPath('data.prefixes.1', '/mnt/backups');
    }

    public function test_host_path_allowlist_reports_fail_closed_when_empty(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $token = User::factory()->admin()->create()->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/host-path-allowlist')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.prefixes', []);
    }

    public function test_host_path_allowlist_is_admin_only(): void
    {
        $token = User::factory()->user()->create()->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/host-path-allowlist')
            ->assertForbidden();
    }

    public function test_admin_write_token_can_probe_an_ssh_host_key(): void
    {
        $this->mock(DestinationStorage::class)
            ->shouldReceive('probeHostKey')
            ->once()
            ->with('ssh.example.com', 2222)
            ->andReturn(['key' => 'ssh-ed25519 AAAAKEY', 'fingerprint' => 'SHA256:abc']);

        $token = User::factory()->admin()->create()->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/destinations/host-key', ['host' => 'ssh.example.com', 'port' => 2222])
            ->assertOk()
            ->assertJsonPath('data.key', 'ssh-ed25519 AAAAKEY')
            ->assertJsonPath('data.fingerprint', 'SHA256:abc');
    }

    public function test_host_key_probe_requires_write_ability(): void
    {
        $token = User::factory()->admin()->create()->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/destinations/host-key', ['host' => 'ssh.example.com'])
            ->assertForbidden();
    }

    public function test_host_key_probe_validates_the_host(): void
    {
        $token = User::factory()->admin()->create()->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/destinations/host-key', ['host' => ''])
            ->assertStatus(422);
    }

    public function test_openapi_documents_the_host_key_probe(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./destinations/host-key.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/HostKeyRequest')
            ->assertJsonPath('components.schemas.HostKeyRequest.required.0', 'host');
    }

    public function test_openapi_documents_the_host_path_allowlist(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./host-path-allowlist.get.summary', fn (string $summary): bool => str_contains($summary, 'host-path allowlist'));
    }

    public function test_openapi_documents_volume_and_restore_key_constraints(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('components.schemas.BackupJobRequest.properties.volume_name.pattern', '^[A-Za-z0-9_.-]+$')
            ->assertJsonPath('components.schemas.RestoreRequest.properties.target_volume_name.pattern', '^[A-Za-z0-9_.-]+$')
            ->assertJsonPath('components.schemas.RestoreRequest.properties.selected_backup_key.description', fn (string $d): bool => str_contains($d, '/backup-jobs/{id}/backups'));
    }

    public function test_api_restore_is_attributed_to_the_token_owner(): void
    {
        Queue::fake();

        $archivePath = sys_get_temp_dir().'/volumevault-api-restore-'.uniqid();
        File::ensureDirectoryExists($archivePath);
        File::put($archivePath.'/backup.tar.gz', 'fake-archive');

        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $archivePath],
        ]);
        $job = BackupJob::create([
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$job->id}/restore", [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.initiated_by_user_id', $admin->id);

        File::deleteDirectory($archivePath);
    }

    public function test_admin_write_token_can_update_a_webhook_notification_channel(): void
    {
        $admin = User::factory()->admin()->create();
        $channel = NotificationChannel::create([
            'name' => 'Healthchecks',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['success' => 'generic+https://hc-ping.com/old']),
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/notifications/{$channel->id}", [
                'name' => 'Healthchecks prod',
                'service' => NotificationChannel::SERVICE_WEBHOOK,
                'notification_level' => NotificationChannel::LEVEL_INFO,
                'config' => [
                    'start_url' => 'https://hc-ping.com/uuid/start',
                    'success_url' => 'https://hc-ping.com/uuid',
                    'fail_url' => 'https://hc-ping.com/uuid/fail',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Healthchecks prod')
            ->assertJsonPath('data.service', NotificationChannel::SERVICE_WEBHOOK)
            ->assertJsonPath('data.masked_url', '********');

        $this->assertSame([
            'start' => 'generic+https://hc-ping.com/uuid/start',
            'success' => 'generic+https://hc-ping.com/uuid',
            'fail' => 'generic+https://hc-ping.com/uuid/fail',
        ], json_decode($channel->fresh()->url, true));
    }

    public function test_updating_a_notification_channel_rejects_an_invalid_webhook_url(): void
    {
        $admin = User::factory()->admin()->create();
        $channel = NotificationChannel::create([
            'name' => 'Healthchecks',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['success' => 'generic+https://hc-ping.com/uuid']),
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/notifications/{$channel->id}", [
                'name' => 'Healthchecks',
                'service' => NotificationChannel::SERVICE_WEBHOOK,
                'notification_level' => NotificationChannel::LEVEL_INFO,
                'config' => ['success_url' => 'ftp://nope'],
            ])
            ->assertStatus(422);
    }

    public function test_updating_a_notification_channel_requires_write_ability(): void
    {
        $admin = User::factory()->admin()->create();
        $channel = NotificationChannel::create([
            'name' => 'Ntfy',
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/all',
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
        $token = $admin->createToken('openclaw-read', ['read'])->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/notifications/{$channel->id}", [
                'name' => 'Ntfy',
                'service' => NotificationChannel::SERVICE_ADVANCED,
                'notification_level' => NotificationChannel::LEVEL_INFO,
                'config' => [],
            ])
            ->assertForbidden();
    }

    public function test_openapi_documents_the_notification_update(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./notifications/{id}.put.requestBody.content.application/json.schema.$ref', '#/components/schemas/NotificationChannelUpdateRequest')
            ->assertJsonPath('components.schemas.NotificationChannelUpdateRequest.properties.service.enum', NotificationChannel::SERVICES);
    }

    public function test_partial_api_update_preserves_omitted_optional_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $channel = NotificationChannel::create([
            'name' => 'Webhook',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['success' => 'generic+https://example.com/ok']),
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => false,
            'is_default' => true,
            'title_template' => 'Backup {{ status }}',
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        // A partial update that only sends the required fields must not reset the
        // optional ones the client omitted.
        $this->withToken($token)
            ->putJson("/api/v1/notifications/{$channel->id}", [
                'name' => 'Webhook renamed',
                'service' => NotificationChannel::SERVICE_WEBHOOK,
                'notification_level' => NotificationChannel::LEVEL_INFO,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Webhook renamed');

        $channel->refresh();
        $this->assertFalse($channel->is_active);
        $this->assertTrue($channel->is_default);
        $this->assertSame('Backup {{ status }}', $channel->title_template);
        $this->assertSame(['success' => 'generic+https://example.com/ok'], json_decode($channel->url, true));
    }

    public function test_updating_a_notification_channel_tolerates_null_config(): void
    {
        $admin = User::factory()->admin()->create();
        $channel = NotificationChannel::create([
            'name' => 'Webhook',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['success' => 'generic+https://example.com/ok']),
            'notification_level' => NotificationChannel::LEVEL_INFO,
        ]);
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        // config is nullable; an explicit null must be a no-op, not a TypeError.
        $this->withToken($token)
            ->putJson("/api/v1/notifications/{$channel->id}", [
                'name' => 'Webhook',
                'service' => NotificationChannel::SERVICE_WEBHOOK,
                'notification_level' => NotificationChannel::LEVEL_INFO,
                'config' => null,
            ])
            ->assertOk();

        $this->assertSame(['success' => 'generic+https://example.com/ok'], json_decode($channel->fresh()->url, true));
    }
}
