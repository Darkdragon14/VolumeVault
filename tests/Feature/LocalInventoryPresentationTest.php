<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalInventoryPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_and_api_volume_lists_only_present_local_rows_and_local_backup_coverage(): void
    {
        $this->createInventory();
        $user = User::factory()->user()->create();

        $this->actingAs($user)->get('/volumes?docker_host_id=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Volumes/Index')
            ->has('volumes', 4)
            ->where('volumes.0.name', 'app_backed')
            ->where('volumes.0.docker_host_id', DockerHost::LOCAL_ID)
            ->where('volumes.0.related_jobs_count', 1)
            ->where('volumes.0.backup_state', 'backed_up')
            ->where('volumes.0.last_backup_size_bytes', 1024)
            ->where('volumes.1.name', 'app_configured')
            ->where('volumes.1.backup_state', 'configured')
            ->where('volumes.2.name', 'app_unprotected')
            ->where('volumes.2.related_jobs_count', 0)
            ->where('volumes.2.backup_state', 'unprotected')
            ->where('volumes.2.last_backup_run_id', null)
            ->where('volumes.3.name', 'app_missing')
            ->where('volumes.3.exists', false)
            ->where('volumes', fn ($volumes): bool => $volumes->every(fn ($volume): bool => $volume['docker_host_id'] === DockerHost::LOCAL_ID))
        );

        $token = $user->createToken('read-inventory', ['read'])->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/volumes?docker_host_id=1')->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.name', 'app_backed')
            ->assertJsonPath('data.0.related_jobs_count', 1)
            ->assertJsonPath('data.0.last_backup_size_bytes', 1024)
            ->assertJsonPath('data.1.backup_state', 'configured')
            ->assertJsonPath('data.2.name', 'app_unprotected')
            ->assertJsonPath('data.2.related_jobs_count', 0)
            ->assertJsonPath('data.2.backup_state', 'unprotected')
            ->assertJsonPath('data.2.last_backup_run_id', null)
            ->assertJsonPath('data.3.exists', false);

        $this->assertSame([DockerHost::LOCAL_ID], collect($response->json('data'))->pluck('docker_host_id')->unique()->all());
        $this->assertDatabaseCount('docker_volumes', 9);
    }

    public function test_stack_rows_and_counts_exclude_remote_homonyms_and_remote_only_labels(): void
    {
        $this->createInventory();

        $this->actingAs(User::factory()->user()->create())->get('/stacks?docker_host_id=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Stacks/Index')
            ->has('stacks', 1)
            ->where('stacks.0.name', 'app')
            ->where('stacks.0.docker_host_id', DockerHost::LOCAL_ID)
            ->where('stacks.0.total_volumes', 4)
            ->where('stacks.0.existing_volumes', 3)
            ->where('stacks.0.missing_volumes', 1)
            ->where('stacks.0.configured_job_volumes', 2)
            ->where('stacks.0.configuration_state', 'partially_configured')
            ->where('stacks.0.backed_up_volumes', 1)
            ->where('stacks.0.configured_volumes', 1)
            ->where('stacks.0.unprotected_volumes', 1)
            ->where('stacks.0.last_backup_size_bytes', 1024)
            ->has('stacks.0.volumes', 4)
            ->where('stacks.0.volumes', fn ($volumes): bool => $volumes->every(fn ($volume): bool => $volume['docker_host_id'] === DockerHost::LOCAL_ID))
        );

        $this->assertDatabaseCount('docker_volumes', 9);
    }

    public function test_web_and_api_dashboard_inventory_counts_only_include_the_local_host(): void
    {
        $this->createInventory();
        $user = User::factory()->user()->create();

        $this->actingAs($user)->get('/dashboard?docker_host_id=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats.total_volumes', 4)
            ->where('stats.existing_volumes', 3)
            ->where('stats.missing_volumes', 1)
            ->where('stats.backed_up_volumes', 1)
            ->where('stats.configured_volumes', 1)
            ->where('stats.unprotected_volumes', 1)
        );

        $token = $user->createToken('read-inventory', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/dashboard?docker_host_id=1')->assertOk()
            ->assertJsonPath('data.stats.total_volumes', 4)
            ->assertJsonPath('data.stats.existing_volumes', 3)
            ->assertJsonPath('data.stats.missing_volumes', 1)
            ->assertJsonPath('data.stats.backed_up_volumes', 1)
            ->assertJsonPath('data.stats.configured_volumes', 1)
            ->assertJsonPath('data.stats.unprotected_volumes', 1);

        $this->assertDatabaseCount('docker_volumes', 9);
    }

    public function test_remote_only_inventory_leaves_local_operation_pages_empty_without_deleting_metadata(): void
    {
        $remote = DockerVolume::create([
            'docker_host_id' => DockerHost::factory()->create()->id,
            'name' => 'remote_only',
            'exists' => true,
            'labels' => ['com.docker.stack.namespace' => 'remote-stack'],
        ]);
        $user = User::factory()->user()->create();

        $this->actingAs($user)->get('/volumes?docker_host_id=1')->assertOk()->assertInertia(fn (Assert $page) => $page->has('volumes', 0));
        $this->get('/stacks?docker_host_id=1')->assertOk()->assertInertia(fn (Assert $page) => $page->has('stacks', 0));
        $this->get('/dashboard?docker_host_id=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_volumes', 0)
            ->where('stats.existing_volumes', 0)
            ->where('stats.missing_volumes', 0)
            ->where('stats.backed_up_volumes', 0)
            ->where('stats.configured_volumes', 0)
            ->where('stats.unprotected_volumes', 0)
        );

        $token = $user->createToken('read-inventory', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/volumes?docker_host_id=1')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/dashboard?docker_host_id=1')->assertOk()
            ->assertJsonPath('data.stats.total_volumes', 0)
            ->assertJsonPath('data.stats.existing_volumes', 0)
            ->assertJsonPath('data.stats.missing_volumes', 0)
            ->assertJsonPath('data.stats.backed_up_volumes', 0)
            ->assertJsonPath('data.stats.configured_volumes', 0)
            ->assertJsonPath('data.stats.unprotected_volumes', 0);

        $this->assertModelExists($remote);
        $this->assertSame(['com.docker.stack.namespace' => 'remote-stack'], $remote->fresh()->labels);
    }

    private function createInventory(): void
    {
        $remote = DockerHost::factory()->create();
        $destination = BackupDestination::create([
            'name' => 'Backups',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/tmp/backups'],
        ]);

        foreach ([DockerHost::LOCAL_ID, $remote->id] as $hostId) {
            foreach (['app_backed', 'app_configured', 'app_unprotected', 'app_missing'] as $name) {
                DockerVolume::create([
                    'docker_host_id' => $hostId,
                    'name' => $name,
                    'exists' => $name !== 'app_missing',
                    'labels' => ['com.docker.compose.project' => 'app'],
                ]);

                if ($name === 'app_missing' || ($name === 'app_unprotected' && $hostId === DockerHost::LOCAL_ID)) {
                    continue;
                }

                $job = BackupJob::create([
                    'docker_host_id' => $hostId,
                    'name' => 'Backup '.$name,
                    'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                    'volume_name' => $name,
                    'backup_destination_id' => $destination->id,
                    'schedule_type' => BackupJob::SCHEDULE_DAILY,
                    'schedule_config' => ['time' => '02:00'],
                    'cron_expression' => '0 2 * * *',
                    'status' => BackupJob::STATUS_ACTIVE,
                ]);

                if ($name === 'app_backed' || $hostId === $remote->id) {
                    BackupRun::create([
                        'docker_host_id' => $hostId,
                        'backup_job_id' => $job->id,
                        'status' => BackupRun::STATUS_SUCCESS,
                        'trigger' => BackupRun::TRIGGER_MANUAL,
                        'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                        'source_volume_name' => $name,
                        'finished_at' => $hostId === DockerHost::LOCAL_ID ? now()->subHour() : now(),
                        'backup_size_bytes' => $hostId === DockerHost::LOCAL_ID ? 1024 : 9999,
                    ]);
                }
            }
        }

        DockerVolume::create([
            'docker_host_id' => $remote->id,
            'name' => 'remote_only',
            'exists' => true,
            'labels' => ['com.docker.stack.namespace' => 'remote-only'],
        ]);
    }
}
