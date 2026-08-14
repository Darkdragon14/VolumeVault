<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SshDestinationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_an_ssh_destination_with_the_legacy_endpoint_field(): void
    {
        $destination = BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'endpoint' => 'server.local',
            'bucket' => 'server.local:/backups',
            'path_prefix' => '/backups',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => [
                'host' => 'server.local',
                'port' => 22,
                'remote_path' => '/backups',
                'host_key' => 'SHA256:pinned-host-key',
            ],
            'secrets' => [
                'user' => 'backup-user',
                'private_key' => 'private-key',
            ],
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/destinations/'.$destination->id, $this->editPayload($destination))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect('/destinations');

        $destination->refresh();

        $this->assertSame('/updated/backups', $destination->settings['remote_path']);
        $this->assertSame('SHA256:pinned-host-key', $destination->settings['host_key']);
        $this->assertSame('backup-user', $destination->secrets['user']);
        $this->assertSame('private-key', $destination->secrets['private_key']);
    }

    public function test_s3_compatible_endpoint_must_still_be_a_url(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/destinations', [
                'name' => 'Custom S3',
                'provider' => BackupDestination::PROVIDER_CUSTOM_S3,
                'endpoint' => 'storage.local',
                'bucket' => 'backups',
                'access_key_id' => 'access-key',
                'secret_access_key' => 'secret-key',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('endpoint');

        $this->assertSame(0, BackupDestination::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function editPayload(BackupDestination $destination): array
    {
        return [
            'name' => $destination->name,
            'provider' => $destination->provider,
            'endpoint' => $destination->endpoint,
            'region' => '',
            'bucket' => $destination->bucket,
            'path_prefix' => $destination->path_prefix,
            'access_key_id' => '',
            'secret_access_key' => '',
            'use_path_style_endpoint' => false,
            'is_active' => true,
            'settings' => [
                ...$destination->settings,
                'remote_path' => '/updated/backups',
            ],
            'secrets' => [
                'user' => '',
                'password' => '',
                'private_key' => '',
                'private_key_passphrase' => '',
            ],
        ];
    }
}
