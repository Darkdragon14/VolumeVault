<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class VolumeJobLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_and_in_place_restore_on_the_same_volume_share_one_lock_key(): void
    {
        $job = $this->backupJob();

        $backupRun = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);

        $restoreRun = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data', // in-place targets the source volume
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);

        $backupLock = $this->overlapMiddleware(new RunBackupJob($backupRun->id));
        $restoreLock = $this->overlapMiddleware(new RunRestoreJob($restoreRun->id));

        // Both must resolve to the identical effective lock key so the queue
        // serializes them — same key string AND shared (no per-class namespace).
        $this->assertTrue($backupLock->shareKey);
        $this->assertTrue($restoreLock->shareKey);
        $this->assertSame('volume-app_data', $backupLock->key);
        $this->assertSame($backupLock->key, $restoreLock->key);
        $this->assertSame($backupLock->getLockKey($backupLock), $restoreLock->getLockKey($restoreLock));
    }

    public function test_new_volume_restore_keys_on_its_own_volume(): void
    {
        $job = $this->backupJob();

        $restoreRun = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);

        $lock = $this->overlapMiddleware(new RunRestoreJob($restoreRun->id));

        // A new-volume restore writes a fresh volume, so it must not contend with
        // a backup of the source volume.
        $this->assertSame('volume-app_data_restored', $lock->key);
    }

    public function test_jobs_release_lock_losers_and_retry_until_a_deadline(): void
    {
        $job = $this->backupJob();
        $backupRun = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        $restoreRun = RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);

        foreach ([new RunBackupJob($backupRun->id), new RunRestoreJob($restoreRun->id)] as $queueJob) {
            $lock = $this->overlapMiddleware($queueJob);

            // A lock loser is released back to the queue (not dropped)...
            $this->assertNotNull($lock->releaseAfter);
            $this->assertGreaterThan(0, $lock->releaseAfter);

            // ...and retries are bounded by a deadline rather than a single
            // attempt, so a released job waits to serialize instead of failing.
            $this->assertFalse(property_exists($queueJob, 'tries'));
            $this->assertInstanceOf(\DateTimeInterface::class, $queueJob->retryUntil());
        }
    }

    private function overlapMiddleware(object $job): WithoutOverlapping
    {
        foreach ($job->middleware() as $middleware) {
            if ($middleware instanceof WithoutOverlapping) {
                return $middleware;
            }
        }

        $this->fail('Job does not register a WithoutOverlapping middleware.');
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
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }
}
