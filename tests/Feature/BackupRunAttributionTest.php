<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\RunBackup;
use App\Actions\Restore\RunPreRestoreBackup;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\Logging\AppendRunLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BackupRunAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_web_backup_is_attributed_to_the_authenticated_user(): void
    {
        Queue::fake();
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $job = $this->backupJob();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('backup-jobs.run', $job))
            ->assertRedirect();

        $run = BackupRun::first();
        $this->assertNotNull($run);
        $this->assertSame(BackupRun::TRIGGER_MANUAL, $run->trigger);
        // The backup records who triggered it for audit purposes.
        $this->assertSame($user->id, $run->initiated_by_user_id);
    }

    public function test_manual_api_backup_is_attributed_to_the_token_owner(): void
    {
        Queue::fake();
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $job = $this->backupJob();

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('openclaw-write', ['read', 'write'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/backup-jobs/{$job->id}/run")
            ->assertAccepted()
            ->assertJsonPath('data.initiated_by_user_id', $admin->id);
    }

    public function test_scheduled_backup_has_no_initiator(): void
    {
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        $job = $this->backupJob();

        $run = app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_SCHEDULED);

        $this->assertSame(BackupRun::TRIGGER_SCHEDULED, $run->trigger);
        $this->assertSame(BackupJob::SOURCE_TYPE_DOCKER_VOLUME, $run->source_type_snapshot);
        $this->assertSame('app_data', $run->source_volume_name);
        $this->assertSame($job->backup_destination_id, $run->backup_destination_id_snapshot);
        $this->assertSame('Local', $run->backup_destination_name);
        $this->assertSame($job->destination->locatorFingerprint(), $run->backup_destination_locator_fingerprint);
        $this->assertSame('volumevault-app_data-run-'.$run->id.'.tar.gz', $run->backup_filename);
        // Scheduled runs have no logged-in user; the initiator stays null and the
        // UI shows it as "—", mirroring automated restores.
        $this->assertNull($run->initiated_by_user_id);
    }

    public function test_pre_restore_backup_inherits_the_restore_initiator(): void
    {
        $user = User::factory()->admin()->create();
        $job = $this->backupJob();

        $restore = RestoreRun::create([
            'backup_job_id' => $job->id,
            'initiated_by_user_id' => $user->id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_RUNNING,
        ]);

        // The full backup pipeline is exercised elsewhere; here we only care that
        // the safety backup copies the restore's initiator, so we stub RunBackup
        // to mark the created BackupRun as a success.
        $runBackup = Mockery::mock(RunBackup::class);
        $runBackup->shouldReceive('handle')
            ->once()
            ->andReturnUsing(fn (BackupRun $backup) => $backup->forceFill([
                'status' => BackupRun::STATUS_SUCCESS,
                'backup_key' => 'safety-backup.tar.gz',
            ])->save());

        $action = new RunPreRestoreBackup($runBackup, app(AppendRunLog::class), app(CreateBackupRunRecord::class));
        $action->handle($restore);

        $backup = BackupRun::where('trigger', BackupRun::TRIGGER_PRE_RESTORE)->first();
        $this->assertNotNull($backup);
        $this->assertSame($user->id, $backup->initiated_by_user_id);
        $this->assertSame('app_data', $backup->source_volume_name);
        $this->assertSame($job->backup_destination_id, $backup->backup_destination_id_snapshot);
        $this->assertSame('volumevault-app_data-run-'.$backup->id.'.tar.gz', $backup->backup_filename);
    }

    private function backupJob(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => sys_get_temp_dir()],
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
