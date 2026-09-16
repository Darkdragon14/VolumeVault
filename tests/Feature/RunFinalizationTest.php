<?php

namespace Tests\Feature;

use App\Actions\Backup\BackupJobDeletionRejected;
use App\Actions\Backup\DeleteBackupJob;
use App\Actions\Backup\DeleteBackupJobGroup;
use App\Actions\Backup\RunBackup;
use App\Actions\Backup\UpdateBackupJobGroup;
use App\Actions\Notifications\DeleteNotificationChannel;
use App\Actions\Notifications\MutateNotificationChannel;
use App\Actions\Notifications\NotificationChannelMutationBlocked;
use App\Actions\Runs\CreateRunFinalizations;
use App\Actions\Runs\ProcessRunFinalization;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\RunFinalization;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Database\QueryException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalization_sweep_overlap_lock_expires_after_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'volumevault:sweep-run-finalizations'),
        );

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
    }

    public function test_database_requires_exactly_one_run_owner(): void
    {
        foreach ([
            [],
            ['backup_run_id' => $this->backupRun()->id, 'restore_run_id' => $this->restoreRun()->id],
        ] as $owners) {
            try {
                RunFinalization::create($owners + [
                    'type' => RunFinalization::TYPE_FINISHED_NOTIFICATION,
                    'deduplication_key' => (string) Str::uuid(),
                    'available_at' => now(),
                ]);
                $this->fail('Expected the owner constraint to reject the finalization.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_schema_migration_restores_missing_owner_triggers_and_can_run_twice(): void
    {
        DB::statement('DROP TRIGGER run_finalizations_one_owner_insert');
        DB::statement('DROP TRIGGER run_finalizations_one_owner_update');
        $migration = require database_path('migrations/2026_08_28_010000_create_run_finalizations_table.php');

        $migration->up();
        $migration->up();

        $triggers = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'run_finalizations_one_owner_%'");

        $this->assertCount(2, $triggers);
    }

    public function test_restore_and_group_processors_send_to_only_the_frozen_explicit_channel(): void
    {
        $restore = $this->restoreRun(['status' => RestoreRun::STATUS_FAILED]);
        $restoreChannel = $this->channel('Restore');
        $restore->job->notificationChannels()->attach($restoreChannel);
        $group = $this->group();
        $groupRun = $this->groupRun($group, ['status' => BackupGroupRun::STATUS_FAILED]);
        $groupChannel = $this->channel('Group');
        $group->notificationChannels()->attach($groupChannel);

        $creator = app(CreateRunFinalizations::class);
        $restoreId = $creator->createRestoreNotifications($restore, $restore->job)[0];
        $groupId = $creator->createGroupNotifications($groupRun, $group)[0];
        $restore->job->notificationChannels()->detach($restoreChannel);
        $group->notificationChannels()->detach($groupChannel);

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldNotReceive('sendRestoreRun');
        $notifier->shouldNotReceive('sendGroupRunFinished');
        $notifier->shouldReceive('sendRestoreRunFinishedToChannel')->once()->withArgs(
            fn (RestoreRun $run, NotificationChannel $channel): bool => $run->is($restore) && $channel->is($restoreChannel),
        );
        $notifier->shouldReceive('sendGroupRunFinishedToChannel')->once()->withArgs(
            fn (BackupGroupRun $run, NotificationChannel $channel): bool => $run->is($groupRun) && $channel->is($groupChannel),
        );
        $processor = new ProcessRunFinalization(Mockery::mock(RunBackup::class), $notifier);

        $processor->handle($restoreId);
        $processor->handle($groupId);

        $this->assertSame(RunFinalization::STATUS_COMPLETED, RunFinalization::findOrFail($restoreId)->status);
        $this->assertSame(RunFinalization::STATUS_COMPLETED, RunFinalization::findOrFail($groupId)->status);
    }

    public function test_queue_failed_hooks_leave_lifecycle_recovery_to_persisted_run_state(): void
    {
        Queue::fake();
        $restore = $this->restoreRun();
        $restore->job->notificationChannels()->attach($this->channel('Restore failed hook'));
        $group = $this->group();
        $group->notificationChannels()->attach($this->channel('Group failed hook'));
        $groupRun = $this->groupRun($group);
        $restore->forceFill(['dispatch_attempted_at' => now(), 'dispatch_published_at' => now()])->save();
        $groupRun->forceFill(['dispatch_attempted_at' => now(), 'dispatch_published_at' => now()])->save();

        (new RunRestoreJob($restore->id))->failed(new RuntimeException('restore queue failure'));
        (new RunBackupGroupJob($groupRun->id))->failed(new RuntimeException('group queue failure'));

        $this->assertSame(RestoreRun::STATUS_QUEUED, $restore->fresh()->status);
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $groupRun->fresh()->status);
        $this->assertSame(0, $restore->finalizations()->count());
        $this->assertSame(0, $groupRun->finalizations()->count());
        $this->assertNull($restore->fresh()->dispatch_attempted_at);
        $this->assertNull($restore->fresh()->dispatch_published_at);
        $this->assertNull($groupRun->fresh()->dispatch_attempted_at);
        $this->assertNull($groupRun->fresh()->dispatch_published_at);
    }

    public function test_stale_reconciliation_creates_restore_and_group_terminal_rows_while_cancellation_is_silent(): void
    {
        Queue::fake();
        $restore = $this->restoreRun([
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        $restore->job->notificationChannels()->attach($this->channel('Stale restore'));
        $group = $this->group();
        $group->notificationChannels()->attach($this->channel('Stale group'));
        $groupRun = $this->groupRun($group, [
            'status' => BackupGroupRun::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        $cancelledRestore = $this->restoreRun(['status' => RestoreRun::STATUS_CANCELLED, 'finished_at' => now()]);
        $cancelledGroup = $this->groupRun($this->group(), ['status' => BackupGroupRun::STATUS_CANCELLED, 'finished_at' => now()]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(RestoreRun::STATUS_FAILED, $restore->fresh()->status);
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $groupRun->fresh()->status);
        $this->assertSame(1, $restore->finalizations()->count());
        $this->assertSame(1, $groupRun->finalizations()->count());
        $this->assertSame(0, $cancelledRestore->finalizations()->count());
        $this->assertSame(0, $cancelledGroup->finalizations()->count());
    }

    public function test_owner_and_channel_guards_remain_until_finalizations_are_terminal(): void
    {
        $restore = $this->restoreRun(['status' => RestoreRun::STATUS_FAILED]);
        $restoreChannel = $this->channel('Guarded restore');
        $restore->job->notificationChannels()->attach($restoreChannel);
        $restoreFinalization = RunFinalization::findOrFail(
            app(CreateRunFinalizations::class)->createRestoreNotifications($restore, $restore->job)[0],
        );
        $group = $this->group();
        $groupRun = $this->groupRun($group, ['status' => BackupGroupRun::STATUS_FAILED]);
        $groupChannel = $this->channel('Guarded group');
        $group->notificationChannels()->attach($groupChannel);
        $groupFinalization = RunFinalization::findOrFail(
            app(CreateRunFinalizations::class)->createGroupNotifications($groupRun, $group)[0],
        );

        foreach ([$restoreChannel, $groupChannel] as $channel) {
            try {
                app(MutateNotificationChannel::class)->update($channel, ['name' => 'Changed']);
                $this->fail('Expected notification channel mutation to be blocked.');
            } catch (NotificationChannelMutationBlocked) {
                $this->assertTrue(true);
            }
        }

        try {
            app(DeleteBackupJob::class)->handle($restore->job);
            $this->fail('Expected backup job deletion to be blocked.');
        } catch (BackupJobDeletionRejected $exception) {
            $this->assertSame(BackupJobDeletionRejected::FINALIZATION_PENDING, $exception->reason);
        }

        foreach ([
            fn () => app(UpdateBackupJobGroup::class)->setNotificationsEnabled($group, false),
            fn () => app(DeleteBackupJobGroup::class)->handle($group),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected backup group mutation to be blocked.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        foreach ([$restoreFinalization, $groupFinalization] as $finalization) {
            $finalization->forceFill([
                'status' => RunFinalization::STATUS_FAILED,
                'available_at' => null,
                'finished_at' => now(),
            ])->save();
        }

        app(DeleteNotificationChannel::class)->handle($restoreChannel);
        app(DeleteNotificationChannel::class)->handle($groupChannel);
        app(DeleteBackupJob::class)->handle($restore->job);
        app(DeleteBackupJobGroup::class)->handle($group);

        $this->assertModelMissing($restoreChannel);
        $this->assertModelMissing($groupChannel);
        $this->assertModelMissing($group);
    }

    private function backupRun(): BackupRun
    {
        return BackupRun::create([
            'backup_job_id' => $this->job()->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
    }

    private function restoreRun(array $attributes = []): RestoreRun
    {
        $job = $this->job();

        return RestoreRun::create($attributes + [
            'backup_job_id' => $job->id,
            'backup_destination_id' => $job->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'restored_'.Str::lower(Str::random(8)),
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
    }

    private function group(): BackupJobGroup
    {
        return BackupJobGroup::create([
            'name' => 'Group '.Str::random(8),
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
        ]);
    }

    private function groupRun(BackupJobGroup $group, array $attributes = []): BackupGroupRun
    {
        return BackupGroupRun::create($attributes + [
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
        ]);
    }

    private function job(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local '.Str::random(8),
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);

        return BackupJob::create([
            'name' => 'Job '.Str::random(8),
            'volume_name' => 'volume_'.Str::lower(Str::random(8)),
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function channel(string $name): NotificationChannel
    {
        return NotificationChannel::create([
            'name' => $name,
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/'.Str::slug($name),
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);
    }
}
