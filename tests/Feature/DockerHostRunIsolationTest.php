<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Restore\RunRestore;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\VolumeJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DockerHostRunIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_keys_are_preserved_and_remote_homonymous_volumes_lock_independently(): void
    {
        $this->assertSame('volume-app_data', VolumeJobLock::key('app_data', 'unused'));
        $this->assertSame('backup-job-42', VolumeJobLock::key(null, 'backup-job-42', DockerHost::LOCAL_ID));
        $this->assertSame('restore-run-42', VolumeJobLock::key('', 'restore-run-42'));
        $this->assertSame('laravel-queue-overlap:volume-app_data', VolumeJobLock::cacheKey('app_data'));
        $this->assertSame('host-2:backup-job-42', VolumeJobLock::key(null, 'backup-job-42', 2));

        $local = Cache::lock(VolumeJobLock::cacheKey('app_data'), 60);
        $remote = Cache::lock(VolumeJobLock::cacheKey('app_data', 2), 60);
        $other = Cache::lock(VolumeJobLock::cacheKey('app_data', 3), 60);

        try {
            $this->assertTrue($local->get());
            $this->assertTrue($remote->get());
            $this->assertTrue($other->get());
            $this->assertFalse(Cache::lock(VolumeJobLock::cacheKey('app_data', 2), 60)->get());
        } finally {
            $local->release();
            $remote->release();
            $other->release();
        }
    }

    public function test_backup_and_restore_share_the_snapshot_host_lock_not_the_current_job_or_source_host(): void
    {
        $remote = DockerHost::factory()->create();
        $job = $this->backupJob();
        $backup = $this->backupRun($job, ['docker_host_id' => $remote->id]);
        $restore = $this->restoreRun($job, ['target_docker_host_id' => $remote->id]);
        $backupQueueJob = new RunBackupJob($backup->id);
        $restoreQueueJob = new RunRestoreJob($restore->id);
        $backupLock = $backupQueueJob->middleware()[0];
        $restoreLock = $restoreQueueJob->middleware()[0];

        $this->assertSame('host-'.$remote->id.':volume-app_data', $backupLock->key);
        $this->assertSame($backupLock->getLockKey($backupQueueJob), $restoreLock->getLockKey($restoreQueueJob));
        $this->assertSame(VolumeJobLock::cacheKey('app_data', $remote->id), $backupLock->getLockKey($backupQueueJob));
    }

    public static function busyRuns(): array
    {
        $cases = [];

        foreach (['backup', 'restore'] as $waiter) {
            foreach (['backup', 'restore'] as $holder) {
                foreach (['running', 'stopped', 'cleanup'] as $state) {
                    if ($holder === 'restore' && $state === 'cleanup') {
                        continue;
                    }

                    foreach ([false, true] as $sameHost) {
                        $cases[$waiter.'-'.$holder.'-'.$state.'-'.($sameHost ? 'same' : 'remote')] = [$waiter, $holder, $state, $sameHost];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('busyRuns')]
    public function test_expired_lock_sql_only_blocks_runs_on_the_same_host(string $waiter, string $holder, string $state, bool $sameHost): void
    {
        $hostId = $sameHost ? DockerHost::LOCAL_ID : DockerHost::factory()->create()->id;
        $job = $this->backupJob();
        $attributes = ['status' => $state === 'running' ? 'running' : 'failed'];

        if ($state === 'stopped') {
            $attributes['stopped_container_ids'] = ['shared-container'];
        } elseif ($state === 'cleanup') {
            $attributes['docker_container_cleanup_pending'] = true;
        }

        if ($holder === 'backup') {
            $this->backupRun($job, ['docker_host_id' => $hostId, ...$attributes]);
        } else {
            $this->restoreRun($job, ['target_docker_host_id' => $hostId, ...$attributes]);
        }

        if ($waiter === 'backup') {
            $run = $this->backupRun($job);
            $queueJob = new RunBackupJob($run->id);
            $action = $this->mock(RunBackup::class);
        } else {
            $run = $this->restoreRun($job);
            $queueJob = new RunRestoreJob($run->id);
            $action = $this->mock(RunRestore::class);
        }

        if ($sameHost) {
            $action->shouldNotReceive('handle');
        } else {
            $action->shouldReceive('handle')->once()->withArgs(fn ($received): bool => $received->is($run));
        }

        $queueJob->withFakeQueueInteractions()->handle($action);

        if ($sameHost) {
            $queueJob->assertReleased(60);
        } else {
            $queueJob->assertNotReleased();
        }
    }

    public function test_host_path_sql_uses_the_run_host_even_when_the_job_has_moved(): void
    {
        $remote = DockerHost::factory()->create();
        $job = $this->backupJob();
        $job->update(['source_type' => BackupJob::SOURCE_TYPE_HOST_PATH, 'volume_name' => null, 'host_path' => '/srv/data']);
        $this->backupRun($job, [
            'docker_host_id' => $remote->id,
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'source_volume_name' => null,
            'status' => BackupRun::STATUS_RUNNING,
        ]);
        $run = $this->backupRun($job, ['source_type_snapshot' => BackupJob::SOURCE_TYPE_HOST_PATH, 'source_volume_name' => null]);
        $action = $this->mock(RunBackup::class);
        $action->shouldReceive('handle')->once();

        (new RunBackupJob($run->id))->handle($action);
    }

    public function test_local_reconciliation_leaves_every_remote_recovery_candidate_untouched(): void
    {
        $remote = DockerHost::factory()->create();
        $job = $this->backupJob();
        $runs = [];

        foreach (['running', 'queued', 'failed'] as $status) {
            $attributes = [
                'status' => $status,
                'started_at' => now()->subDays(2),
                'docker_container_id' => 'remote-helper',
                'stopped_container_ids' => ['remote-application'],
            ];
            $runs[] = $this->backupRun($job, ['docker_host_id' => $remote->id, 'docker_container_cleanup_pending' => true, ...$attributes]);
            $runs[] = $this->restoreRun($job, ['target_docker_host_id' => $remote->id, ...$attributes]);
            $runs[] = $this->restoreRun($job, ['source_docker_host_id' => $remote->id, 'target_docker_host_id' => $remote->id, 'target_volume_name' => 'other_data', ...$attributes]);
        }

        foreach ($runs as $run) {
            $run->forceFill([
                'dispatch_token' => 'second-publication',
                'dispatch_published_at' => now()->subHours(2),
                'dispatch_attempted_at' => now()->subHour(),
                'created_at' => now()->subDays(2),
            ])->save();
        }

        $before = array_map(fn ($run): array => $run->fresh()->getAttributes(), $runs);
        $local = $this->backupRun($job, ['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()->subDays(2)]);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $local->fresh()->status);
        $this->assertSame($before, array_map(fn ($run): array => $run->fresh()->getAttributes(), $runs));
    }

    public function test_remote_group_is_rejected_before_claim_or_notifications_and_not_reconciled_locally(): void
    {
        $remote = DockerHost::factory()->create();
        $group = BackupJobGroup::create([
            'name' => 'Mixed hosts',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
        ]);
        $job = $this->backupJob();
        $job->update(['backup_job_group_id' => $group->id, 'docker_host_id' => $remote->id]);
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
        ]);
        $run->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->mock(RunBackup::class)->shouldNotReceive('handle');
        $this->mock(SendShoutrrrNotification::class)->shouldNotReceive('sendGroupRunStarted');

        try {
            app(RunBackupGroup::class)->handle($run);
            $this->fail('A remote group must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Backup groups containing remote Docker hosts cannot execute locally.', $exception->getMessage());
        }

        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame(0, $run->memberRuns()->count());
        $this->assertDatabaseCount('run_finalizations', 0);
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $run->fresh()->status);

        $this->backupRun($job, ['docker_host_id' => $remote->id, 'backup_group_run_id' => $run->id, 'status' => BackupRun::STATUS_SUCCESS]);
        $job->update(['docker_host_id' => DockerHost::LOCAL_ID]);
        $run->forceFill(['status' => BackupGroupRun::STATUS_RUNNING, 'started_at' => now()->subDays(2)])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame(BackupGroupRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_interrupted_restore_from_remote_archive_is_reconciled_on_its_local_target(): void
    {
        $remote = DockerHost::factory()->create();
        $job = $this->backupJob();
        $attributes = [
            'source_docker_host_id' => $remote->id,
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'target_volume_name' => 'restored_data',
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subHour(),
        ];
        $local = $this->restoreRun($job, [...$attributes, 'docker_container_id' => 'local-restore-helper']);
        $remoteTarget = $this->restoreRun($job, [...$attributes, 'target_docker_host_id' => $remote->id, 'docker_container_id' => 'remote-restore-helper']);
        $remoteBefore = $remoteTarget->refresh()->getAttributes();
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->with('local-restore-helper')->twice()->andReturnFalse();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_FAILED, $local->refresh()->status);
        $this->assertNotNull($local->finished_at);
        $this->assertNotNull($local->error_message);
        $this->assertSame($remoteBefore, $remoteTarget->refresh()->getAttributes());

        $waiter = $this->restoreRun($job, ['target_volume_name' => 'restored_data']);
        $action = $this->mock(RunRestore::class);
        $action->shouldReceive('handle')->once()->withArgs(fn (RestoreRun $run): bool => $run->is($waiter));
        $queued = (new RunRestoreJob($waiter->id))->withFakeQueueInteractions();
        $queued->handle($action);
        $queued->assertNotReleased();
    }

    public function test_exhausted_restore_publication_is_reconciled_by_target_not_archive_origin(): void
    {
        $remote = DockerHost::factory()->create();
        $job = $this->backupJob();
        $attributes = ['source_docker_host_id' => $remote->id, 'mode' => RestoreRun::MODE_NEW_VOLUME, 'target_volume_name' => 'restored_data'];
        $local = $this->restoreRun($job, $attributes);
        $remoteTarget = $this->restoreRun($job, [...$attributes, 'target_docker_host_id' => $remote->id]);
        foreach ([$local, $remoteTarget] as $run) {
            $run->forceFill([
                'dispatch_token' => 'second-publication',
                'dispatch_published_at' => now()->subHours(2),
                'dispatch_attempted_at' => now()->subHour(),
                'created_at' => now()->subHours(3),
            ])->save();
        }
        $remoteBefore = $remoteTarget->refresh()->getAttributes();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_FAILED, $local->refresh()->status);
        $this->assertNotNull($local->finished_at);
        $this->assertStringContainsString('queue publication attempts', $local->error_message);
        $this->assertSame($remoteBefore, $remoteTarget->refresh()->getAttributes());
    }

    public static function terminalRestoreStatuses(): array
    {
        return [[RestoreRun::STATUS_FAILED], [RestoreRun::STATUS_SUCCESS], [RestoreRun::STATUS_CANCELLED]];
    }

    #[DataProvider('terminalRestoreStatuses')]
    public function test_restore_container_recovery_uses_target_host_only(string $status): void
    {
        $remote = DockerHost::factory()->create();
        $job = $this->backupJob();
        $attributes = [
            'source_docker_host_id' => $remote->id,
            'mode' => RestoreRun::MODE_SAFE_INPLACE,
            'status' => $status,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHour(),
        ];
        $local = $this->restoreRun($job, [...$attributes, 'stopped_container_ids' => ['local-application']]);
        $remoteTarget = $this->restoreRun($job, [...$attributes, 'target_docker_host_id' => $remote->id, 'stopped_container_ids' => ['remote-application']]);
        $remoteBefore = $remoteTarget->refresh()->getAttributes();
        $this->mock(StartDockerContainers::class)->shouldReceive('handle')->once()->with(['local-application']);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame($status, $local->refresh()->status);
        $this->assertNull($local->stopped_container_ids);
        $this->assertSame($remoteBefore, $remoteTarget->refresh()->getAttributes());
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
    }

    private function backupJob(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/tmp/vv'],
        ]);

        return BackupJob::create([
            'name' => 'App data',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function backupRun(BackupJob $job, array $attributes = []): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $job->id,
            'docker_host_id' => DockerHost::LOCAL_ID,
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'source_volume_name' => 'app_data',
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            ...$attributes,
        ]);
    }

    private function restoreRun(BackupJob $job, array $attributes = []): RestoreRun
    {
        return RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'source_docker_host_id' => DockerHost::LOCAL_ID,
            'target_docker_host_id' => DockerHost::LOCAL_ID,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
            ...$attributes,
        ]);
    }
}
