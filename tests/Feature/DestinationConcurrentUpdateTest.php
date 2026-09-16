<?php

namespace Tests\Feature;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\BackupDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationConcurrentUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_update_preserves_newer_omitted_setting_and_secret_values(): void
    {
        $destination = $this->destination();
        $this->replaceDestinationImmediatelyBeforeLocking($destination);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/destinations/'.$destination->id, $this->updatePayload())
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect('/destinations');

        $this->assertLatestValuesWerePreserved($destination);
    }

    public function test_api_update_preserves_newer_omitted_setting_and_secret_values(): void
    {
        $destination = $this->destination();
        $this->replaceDestinationImmediatelyBeforeLocking($destination);
        $token = User::factory()->admin()->create()->createToken('destination-write', ['write'])->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/destinations/'.$destination->id, $this->updatePayload())
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed destination');

        $this->assertLatestValuesWerePreserved($destination);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'SSH destination',
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
                'host_key' => 'SHA256:stale-host-key',
            ],
            'secrets' => [
                'user' => 'backup-user',
                'private_key' => 'stale-private-key',
            ],
            'is_active' => true,
        ]);
    }

    private function replaceDestinationImmediatelyBeforeLocking(BackupDestination $destination): void
    {
        $locks = new class($destination->id) extends WithDockerLabelMutationLocks
        {
            private bool $replaced = false;

            public function __construct(private readonly int $destinationId) {}

            public function handleForJobs(
                array $managedJobIds,
                array $destinationIds,
                callable $callback,
                array $volumeNames = [],
                array $notificationChannelIds = [],
                array $explicitJobIds = [],
            ): mixed {
                if (! $this->replaced) {
                    $this->replaced = true;
                    $destination = BackupDestination::findOrFail($this->destinationId);
                    $destination->update([
                        'settings' => [
                            ...$destination->settings,
                            'host_key' => 'SHA256:latest-host-key',
                        ],
                        'secrets' => [
                            ...$destination->secrets,
                            'private_key' => 'latest-private-key',
                        ],
                    ]);
                }

                return parent::handleForJobs(
                    $managedJobIds,
                    $destinationIds,
                    $callback,
                    $volumeNames,
                    $notificationChannelIds,
                    $explicitJobIds,
                );
            }
        };

        $this->app->instance(WithDockerLabelMutationLocks::class, $locks);
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(): array
    {
        return [
            'name' => 'Renamed destination',
            'provider' => BackupDestination::PROVIDER_SSH,
            'settings' => [
                'host' => 'server.local',
                'port' => 22,
                'remote_path' => '/updated/backups',
            ],
            'is_active' => true,
        ];
    }

    private function assertLatestValuesWerePreserved(BackupDestination $destination): void
    {
        $destination->refresh();

        $this->assertSame('/updated/backups', $destination->settings['remote_path']);
        $this->assertSame('SHA256:latest-host-key', $destination->settings['host_key']);
        $this->assertSame('latest-private-key', $destination->secrets['private_key']);
    }
}
