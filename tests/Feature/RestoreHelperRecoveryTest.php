<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Restore\RunRestore;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\ArchiveRelay;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Agents\AgentLifecycle;
use App\Services\Docker\DockerProcess;
use App\Support\RunHeartbeatLock;
use App\Support\VolumeJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RestoreHelperRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
    }

    public function test_crash_after_create_removes_helper_before_failing_run_and_releases_maintenance(): void
    {
        $run = $this->restoreRun();
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $this->assertFalse(app(AgentLifecycle::class)->state($host)['maintenance_ready']);
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->with('created-helper')->andReturn(false);
        $this->mock(RemoveDockerContainer::class)->shouldReceive('handle')->once()->with('created-helper')
            ->andReturnUsing(function () use ($run): void {
                $this->assertSame('running', $run->fresh()->status);
                $this->assertTrue($run->fresh()->docker_container_cleanup_pending);
            });

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame('failed', $run->refresh()->status);
        $this->assertFalse($run->docker_container_cleanup_pending);
        $this->assertNull($run->docker_container_id);
        $this->assertTrue(app(AgentLifecycle::class)->state($host)['maintenance_ready']);
        $this->assertSame('restored', $run->target_volume_name);
    }

    public function test_volume_lock_suppresses_both_stale_and_terminal_helper_cleanup(): void
    {
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->andReturn(false);
        $this->mock(RemoveDockerContainer::class)->shouldNotReceive('handle');
        foreach (['running', 'failed'] as $status) {
            $run = $this->restoreRun($status);
            $lock = Cache::lock(VolumeJobLock::cacheKey($run->target_volume_name), 86400);
            $this->assertTrue($lock->get());
            try {
                $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
                $this->assertSame($status, $run->refresh()->status);
                $this->assertTrue($run->docker_container_cleanup_pending);
            } finally {
                $lock->release();
            }
        }
    }

    public function test_terminal_remove_failure_is_retried_and_counts_only_on_target_host(): void
    {
        Exceptions::fake();
        $run = $this->restoreRun('failed');
        $source = DockerHost::factory()->create();
        $run->forceFill(['source_docker_host_id' => $source->id])->save();
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $local->forceFill(['maintenance_requested_at' => now()])->save();
        $remove = $this->mock(RemoveDockerContainer::class);
        $remove->shouldReceive('handle')->once()->with('created-helper')->andThrow(new RuntimeException('daemon unavailable'));
        $remove->shouldReceive('handle')->once()->with('created-helper')->andReturnNull();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertTrue($run->refresh()->docker_container_cleanup_pending);
        $this->assertSame('created-helper', $run->docker_container_id);
        $this->assertSame(1, app(AgentLifecycle::class)->activeOperations($local));
        $this->assertSame(0, app(AgentLifecycle::class)->activeOperations($source));
        $this->assertFalse(app(AgentLifecycle::class)->state($local)['maintenance_ready']);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertFalse($run->refresh()->docker_container_cleanup_pending);
        $this->assertSame('failed', $run->status);
        $this->assertTrue(app(AgentLifecycle::class)->state($local)['maintenance_ready']);
    }

    public function test_terminal_cleanup_with_recent_heartbeat_or_heartbeat_lock_is_not_touched(): void
    {
        $run = $this->restoreRun('failed');
        $this->mock(RemoveDockerContainer::class)->shouldNotReceive('handle');
        $run->forceFill(['last_heartbeat_at' => now()])->save();
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $run->forceFill(['last_heartbeat_at' => now()->subHour()])->save();
        $lock = Cache::lock(RunHeartbeatLock::restore($run->id), 180);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
            $this->assertTrue($run->refresh()->docker_container_cleanup_pending);
        } finally {
            $lock->release();
        }
    }

    public function test_alive_stale_helper_is_not_stopped_by_generic_recovery(): void
    {
        $run = $this->restoreRun();
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->with('created-helper')->andReturn(true);
        $this->mock(RemoveDockerContainer::class)->shouldNotReceive('handle');
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertSame('running', $run->refresh()->status);
        $this->assertTrue($run->docker_container_cleanup_pending);
    }

    public function test_terminal_candidate_is_refreshed_under_locks_before_cleanup(): void
    {
        $run = $this->restoreRun('failed');
        $changed = false;
        RestoreRun::retrieved(function (RestoreRun $candidate) use ($run, &$changed): void {
            if ($candidate->id === $run->id && ! $changed) {
                $changed = true;
                RestoreRun::whereKey($run->id)->update(['status' => 'running', 'last_heartbeat_at' => now()]);
            }
        });
        $this->mock(RemoveDockerContainer::class)->shouldNotReceive('handle');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertTrue($changed);
        $this->assertSame('running', $run->refresh()->status);
        $this->assertTrue($run->docker_container_cleanup_pending);
    }

    public function test_central_cleanup_excludes_remote_and_relay_targets(): void
    {
        $remote = $this->restoreRun('failed');
        $remote->forceFill(['target_docker_host_id' => DockerHost::factory()->create()->id])->save();
        $relay = $this->restoreRun('failed');
        ArchiveRelay::create([
            'id' => (string) Str::uuid(), 'restore_run_id' => $relay->id,
            'source_docker_host_id' => DockerHost::LOCAL_ID, 'target_docker_host_id' => DockerHost::LOCAL_ID,
            'destination_snapshot' => [], 'source_key' => 'backup.tar.gz', 'reserved_bytes' => 1,
            'expires_at' => now()->addHour(),
        ]);
        $this->mock(RemoveDockerContainer::class)->shouldNotReceive('handle');
        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();
        $this->assertTrue($remote->refresh()->docker_container_cleanup_pending);
        $this->assertTrue($relay->refresh()->docker_container_cleanup_pending);
    }

    public function test_pending_terminal_restore_blocks_backup_and_restore_admission_on_its_target(): void
    {
        $holder = $this->restoreRun('failed');
        $this->assertTrue(RestoreRun::activeOrHoldingContainers()->whereKey($holder->id)->exists());
        $backupJob = $holder->job;
        $backupJob->update(['volume_name' => $holder->target_volume_name]);
        $backup = BackupRun::create(['backup_job_id' => $backupJob->id, 'status' => 'queued', 'trigger' => 'manual']);
        $restore = $this->restoreRun('queued');
        $restore->update(['docker_container_cleanup_pending' => false, 'docker_container_id' => null]);
        $runBackup = $this->mock(RunBackup::class);
        $runBackup->shouldNotReceive('handle');
        $runRestore = $this->mock(RunRestore::class);
        $runRestore->shouldNotReceive('handle');
        $backupQueue = new RunBackupJob($backup->id);
        $backupQueue->withFakeQueueInteractions();
        $backupQueue->handle($runBackup);
        $backupQueue->assertReleased(60);
        $restoreQueue = new RunRestoreJob($restore->id);
        $restoreQueue->withFakeQueueInteractions();
        $restoreQueue->handle($runRestore);
        $restoreQueue->assertReleased(60);
    }

    private function restoreRun(string $status = 'running'): RestoreRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local', 'provider' => 'local', 'bucket' => 'local',
            'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);
        $job = BackupJob::create([
            'name' => 'Backup', 'volume_name' => 'source', 'backup_destination_id' => $destination->id,
            'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *', 'status' => 'active',
        ]);

        return RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => 'source', 'target_volume_name' => 'restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => $status, 'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(), 'docker_container_id' => 'created-helper', 'docker_container_cleanup_pending' => true,
        ]);
    }
}
