<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Actions\Backup\RunBackup;
use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\ListDockerVolumes;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\RunRestoreContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Docker\StopDockerContainers;
use App\Actions\Docker\SyncDockerVolumes;
use App\Actions\Docker\VerifyRestoreArchive;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\RunRestore;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\AgentCommand;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\Host;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcessResult;
use App\Services\Logging\AppendRunLog;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HostsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_host_is_created_and_used_by_default_for_scoped_resources(): void
    {
        $localHost = DB::table('hosts')->where('type', 'local')->first();

        $this->assertNotNull($localHost);
        $this->assertSame('Local Docker Host', $localHost->name);
        $this->assertSame('online', $localHost->status);
        $this->assertSame(1, (int) $localHost->is_active);
        $this->assertSame(1, DB::table('hosts')->where('type', 'local')->count());

        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $destination = $this->destination();
        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        BackupRun::create([
            'backup_job_id' => $job->id,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backups/app_data.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
        ]);

        foreach (['docker_volumes', 'backup_jobs', 'backup_runs', 'restore_runs'] as $table) {
            $this->assertDatabaseHas($table, ['host_id' => $localHost->id]);
        }
    }

    public function test_host_id_columns_are_not_nullable(): void
    {
        foreach (['docker_volumes', 'backup_jobs', 'backup_runs', 'restore_runs'] as $table) {
            $hostId = collect(Schema::getColumns($table))->firstWhere('name', 'host_id');

            $this->assertNotNull($hostId);
            $this->assertFalse($hostId['nullable']);
            $this->assertNotNull($hostId['default']);
        }
    }

    public function test_docker_volume_names_are_unique_per_host(): void
    {
        $localHostId = DB::table('hosts')->where('type', 'local')->value('id');
        $agentHostId = $this->createAgentHost();

        DockerVolume::create(['name' => 'shared_data', 'exists' => true]);
        DB::table('docker_volumes')->insert([
            'host_id' => $agentHostId,
            'name' => 'shared_data',
            'exists' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('docker_volumes')->where('name', 'shared_data')->count());
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $localHostId, 'name' => 'shared_data']);
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $agentHostId, 'name' => 'shared_data']);

        $this->expectException(QueryException::class);

        DockerVolume::create(['name' => 'shared_data', 'exists' => true]);
    }

    public function test_backup_run_creation_rejects_agent_hosts(): void
    {
        $agentHost = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        $destination = $this->destination();
        DockerVolume::create(['host_id' => $agentHost->id, 'name' => 'agent_data', 'exists' => true]);
        $job = BackupJob::create([
            'host_id' => $agentHost->id,
            'name' => 'Agent Nightly',
            'volume_name' => 'agent_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        try {
            app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
            $this->fail('Expected remote backup creation to be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('backup_jobs', [
                'id' => $job->id,
                'status' => BackupJob::STATUS_PAUSED,
                'pause_reason' => 'Remote agent backups are not supported yet.',
                'next_run_at' => null,
            ]);
        }
    }

    public function test_backup_run_creation_rejects_volume_that_exists_only_on_another_host(): void
    {
        $localHost = Host::localHost();
        $agentHost = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        $destination = $this->destination();
        DockerVolume::create(['host_id' => $agentHost->id, 'name' => 'shared_data', 'exists' => true]);
        $job = BackupJob::create([
            'host_id' => $localHost->id,
            'name' => 'Local Nightly',
            'volume_name' => 'shared_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
    }

    public function test_restore_run_creation_rejects_agent_hosts(): void
    {
        $agentHost = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        $destination = $this->destination();
        $job = BackupJob::create([
            'host_id' => $agentHost->id,
            'name' => 'Agent Nightly',
            'volume_name' => 'agent_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateRestoreRun::class)->handle($job, [
            'selected_backup_key' => 'backups/agent_data.tar.gz',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'target_volume_name' => 'agent_data_restored',
        ]);

    }

    public function test_local_backup_updates_volume_for_the_local_host_only(): void
    {
        $localHost = Host::localHost();
        $agentHost = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        $destination = $this->destination();
        DockerVolume::create(['host_id' => $agentHost->id, 'name' => 'app_data', 'exists' => false]);
        $job = BackupJob::create([
            'host_id' => $localHost->id,
            'name' => 'Local Nightly',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'stop_containers_before_backup' => false,
        ]);
        $run = BackupRun::create([
            'host_id' => $localHost->id,
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        $inspectDockerVolume = Mockery::mock(InspectDockerVolume::class);
        $inspectDockerVolume->shouldReceive('handle')->once()->with('app_data')->andReturn(['name' => 'app_data']);
        $findContainersUsingVolume = Mockery::mock(FindContainersUsingVolume::class);
        $findContainersUsingVolume->shouldNotReceive('handle');
        $runBackupContainer = Mockery::mock(RunBackupContainer::class);
        $runBackupContainer->shouldReceive('handle')
            ->once()
            ->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $sendNotification = Mockery::mock(SendShoutrrrNotification::class);
        $sendNotification->shouldReceive('sendBackupRunStarted')->once();
        $sendNotification->shouldReceive('sendBackupRunFinished')->zeroOrMoreTimes();

        $this->app->instance(InspectDockerVolume::class, $inspectDockerVolume);
        $this->app->instance(FindContainersUsingVolume::class, $findContainersUsingVolume);
        $this->app->instance(RunBackupContainer::class, $runBackupContainer);
        $this->app->instance(SendShoutrrrNotification::class, $sendNotification);
        $action = app(RunBackup::class);
        $action->handle($run);

        $this->assertDatabaseHas('docker_volumes', ['host_id' => $localHost->id, 'name' => 'app_data', 'exists' => true]);
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $agentHost->id, 'name' => 'app_data', 'exists' => false]);
    }

    public function test_backup_execution_rejects_a_job_moved_after_the_run_was_queued(): void
    {
        $localHost = Host::localHost();
        $agentHost = Host::factory()->agent()->create();
        $destination = $this->destination();
        $job = BackupJob::create([
            'host_id' => $agentHost->id,
            'name' => 'Moved job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        $run = BackupRun::create([
            'host_id' => $localHost->id,
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
        $sendNotification = Mockery::mock(SendShoutrrrNotification::class);
        $sendNotification->shouldReceive('sendBackupRunStarted')->once();
        $sendNotification->shouldReceive('sendBackupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $sendNotification);
        $action = app(RunBackup::class);

        $action->handle($run);

        $this->assertDatabaseHas('backup_runs', [
            'id' => $run->id,
            'host_id' => $localHost->id,
            'status' => BackupRun::STATUS_FAILED,
            'error_message' => 'The backup job host changed after this run was queued.',
        ]);
    }

    public function test_local_restore_updates_volume_for_the_local_host_only(): void
    {
        $localHost = Host::localHost();
        $agentHost = Host::factory()->agent()->create(['name' => 'Remote Agent']);
        $destination = $this->destination();
        DockerVolume::create(['host_id' => $agentHost->id, 'name' => 'app_data_restored', 'exists' => false]);
        $job = BackupJob::create([
            'host_id' => $localHost->id,
            'name' => 'Local Nightly',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        $run = RestoreRun::create([
            'host_id' => $localHost->id,
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backups/app_data.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
        $inspectDockerVolume = Mockery::mock(InspectDockerVolume::class);
        $inspectDockerVolume->shouldReceive('handle')->once()->with('app_data_restored')->andThrow(new RuntimeException('missing'));
        $createDockerVolume = Mockery::mock(CreateDockerVolume::class);
        $createDockerVolume->shouldReceive('handle')->once()->with('app_data_restored');
        $runRestoreContainer = Mockery::mock(RunRestoreContainer::class);
        $runRestoreContainer->shouldReceive('handle')
            ->once()
            ->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldReceive('download')
            ->once()
            ->with(Mockery::on(fn (BackupDestination $givenDestination): bool => $givenDestination->is($destination)), 'backups/app_data.tar.gz', Mockery::type('string'))
            ->andReturnUsing(function (BackupDestination $destination, string $key, string $path): void {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, 'archive');
            });
        $verifyRestoreArchive = Mockery::mock(VerifyRestoreArchive::class);
        $verifyRestoreArchive->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, 'ok', ''));
        $sendNotification = Mockery::mock(SendShoutrrrNotification::class);
        $sendNotification->shouldReceive('sendRestoreRun')->twice();

        $this->app->instance(InspectDockerVolume::class, $inspectDockerVolume);
        $this->app->instance(CreateDockerVolume::class, $createDockerVolume);
        $this->app->instance(RunRestoreContainer::class, $runRestoreContainer);
        $this->app->instance(DestinationStorage::class, $storage);
        $this->app->instance(VerifyRestoreArchive::class, $verifyRestoreArchive);
        $this->app->instance(SendShoutrrrNotification::class, $sendNotification);
        $action = app(RunRestore::class);
        $action->handle($run);

        $this->assertDatabaseHas('docker_volumes', ['host_id' => $localHost->id, 'name' => 'app_data_restored', 'exists' => true]);
        $this->assertDatabaseHas('docker_volumes', ['host_id' => $agentHost->id, 'name' => 'app_data_restored', 'exists' => false]);
    }

    public function test_scheduled_sync_still_queues_agent_hosts_when_local_docker_fails(): void
    {
        $agentHost = Host::factory()->agent()->create();
        $listDockerVolumes = Mockery::mock(ListDockerVolumes::class);
        $listDockerVolumes->shouldReceive('handle')->once()->andThrow(new RuntimeException('Docker socket unavailable'));
        $syncDockerVolumes = new SyncDockerVolumes($listDockerVolumes, app(MarkMissingVolumeJobs::class));

        (new SyncDockerVolumesJob)->handle($syncDockerVolumes);

        $this->assertDatabaseHas('hosts', [
            'id' => Host::localHost()->id,
            'last_error' => 'Docker socket unavailable',
        ]);
        $this->assertDatabaseHas('agent_commands', [
            'host_id' => $agentHost->id,
            'type' => AgentCommand::TYPE_SYNC_VOLUMES,
            'status' => AgentCommand::STATUS_PENDING,
        ]);
    }

    public function test_local_volume_sync_uses_a_shared_per_host_lock(): void
    {
        $localHost = Host::localHost();
        $lock = Cache::lock('volumevault:docker-volume-sync:'.$localHost->id, 10);
        $this->assertTrue($lock->get());
        $listDockerVolumes = Mockery::mock(ListDockerVolumes::class);
        $listDockerVolumes->shouldNotReceive('handle');
        $syncDockerVolumes = new SyncDockerVolumes($listDockerVolumes, app(MarkMissingVolumeJobs::class));

        try {
            $syncDockerVolumes->handle($localHost);
            $this->fail('A second synchronization should not enter the critical section.');
        } catch (LockTimeoutException) {
            $this->assertTrue(true);
        } finally {
            $lock->release();
        }
    }

    public function test_agent_sync_queue_deduplicates_active_commands(): void
    {
        $agentHost = Host::factory()->agent()->create();
        $syncDockerVolumes = new SyncDockerVolumes(
            Mockery::mock(ListDockerVolumes::class),
            app(MarkMissingVolumeJobs::class),
        );

        $this->assertTrue($syncDockerVolumes->queueAgentSync($agentHost));
        $this->assertFalse($syncDockerVolumes->queueAgentSync($agentHost));
        $this->assertSame(1, AgentCommand::query()
            ->where('host_id', $agentHost->id)
            ->where('type', AgentCommand::TYPE_SYNC_VOLUMES)
            ->count());
    }

    public function test_agent_sync_queue_rechecks_host_activation_from_the_database(): void
    {
        $agentHost = Host::factory()->agent()->create();
        $syncDockerVolumes = new SyncDockerVolumes(
            Mockery::mock(ListDockerVolumes::class),
            app(MarkMissingVolumeJobs::class),
        );

        Host::query()->whereKey($agentHost->id)->update(['is_active' => false]);

        try {
            $syncDockerVolumes->queueAgentSync($agentHost);
            $this->fail('An inactive host should not accept agent sync commands.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Inactive agent hosts cannot queue a remote Docker volume sync.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('agent_commands', [
            'host_id' => $agentHost->id,
            'type' => AgentCommand::TYPE_SYNC_VOLUMES,
        ]);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
    }

    private function createAgentHost(): int
    {
        $now = now();

        return (int) DB::table('hosts')->insertGetId([
            'name' => 'Remote Agent',
            'type' => 'agent',
            'status' => 'offline',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
