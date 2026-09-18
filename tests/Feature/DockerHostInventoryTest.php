<?php

namespace Tests\Feature;

use App\Actions\Backup\BackupStack;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\MarkMissingVolumeJobs;
use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\ListDockerLabelBackupContainers;
use App\Actions\Docker\ListDockerVolumes;
use App\Actions\Docker\ReadDockerHostInfo;
use App\Actions\Docker\SyncDockerVolumes;
use App\Actions\Restore\GenerateRestoreVolumeName;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerLabelBackupSetting;
use App\Models\DockerVolume;
use App\Models\User;
use App\Services\Volumes\VolumeBackupSummaries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DockerHostInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(ReadDockerHostInfo::class)->shouldReceive('handle')->andReturn(['version' => '29.0.0', 'containers' => 0]);
    }

    public function test_local_sync_only_updates_removes_and_marks_local_inventory(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $remoteVolumes = collect(['shared', 'missing', 'orphan', 'remote-only'])->map(fn (string $name): DockerVolume => $this->volume($remoteHost->id, $name));
        $originalRemoteInventory = $remoteVolumes->map->refresh()->map->getAttributes()->all();
        $localShared = $this->volume(DockerHost::LOCAL_ID, 'shared');
        $localMissing = $this->volume(DockerHost::LOCAL_ID, 'missing');
        $localOrphan = $this->volume(DockerHost::LOCAL_ID, 'orphan');
        $localJob = $this->job(DockerHost::LOCAL_ID, 'missing');
        $remoteJob = $this->job($remoteHost->id, 'missing');
        $this->job($remoteHost->id, 'orphan');
        $this->job($remoteHost->id, 'remote-only');
        $this->job($remoteHost->id, 'remote-job-only');
        DockerLabelBackupSetting::current()->update(['enabled' => false]);
        $this->mock(ListDockerVolumes::class)->shouldReceive('handle')->once()->andReturn([
            ['name' => 'shared', 'driver' => 'local', 'labels' => ['synced' => 'yes']],
        ]);

        $result = app(SyncDockerVolumes::class)->handle();

        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['marked_missing']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(1, $result['affected_jobs']);
        $this->assertSame(['synced' => 'yes'], $localShared->refresh()->labels);
        $this->assertFalse($localMissing->refresh()->isAvailable());
        $this->assertModelMissing($localOrphan);
        $this->assertSame(BackupJob::STATUS_ERROR, $localJob->refresh()->status);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $remoteJob->refresh()->status);
        $this->assertNull($remoteJob->last_error);
        $this->assertSame($originalRemoteInventory, $remoteVolumes->map->refresh()->map->getAttributes()->all());
        $this->assertDatabaseMissing('docker_volumes', ['docker_host_id' => DockerHost::LOCAL_ID, 'name' => 'remote-job-only']);
    }

    public function test_missing_detection_uses_the_requested_host_for_jobs_and_availability(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $localVolume = $this->volume(DockerHost::LOCAL_ID, 'shared', false);
        $remoteVolume = $this->volume($remoteHost->id, 'shared');
        $localJob = $this->job(DockerHost::LOCAL_ID, 'shared');
        $remoteJob = $this->job($remoteHost->id, 'shared');
        $action = app(MarkMissingVolumeJobs::class);

        $this->assertSame(1, $action->handle(['shared']));
        $this->assertSame(BackupJob::STATUS_ERROR, $localJob->refresh()->status);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $remoteJob->refresh()->status);
        $this->assertSame(0, $action->handle(['shared'], $remoteHost->id));

        $localVolume->update(['exists' => true]);
        $remoteVolume->update(['exists' => false]);
        $this->assertSame(1, $action->handle(['shared'], $remoteHost->id));
        $this->assertSame(BackupJob::STATUS_ERROR, $remoteJob->refresh()->status);
    }

    public function test_local_sync_does_not_disable_remote_label_managed_jobs(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $remoteJob = $this->job($remoteHost->id, 'shared');
        $remoteJob->update([
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'remote-managed-job'),
        ]);
        $originalJob = $remoteJob->refresh()->getAttributes();
        DockerLabelBackupSetting::current()->update(['enabled' => false]);
        $this->mock(ListDockerVolumes::class)->shouldReceive('handle')->once()->andReturn([]);

        app(SyncDockerVolumes::class)->handle();

        $this->assertSame($originalJob, $remoteJob->refresh()->getAttributes());
    }

    public function test_coverage_history_and_homonymous_stacks_are_independent_per_host(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $localVolume = $this->volume(DockerHost::LOCAL_ID, 'shared');
        $remoteVolume = $this->volume($remoteHost->id, 'shared');
        $localJob = $this->job(DockerHost::LOCAL_ID, 'shared');
        $remoteJob = $this->job($remoteHost->id, 'shared');
        $localRun = $this->backupRun($localJob, 100, now()->subHour());
        $remoteRun = $this->backupRun($remoteJob, 200, now());
        $volumes = collect([$localVolume, $remoteVolume]);
        $service = app(VolumeBackupSummaries::class);
        $summaries = $service->forVolumes($volumes)->keyBy('docker_host_id');

        $this->assertSame(1, $summaries[DockerHost::LOCAL_ID]['related_jobs_count']);
        $this->assertSame(1, $summaries[$remoteHost->id]['related_jobs_count']);
        $this->assertSame($localRun->id, $summaries[DockerHost::LOCAL_ID]['last_backup_run_id']);
        $this->assertSame($remoteRun->id, $summaries[$remoteHost->id]['last_backup_run_id']);
        $stacks = $service->forStacks($volumes)->keyBy('docker_host_id');
        $this->assertCount(2, $stacks);
        $this->assertSame('app', $stacks[DockerHost::LOCAL_ID]['name']);
        $this->assertSame('app', $stacks[$remoteHost->id]['name']);
        $this->assertSame(1, $stacks[DockerHost::LOCAL_ID]['total_volumes']);
        $this->assertSame(100, $stacks[DockerHost::LOCAL_ID]['last_backup_size_bytes']);
        $this->assertSame(200, $stacks[$remoteHost->id]['last_backup_size_bytes']);

        $remoteJob->delete();
        $summaries = $service->forVolumes($volumes)->keyBy('docker_host_id');
        $this->assertSame('unprotected', $summaries[$remoteHost->id]['backup_state']);
        $this->assertSame('backed_up', $summaries[DockerHost::LOCAL_ID]['backup_state']);
    }

    public function test_snapshot_history_stays_on_original_host_when_job_moves(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $localVolume = $this->volume(DockerHost::LOCAL_ID, 'shared');
        $remoteVolume = $this->volume($remoteHost->id, 'shared');
        $job = $this->job(DockerHost::LOCAL_ID, 'shared');
        $snapshotRun = $this->backupRun($job, 100, now()->subHour());
        $legacyRun = $this->backupRun($job, 200, now());
        $legacyRun->update(['source_type_snapshot' => null, 'source_volume_name' => null]);
        $job->update(['docker_host_id' => $remoteHost->id]);

        $summaries = app(VolumeBackupSummaries::class)->forVolumes(collect([$localVolume, $remoteVolume]))->keyBy('docker_host_id');

        $this->assertSame($snapshotRun->id, $summaries[DockerHost::LOCAL_ID]['last_backup_run_id']);
        $this->assertSame(0, $summaries[DockerHost::LOCAL_ID]['related_jobs_count']);
        $this->assertSame('configured', $summaries[$remoteHost->id]['backup_state']);
        $this->assertNull($summaries[$remoteHost->id]['last_backup_run_id']);
    }

    public function test_label_locks_select_local_volumes_and_managed_jobs_only(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $localVolume = $this->volume(DockerHost::LOCAL_ID, 'shared');
        $this->volume($remoteHost->id, 'shared');
        $localJob = $this->job(DockerHost::LOCAL_ID, 'shared');
        $remoteJob = $this->job($remoteHost->id, 'shared');
        foreach ([$localJob, $remoteJob] as $job) {
            $job->update(['configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL]);
        }
        DockerLabelBackupSetting::current();

        app(WithDockerLabelMutationLocks::class)->handle([], function ($destinations, $settings, $jobs, $volumes) use ($localJob, $localVolume): void {
            $this->assertSame([$localJob->id], $jobs->values()->modelKeys());
            $this->assertSame([$localVolume->id], $volumes->values()->modelKeys());
        }, volumeNames: ['shared']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Docker label mutations are only supported on the local Docker host.');
        app(WithDockerLabelMutationLocks::class)->handle([], function (): void {
            $this->fail('Remote explicit jobs must be rejected before invoking the mutation.');
        }, explicitJobIds: [$remoteJob->id]);
    }

    public function test_label_reconciliation_separates_configuration_keys_and_manual_coverage_by_host(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $this->volume(DockerHost::LOCAL_ID, 'shared');
        $this->volume($remoteHost->id, 'shared');
        $this->job($remoteHost->id, 'shared');
        $remoteManaged = $this->job($remoteHost->id, 'shared');
        $key = hash('sha256', 'docker-label:app/db:database');
        $remoteManaged->update([
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => $key,
        ]);
        $original = $remoteManaged->refresh()->getAttributes();
        DockerLabelBackupSetting::current()->update([
            'enabled' => true,
            'backup_destination_id' => $remoteManaged->backup_destination_id,
        ]);
        $container = [
            'id' => 'db-container', 'name' => 'app-db-1', 'running' => true,
            'labels' => [
                'com.docker.compose.project' => 'app',
                'com.docker.compose.service' => 'db',
                'dev.darkdragon14.volumevault.enable' => 'true',
                'dev.darkdragon14.volumevault.backup.database.volume' => 'shared',
            ],
            'mounts' => [['name' => 'shared', 'destination' => '/data']],
        ];
        $invalidContainer = $container;
        $invalidContainer['labels']['dev.darkdragon14.volumevault.backup.database.schedule'] = 'invalid';
        $this->mock(ListDockerLabelBackupContainers::class)->shouldReceive('handle')->twice()->andReturn([$container], [$invalidContainer]);

        $result = app(ReconcileDockerLabelBackupJobs::class)->handle();
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['conflicts']);
        $localJob = BackupJob::where('docker_host_id', DockerHost::LOCAL_ID)->where('configuration_key', $key)->firstOrFail();

        app(ReconcileDockerLabelBackupJobs::class)->handle();
        $this->assertNotNull($localJob->refresh()->label_reconciliation_error);
        $this->assertSame($original, $remoteManaged->refresh()->getAttributes());
    }

    public function test_stack_backup_does_not_use_remote_coverage_or_queue_remote_jobs(): void
    {
        Queue::fake();
        $remoteHost = DockerHost::factory()->create();
        $this->volume(DockerHost::LOCAL_ID, 'shared');
        $this->volume($remoteHost->id, 'shared');
        $this->volume($remoteHost->id, 'remote-only');
        $remoteJob = $this->job($remoteHost->id, 'shared');
        DockerLabelBackupSetting::current();

        $result = app(BackupStack::class)->handle('app', [
            'backup_destination_id' => $remoteJob->backup_destination_id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
        ]);

        $this->assertSame(['created' => 1, 'queued' => 1, 'skipped' => 0, 'grouped' => 0], $result);
        $this->assertDatabaseCount('backup_runs', 1);
        $this->assertDatabaseHas('backup_runs', ['docker_host_id' => DockerHost::LOCAL_ID]);
        $this->assertDatabaseMissing('backup_jobs', ['docker_host_id' => DockerHost::LOCAL_ID, 'volume_name' => 'remote-only']);
    }

    public function test_remote_volume_cannot_satisfy_local_run_source_validation(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $this->volume($remoteHost->id, 'shared');
        $job = $this->job(DockerHost::LOCAL_ID, 'shared');
        DockerLabelBackupSetting::current();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Docker volume not found: shared');
        app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL);
    }

    public function test_remote_volume_names_do_not_reserve_local_restore_names(): void
    {
        $remoteHost = DockerHost::factory()->create();
        $now = now();
        $expected = 'shared_restored_'.$now->format('Ymd_His');
        $this->volume($remoteHost->id, $expected);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->with($expected)->andThrow(new RuntimeException('Not found'));

        $this->assertSame($expected, app(GenerateRestoreVolumeName::class)->handle('shared', $now));
    }

    public function test_backup_job_source_picker_includes_host_identity_for_remote_volumes(): void
    {
        $this->withoutVite();
        $remoteHost = DockerHost::factory()->create();
        $this->volume(DockerHost::LOCAL_ID, 'shared');
        $this->volume($remoteHost->id, 'shared');
        $this->volume($remoteHost->id, 'remote-only');

        $this->actingAs(User::factory()->admin()->create())->get('/backup-jobs/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('volumes', 3)
                ->where('volumes', fn ($volumes) => collect($volumes)->where('name', 'shared')->pluck('docker_host_id')->sort()->values()->all() === [DockerHost::LOCAL_ID, $remoteHost->id]));
    }

    private function volume(int $hostId, string $name, bool $exists = true): DockerVolume
    {
        return DockerVolume::create([
            'docker_host_id' => $hostId,
            'name' => $name,
            'exists' => $exists,
            'labels' => ['com.docker.compose.project' => 'app'],
        ]);
    }

    private function job(int $hostId, string $volumeName): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        return BackupJob::create([
            'docker_host_id' => $hostId,
            'name' => 'Backup '.$volumeName,
            'volume_name' => $volumeName,
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function backupRun(BackupJob $job, int $size, \DateTimeInterface $finishedAt): BackupRun
    {
        return BackupRun::create([
            'docker_host_id' => $job->docker_host_id,
            'backup_job_id' => $job->id,
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'source_volume_name' => $job->volume_name,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'finished_at' => $finishedAt,
            'backup_size_bytes' => $size,
        ]);
    }
}
