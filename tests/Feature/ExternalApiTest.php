<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_schema_is_public(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('components.schemas.BackupJobRequest.properties.backup_exclude_regexp.maxLength', 1000)
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
            ->assertJsonPath('data.0.name', 'app-data');
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
            ->assertJsonPath('data.destination.has_access_key_id', true)
            ->assertJsonMissing(['secret-access-key'])
            ->assertJsonMissing(['secret-access-key-id']);
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
}
