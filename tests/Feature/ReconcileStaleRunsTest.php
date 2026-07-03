<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileStaleRuns;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Support\VolumeJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReconcileStaleRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_running_backup_run_is_marked_failed_and_job_reschedulable(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $run->refresh();
        $job->refresh();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error_message);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->status);
        $this->assertNotNull($job->next_run_at);
    }

    public function test_stale_queued_backup_run_is_marked_failed(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        // No started_at; age the run from created_at.
        $run->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_recent_running_backup_run_is_not_swept(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_succeeded_run_is_left_untouched(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->refresh()->status);
    }

    public function test_custom_threshold_keeps_run_younger_than_threshold(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs', ['--minutes' => 60])->assertSuccessful();
        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);

        $this->artisan('volumevault:reconcile-stale-runs', ['--minutes' => 10])->assertSuccessful();
        $this->assertSame(BackupRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_stale_restore_run_is_marked_failed(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subDays(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error_message);
    }

    public function test_running_restore_with_a_recent_heartbeat_is_not_swept(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_RUNNING,
            // A long safety backup / download: started long ago and no container
            // yet, but the worker is alive and refreshing the heartbeat.
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subMinutes(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_running_restore_with_a_stale_heartbeat_is_swept(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_RUNNING,
            // Recent start, but the heartbeat went cold — the worker died.
            'started_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_restore_with_a_dead_container_is_swept_despite_a_recent_archive(): void
    {
        $storagePath = sys_get_temp_dir().'/vv-reconcile-dead-'.uniqid();
        File::ensureDirectoryExists($storagePath);
        $this->app->useStoragePath($storagePath);
        $this->app->instance(DockerProcess::class, $this->inspectDockerProcess(alive: false));

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subMinute(),
            'docker_container_id' => 'volumevault-extract-1-deadbeef',
        ]);

        // A leftover archive from the finished download, freshly written. It must
        // NOT mask the confirmed-dead extract container.
        $archive = $storagePath.'/app/restore-runs/'.$run->id.'/backup.tar.gz';
        File::ensureDirectoryExists(dirname($archive));
        File::put($archive, 'leftover');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);

        File::deleteDirectory($storagePath);
    }

    public function test_running_restore_with_an_in_progress_download_is_not_swept(): void
    {
        $storagePath = sys_get_temp_dir().'/vv-reconcile-'.uniqid();
        File::ensureDirectoryExists($storagePath);
        $this->app->useStoragePath($storagePath);

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_RUNNING,
            // Long download: old start AND cold heartbeat, no container yet.
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        // The worker is mid-download: the temp archive exists and was just written.
        $archive = $storagePath.'/app/restore-runs/'.$run->id.'/backup.tar.gz';
        File::ensureDirectoryExists(dirname($archive));
        File::put($archive, 'partial-download');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_RUNNING, $run->refresh()->status);

        File::deleteDirectory($storagePath);
    }

    public function test_running_restore_during_its_safety_backup_is_not_swept(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $safetyBackup = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
            'started_at' => now()->subMinutes(2),
        ]);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'backup_before_overwrite' => true,
            'pre_restore_backup_run_id' => $safetyBackup->id,
            'status' => RestoreRun::STATUS_RUNNING,
            // Old start / cold heartbeat, but the safety backup is still running.
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_stale_in_place_restore_and_its_safety_backup_are_both_reconciled(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);

        // A worker crashed mid safety backup: both the inline pre-restore backup
        // and its parent in-place restore are stuck RUNNING on the same volume.
        $safetyBackup = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
            'started_at' => now()->subHours(2),
        ]);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'backup_before_overwrite' => true,
            'pre_restore_backup_run_id' => $safetyBackup->id,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // Neither shields the other forever: the safety backup is reconciled on
        // its own liveness, which then unblocks reconciling the restore.
        $this->assertSame(BackupRun::STATUS_FAILED, $safetyBackup->refresh()->status);
        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_queued_restore_waiting_on_a_busy_volume_is_not_swept(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);

        // Holder: a healthy restore currently running on the volume (recent start,
        // so it is not itself reconciled), holding the volume lock.
        RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subMinutes(1),
            'last_heartbeat_at' => now()->subMinutes(1),
        ]);

        // Waiter: queued long ago because WithoutOverlapping keeps releasing it
        // while the holder runs. It is pending, not stale.
        $waiter = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
        $waiter->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_QUEUED, $waiter->refresh()->status);
    }

    public function test_failing_a_running_holder_releases_its_orphaned_volume_lock(): void
    {
        // Simulate a crashed worker that holds the volume lock but never released it.
        $orphaned = Cache::lock(VolumeJobLock::cacheKey('app_data'), 86400);
        $this->assertTrue($orphaned->get());

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subDays(2),
            'last_heartbeat_at' => now()->subDays(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
        // The lock was force-released, so a fresh acquisition succeeds instead of
        // waiting out the 24h expiry.
        $this->assertTrue(Cache::lock(VolumeJobLock::cacheKey('app_data'), 86400)->get());
    }

    public function test_failing_a_running_host_path_backup_releases_its_orphaned_job_lock(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/tmp/vv'],
        ]);
        $job = BackupJob::create([
            'name' => 'Host path job',
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => '/srv/data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_RUNNING,
        ]);

        // Host-path jobs have no volume, so they lock on the backup-job fallback key.
        $lockKey = 'backup-job-'.$job->id;
        $this->assertTrue(Cache::lock(VolumeJobLock::cacheKeyFor($lockKey), 86400)->get());

        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertTrue(Cache::lock(VolumeJobLock::cacheKeyFor($lockKey), 86400)->get());
    }

    public function test_failing_a_queued_standalone_backup_releases_its_orphaned_volume_lock(): void
    {
        // WithoutOverlapping acquires the lock before RunBackup flips the run to
        // running; simulate a worker that crashed in that window.
        $orphaned = Cache::lock(VolumeJobLock::cacheKey('app_data'), 86400);
        $this->assertTrue($orphaned->get());

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        $run->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->refresh()->status);
        // No other run holds the lock, so the orphan is released rather than left
        // to block same-volume work for the 24h TTL.
        $this->assertTrue(Cache::lock(VolumeJobLock::cacheKey('app_data'), 86400)->get(), 'the orphaned lock should be released');
    }

    public function test_a_running_backup_with_a_fresh_heartbeat_is_not_reconciled(): void
    {
        $this->app->instance(DockerProcess::class, $this->recordingDockerProcess());

        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        // Mid a long sequential container stop: running, no backup container yet,
        // started long ago — but the worker keeps refreshing the heartbeat.
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subHour(),
        ]);
        $run->forceFill(['last_heartbeat_at' => now()])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_a_queued_waiter_survives_while_a_terminal_holder_is_still_finalizing(): void
    {
        $this->app->instance(DockerProcess::class, $this->recordingDockerProcess());

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        // Holder finished past the 120s requeue window, but its job still holds the
        // overlap lock while listing archive metadata + sending notifications, and
        // keeps its heartbeat fresh across that finalization.
        $holder = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);
        $holder->forceFill(['last_heartbeat_at' => now()])->save();

        $waiter = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        $waiter->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // The holder is still finalizing (fresh heartbeat), so the waiter is
        // legitimately pending and must not be failed.
        $this->assertSame(BackupRun::STATUS_QUEUED, $waiter->refresh()->status);
    }

    public function test_queued_run_is_not_swept_while_a_terminal_run_still_restarts_containers(): void
    {
        $this->app->instance(DockerProcess::class, $this->recordingDockerProcess());

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);

        // A terminal backup on the volume that still owns stopped containers (its
        // finally has not finished restarting them — pending reconcile restart).
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
            'stopped_container_ids' => ['app-1'],
        ]);

        $waiter = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
        $waiter->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // The stale sweep (which runs before the container-restart sweep) must not
        // fail the waiter while the terminal run is still restarting on its volume.
        $this->assertSame(RestoreRun::STATUS_QUEUED, $waiter->refresh()->status);
    }

    public function test_queued_restore_is_not_swept_just_after_its_lock_holder_finishes(): void
    {
        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);

        // The holder finished seconds ago; the waiter has been released with a
        // delay and has not yet resumed. It must survive the release window.
        RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subSeconds(10),
        ]);

        $waiter = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
        $waiter->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_QUEUED, $waiter->refresh()->status);
    }

    public function test_interrupted_run_with_stopped_containers_is_restarted_and_cleared(): void
    {
        $docker = $this->recordingDockerProcess();
        $this->app->instance(DockerProcess::class, $docker);

        $job = $this->backupJob(BackupJob::STATUS_ERROR);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
            'stopped_container_ids' => ['app-1', 'app-2'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame([
            ['docker', 'start', 'app-1'],
            ['docker', 'start', 'app-2'],
        ], $docker->commands);
        $this->assertNull($run->refresh()->stopped_container_ids);
    }

    public function test_stale_running_run_with_stopped_containers_is_failed_then_restarted(): void
    {
        $docker = $this->recordingDockerProcess();
        $this->app->instance(DockerProcess::class, $docker);

        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
            'stopped_container_ids' => ['app-1'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $run->refresh();
        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->stopped_container_ids);
        $this->assertSame([['docker', 'start', 'app-1']], $docker->commands);
    }

    public function test_restart_failure_keeps_stopped_container_ids_for_retry(): void
    {
        $docker = $this->recordingDockerProcess(successful: false);
        $this->app->instance(DockerProcess::class, $docker);

        $job = $this->backupJob(BackupJob::STATUS_ERROR);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
            'stopped_container_ids' => ['app-1'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(['app-1'], $run->refresh()->stopped_container_ids);
    }

    public function test_terminal_restore_run_with_stopped_containers_is_restarted(): void
    {
        $docker = $this->recordingDockerProcess();
        $this->app->instance(DockerProcess::class, $docker);

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_SAFE_INPLACE,
            'status' => RestoreRun::STATUS_FAILED,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
            'stopped_container_ids' => ['app-1', 'app-2'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame([
            ['docker', 'start', 'app-1'],
            ['docker', 'start', 'app-2'],
        ], $docker->commands);
        $this->assertNull($run->refresh()->stopped_container_ids);
    }

    public function test_terminal_run_without_stopped_containers_is_left_untouched(): void
    {
        $docker = $this->recordingDockerProcess();
        $this->app->instance(DockerProcess::class, $docker);

        $job = $this->backupJob(BackupJob::STATUS_ACTIVE);
        BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame([], $docker->commands);
    }

    public function test_default_threshold_is_short_now_that_liveness_guards_running_runs(): void
    {
        // Liveness checking (not the age threshold) protects genuinely long
        // running backups, so the threshold can stay short — it only gates
        // queued runs a worker never picked up.
        $this->assertSame(15, ReconcileStaleRuns::DEFAULT_THRESHOLD_MINUTES);
    }

    public function test_running_run_with_a_live_container_is_never_reconciled(): void
    {
        $this->app->instance(DockerProcess::class, $this->inspectDockerProcess(alive: true));

        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            // Older than any threshold: only liveness should keep it alive.
            'started_at' => now()->subDays(2),
            'docker_container_id' => 'volumevault-backup-1-abcd1234',
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_running_run_with_a_dead_container_is_reconciled_regardless_of_age(): void
    {
        $this->app->instance(DockerProcess::class, $this->inspectDockerProcess(alive: false));

        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            // Recent, yet its container is gone: liveness overrides the age gate.
            'started_at' => now()->subMinute(),
            'docker_container_id' => 'volumevault-backup-1-abcd1234',
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_unreachable_docker_does_not_fail_a_recent_running_run(): void
    {
        $this->app->instance(DockerProcess::class, $this->unreachableDockerProcess());

        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            // Recent: liveness is indeterminate, so the age gate must protect it.
            'started_at' => now()->subMinute(),
            'docker_container_id' => 'volumevault-backup-1-abcd1234',
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_unreachable_docker_still_reconciles_an_old_running_run_via_age(): void
    {
        $this->app->instance(DockerProcess::class, $this->unreachableDockerProcess());

        $job = $this->backupJob(BackupJob::STATUS_RUNNING);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            // Older than the threshold: even with indeterminate liveness, the age
            // gate reconciles it.
            'started_at' => now()->subDays(2),
            'docker_container_id' => 'volumevault-backup-1-abcd1234',
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->refresh()->status);
    }

    private function recordingDockerProcess(bool $successful = true): DockerProcess
    {
        return new class($successful) extends DockerProcess
        {
            /** @var array<int, array<int, string>> */
            public array $commands = [];

            public function __construct(private readonly bool $successful) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = $command;

                return new DockerProcessResult($command, $this->successful ? 0 : 1, '', $this->successful ? '' : 'boom');
            }
        };
    }

    private function inspectDockerProcess(bool $alive): DockerProcess
    {
        return new class($alive) extends DockerProcess
        {
            /** @var array<int, array<int, string>> */
            public array $commands = [];

            public function __construct(private readonly bool $alive) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = $command;

                if (($command[1] ?? null) === 'inspect') {
                    return new DockerProcessResult(
                        $command,
                        $this->alive ? 0 : 1,
                        $this->alive ? "true\n" : '',
                        $this->alive ? '' : 'Error: No such object: '.($command[4] ?? ''),
                    );
                }

                return new DockerProcessResult($command, 0, '', '');
            }
        };
    }

    private function unreachableDockerProcess(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'inspect') {
                    return new DockerProcessResult(
                        $command,
                        1,
                        '',
                        'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?',
                    );
                }

                return new DockerProcessResult($command, 0, '', '');
            }
        };
    }

    private function backupJob(string $status): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/tmp/vv', 'archive_mount_source' => '/tmp/vv'],
        ]);

        return BackupJob::create([
            'name' => 'Local app backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => $status,
        ]);
    }
}
