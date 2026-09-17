<?php

namespace Tests\Feature;

use App\Actions\Backup\BackupJobDeletionRejected;
use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\DeleteBackupJob;
use App\Actions\Backup\DeleteBackupJobGroup;
use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Backup\UpdateBackupJobGroup;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Actions\Destinations\MutateDestination;
use App\Actions\Notifications\MutateNotificationChannel;
use App\Actions\Restore\CreateRestoreRun;
use App\Http\Controllers\Api\V1\DestinationController;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class RealSqliteConcurrencyTest extends TestCase
{
    private const IPC_TIMEOUT_SECONDS = 15;

    private string $databasePath;

    /** @var array<int, resource> */
    private array $children = [];

    /** @var array<string, mixed> */
    private array $originalDatabaseConfig = [];

    /** @var array<string, mixed> */
    private array $originalCacheConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for process concurrency tests.');
        }

        $path = tempnam(sys_get_temp_dir(), 'volumevault-concurrency-');

        if ($path === false) {
            throw new RuntimeException('Unable to create the temporary SQLite database.');
        }

        $this->databasePath = $path;
        File::ensureDirectoryExists($this->databasePath.'-backups');
        File::put($this->databasePath.'-backups/application.tar.gz', 'fake-archive');
        $this->originalDatabaseConfig = config('database');
        $this->originalCacheConfig = config('cache');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'database.connections.sqlite.busy_timeout' => 5000,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.synchronous' => 'NORMAL',
            'cache.default' => 'file',
            'volumevault.host_path_allowlist' => [sys_get_temp_dir()],
        ]);

        Cache::forgetDriver('array');
        Cache::forgetDriver('file');
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::disconnect('sqlite');
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid => $stream) {
            @fclose($stream);

            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGKILL);
            }

            @pcntl_waitpid($pid, $status);
        }

        DB::purge('sqlite');

        if (isset($this->databasePath)) {
            @unlink($this->databasePath);
            @unlink($this->databasePath.'-wal');
            @unlink($this->databasePath.'-shm');
            File::deleteDirectory($this->databasePath.'-backups');
        }

        if ($this->originalDatabaseConfig !== []) {
            config(['database' => $this->originalDatabaseConfig]);
        }

        if ($this->originalCacheConfig !== []) {
            config(['cache' => $this->originalCacheConfig]);
            Cache::forgetDriver('file');
            Cache::forgetDriver('array');
        }

        parent::tearDown();
    }

    public function test_backup_failure_finalization_has_no_intermediate_committed_state(): void
    {
        [$job, $run] = $this->runningBackup();

        $child = $this->fork(function (callable $barrier) use ($job, $run): bool {
            $paused = false;

            Event::listen('eloquent.updated: '.BackupJob::class, function (BackupJob $updated) use ($barrier, $job, &$paused): void {
                if (! $paused && $updated->id === $job->id && $updated->status === BackupJob::STATUS_ERROR) {
                    $paused = true;
                    $barrier('job-finalized-before-run');
                }
            });

            return app(RunBackup::class)->markFailed($run, new RuntimeException('concurrent failure'));
        });

        $this->awaitBarrier($child, 'job-finalized-before-run');

        $this->assertSame(BackupJob::STATUS_RUNNING, BackupJob::query()->findOrFail($job->id)->status);
        $this->assertSame(BackupRun::STATUS_RUNNING, BackupRun::query()->findOrFail($run->id)->status);

        $this->releaseBarrier($child);
        $this->assertTrue($this->finish($child));

        $committedJob = BackupJob::query()->findOrFail($job->id);
        $committedRun = BackupRun::query()->findOrFail($run->id);

        $this->assertSame(BackupJob::STATUS_ERROR, $committedJob->status);
        $this->assertSame('concurrent failure', $committedJob->last_error);
        $this->assertSame(BackupRun::STATUS_FAILED, $committedRun->status);
        $this->assertSame('concurrent failure', $committedRun->error_message);
    }

    public function test_backup_run_creation_is_invisible_until_job_and_activity_writes_commit(): void
    {
        $job = $this->backupJob();
        $originalNextRunAt = $job->next_run_at;

        $child = $this->fork(function (callable $barrier) use ($job): int {
            Event::listen('eloquent.created: '.BackupRun::class, function (BackupRun $run) use ($barrier, $job): void {
                if ($run->backup_job_id === $job->id) {
                    $barrier('backup-run-inserted');
                }
            });

            return app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL)->id;
        });

        $this->awaitBarrier($child, 'backup-run-inserted');

        $this->assertSame(0, BackupRun::query()->where('backup_job_id', $job->id)->count());
        $this->assertSame(0, ActivityLog::query()->where('event_type', 'backup_run_queued')->count());
        $this->assertTrue(BackupJob::query()->findOrFail($job->id)->next_run_at->equalTo($originalNextRunAt));

        $this->releaseBarrier($child);
        $runId = $this->finish($child);

        $this->assertNotNull(BackupRun::query()->find($runId));
        $this->assertTrue(ActivityLog::query()->where('event_type', 'backup_run_queued')->where('subject_id', $runId)->exists());
        $this->assertFalse(BackupJob::query()->findOrFail($job->id)->next_run_at->equalTo($originalNextRunAt));
    }

    public function test_restore_run_and_activity_are_invisible_until_the_creation_transaction_commits(): void
    {
        $job = $this->backupJob();

        $child = $this->fork(function (callable $barrier) use ($job): int {
            Event::listen('eloquent.created: '.RestoreRun::class, function (RestoreRun $run) use ($barrier, $job): void {
                if ($run->backup_job_id === $job->id) {
                    $barrier('restore-run-inserted');
                }
            });

            return app(CreateRestoreRun::class)->handle($job, [
                'selected_backup_key' => 'application.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
                'target_volume_name' => 'restored_application',
            ])->id;
        });

        $this->awaitBarrier($child, 'restore-run-inserted');

        $this->assertSame(0, RestoreRun::query()->where('backup_job_id', $job->id)->count());
        $this->assertSame(0, ActivityLog::query()->where('event_type', 'restore_run_started')->count());

        $this->releaseBarrier($child);
        $runId = $this->finish($child);

        $this->assertNotNull(RestoreRun::query()->find($runId));
        $this->assertTrue(ActivityLog::query()->where('event_type', 'restore_run_started')->where('subject_id', $runId)->exists());
    }

    public function test_destination_deletion_retries_after_concurrent_run_creation_commits(): void
    {
        $job = $this->backupJob();
        $destinationId = $job->backup_destination_id;

        $creator = $this->fork(function (callable $barrier) use ($job): int {
            Event::listen('eloquent.created: '.BackupRun::class, function (BackupRun $run) use ($barrier, $job): void {
                if ($run->backup_job_id === $job->id) {
                    $barrier('run-created-but-uncommitted');
                }
            });

            return app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL)->id;
        });

        $this->awaitBarrier($creator, 'run-created-but-uncommitted');

        $deleter = $this->fork(function (callable $barrier) use ($destinationId): array {
            Event::listen('eloquent.deleting: '.BackupDestination::class, function (BackupDestination $destination) use ($barrier, $destinationId): void {
                if ($destination->id === $destinationId) {
                    $barrier('destination-ready-to-delete');
                }
            });

            try {
                app(DestinationController::class)->destroy(
                    BackupDestination::query()->findOrFail($destinationId),
                    app(MutateDestination::class),
                );
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        });

        $this->awaitBarrier($deleter, 'destination-ready-to-delete');

        $this->releaseBarrier($creator);
        $runId = $this->finish($creator);
        $this->assertNotNull(BackupRun::query()->find($runId));

        $this->releaseBarrier($deleter);
        $errors = $this->finish($deleter);

        $this->assertArrayHasKey('destination', $errors);
        $this->assertStringContainsString('in progress', $errors['destination'][0]);
        $this->assertNotNull(BackupDestination::query()->find($destinationId));
        $this->assertNotNull(BackupRun::query()->find($runId));
    }

    public function test_backup_job_deletion_retries_after_concurrent_run_creation_and_preserves_both_rows(): void
    {
        $job = $this->backupJob();

        $creator = $this->fork(function (callable $barrier) use ($job): int {
            Event::listen('eloquent.created: '.BackupRun::class, function (BackupRun $run) use ($barrier, $job): void {
                if ($run->backup_job_id === $job->id) {
                    $barrier('job-run-created-but-uncommitted');
                }
            });

            return app(CreateBackupRun::class)->handle($job, BackupRun::TRIGGER_MANUAL)->id;
        });

        $this->awaitBarrier($creator, 'job-run-created-but-uncommitted');

        $deleter = $this->fork(function (callable $barrier) use ($job): string {
            Event::listen('eloquent.deleting: '.BackupJob::class, function (BackupJob $deleting) use ($barrier, $job): void {
                if ($deleting->id === $job->id) {
                    $barrier('job-ready-to-delete');
                }
            });

            try {
                app(DeleteBackupJob::class)->handle(BackupJob::query()->findOrFail($job->id));
            } catch (BackupJobDeletionRejected $exception) {
                return $exception->reason;
            }

            return 'deleted';
        });

        $this->awaitBarrier($deleter, 'job-ready-to-delete');
        $this->releaseBarrier($creator);
        $runId = $this->finish($creator);

        $this->releaseBarrier($deleter);
        $this->assertSame(BackupJobDeletionRejected::RUN_IN_PROGRESS, $this->finish($deleter));
        $this->assertNotNull(BackupJob::query()->find($job->id));
        $this->assertNotNull(BackupRun::query()->find($runId));
    }

    public function test_group_deletion_serializes_with_existing_group_attachment(): void
    {
        $group = $this->backupGroup();
        $destination = $this->destination();
        $admin = User::factory()->admin()->create();

        $deleter = $this->fork(function (callable $barrier) use ($group): bool {
            Event::listen('eloquent.deleting: '.BackupJobGroup::class, function (BackupJobGroup $deleting) use ($barrier, $group): void {
                if ($deleting->id === $group->id) {
                    $barrier('group-ready-to-delete-before-attachment');
                }
            });

            app(DeleteBackupJobGroup::class)->handle(BackupJobGroup::query()->findOrFail($group->id));

            return true;
        });

        $this->awaitBarrier($deleter, 'group-ready-to-delete-before-attachment');

        $attacher = $this->fork(function (callable $barrier) use ($admin, $destination, $group): array {
            $barrier('attachment-starting');

            $response = $this->actingAs($admin)->post(route('backup-jobs.store'), [
                'name' => 'Concurrent group member',
                'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
                'host_path' => sys_get_temp_dir(),
                'backup_destination_id' => $destination->id,
                'planning_mode' => 'group',
                'group_selection' => 'existing',
                'backup_job_group_id' => $group->id,
            ]);

            return ['status' => $response->getStatusCode()];
        });

        $this->awaitBarrier($attacher, 'attachment-starting');
        $this->releaseBarrier($attacher);
        $this->releaseBarrier($deleter);

        $this->assertTrue($this->finish($deleter));
        $attachment = $this->finish($attacher);

        $this->assertSame(302, $attachment['status']);
        $this->assertNull(BackupJobGroup::query()->find($group->id));
        $this->assertFalse(BackupJob::query()->where('name', 'Concurrent group member')->exists());
    }

    public function test_group_deletion_serializes_with_group_run_creation(): void
    {
        $group = $this->backupGroup();

        $deleter = $this->fork(function (callable $barrier) use ($group): bool {
            Event::listen('eloquent.deleting: '.BackupJobGroup::class, function (BackupJobGroup $deleting) use ($barrier, $group): void {
                if ($deleting->id === $group->id) {
                    $barrier('group-ready-to-delete-before-run');
                }
            });

            app(DeleteBackupJobGroup::class)->handle(BackupJobGroup::query()->findOrFail($group->id));

            return true;
        });

        $this->awaitBarrier($deleter, 'group-ready-to-delete-before-run');

        $creator = $this->fork(function (callable $barrier) use ($group): array {
            $barrier('group-run-creation-starting');

            try {
                app(CreateBackupGroupRun::class)->handle($group, BackupGroupRun::TRIGGER_MANUAL);
            } catch (ValidationException $exception) {
                return $exception->errors();
            }

            return [];
        });

        $this->awaitBarrier($creator, 'group-run-creation-starting');
        $this->releaseBarrier($creator);
        $this->releaseBarrier($deleter);

        $this->assertTrue($this->finish($deleter));
        $errors = $this->finish($creator);

        $this->assertArrayHasKey('group', $errors);
        $this->assertNull(BackupJobGroup::query()->find($group->id));
        $this->assertSame(0, BackupGroupRun::query()->where('backup_job_group_id', $group->id)->count());
    }

    public function test_group_edit_serializes_with_run_creation_and_commits_member_schedule_atomically(): void
    {
        $group = $this->backupGroup();
        $member = $this->backupJob([
            'backup_job_group_id' => $group->id,
            'next_run_at' => null,
        ]);

        $updater = $this->fork(function (callable $barrier) use ($group): bool {
            Event::listen('eloquent.updated: '.BackupJobGroup::class, function (BackupJobGroup $updated) use ($barrier, $group): void {
                if ($updated->id === $group->id && $updated->name === 'Concurrent edited group') {
                    $barrier('group-edit-written-but-uncommitted');
                }
            });

            app(UpdateBackupJobGroup::class)->handle($group, [
                'name' => 'Concurrent edited group',
                'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '06:00'],
                'timezone' => 'UTC',
                'failure_policy' => BackupJobGroup::FAILURE_POLICY_STOP,
            ]);

            return true;
        });

        $this->awaitBarrier($updater, 'group-edit-written-but-uncommitted');
        $this->assertSame('Concurrency group', BackupJobGroup::query()->findOrFail($group->id)->name);
        $this->assertSame(['time' => '02:00'], BackupJob::query()->findOrFail($member->id)->schedule_config);

        $creator = $this->fork(function (callable $barrier) use ($group): int {
            $barrier('concurrent-group-run-starting');

            return app(CreateBackupGroupRun::class)->handle($group, BackupGroupRun::TRIGGER_MANUAL)->id;
        });

        $this->awaitBarrier($creator, 'concurrent-group-run-starting');
        $this->releaseBarrier($creator);
        $this->releaseBarrier($updater);

        $this->assertTrue($this->finish($updater));
        $runId = $this->finish($creator);

        $freshGroup = BackupJobGroup::query()->findOrFail($group->id);
        $freshMember = BackupJob::query()->findOrFail($member->id);
        $this->assertSame('Concurrent edited group', $freshGroup->name);
        $this->assertSame(BackupJobGroup::FAILURE_POLICY_STOP, $freshGroup->failure_policy);
        $this->assertSame('0 6 * * *', $freshGroup->cron_expression);
        $this->assertSame(['time' => '06:00'], $freshMember->schedule_config);
        $this->assertSame('0 6 * * *', $freshMember->cron_expression);
        $this->assertNotNull(BackupGroupRun::query()->find($runId));
    }

    public function test_group_attachment_waits_for_concurrent_run_creation_and_is_rejected(): void
    {
        $group = $this->backupGroup();
        $this->backupJob(['backup_job_group_id' => $group->id, 'next_run_at' => null]);
        $attachingJob = $this->backupJob();
        $admin = User::factory()->admin()->create();

        $creator = $this->fork(function (callable $barrier) use ($group): int {
            Event::listen('eloquent.created: '.BackupGroupRun::class, function (BackupGroupRun $run) use ($barrier, $group): void {
                if ($run->backup_job_group_id === $group->id) {
                    $barrier('group-run-created-before-attachment');
                }
            });

            return app(CreateBackupGroupRun::class)->handle($group, BackupGroupRun::TRIGGER_MANUAL)->id;
        });

        $this->awaitBarrier($creator, 'group-run-created-before-attachment');

        $attacher = $this->fork(function (callable $barrier) use ($admin, $attachingJob, $group): array {
            $barrier('group-attachment-starting');
            $response = $this->actingAs($admin)->put(route('backup-jobs.update', $attachingJob), [
                'name' => $attachingJob->name,
                'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
                'host_path' => $attachingJob->host_path,
                'backup_destination_id' => $attachingJob->backup_destination_id,
                'planning_mode' => 'group',
                'group_selection' => 'existing',
                'backup_job_group_id' => $group->id,
            ]);

            return [
                'status' => $response->getStatusCode(),
            ];
        });

        $this->awaitBarrier($attacher, 'group-attachment-starting');
        $this->releaseBarrier($attacher);
        $this->releaseBarrier($creator);

        $runId = $this->finish($creator);
        $attachment = $this->finish($attacher);

        $this->assertNotNull(BackupGroupRun::query()->find($runId));
        $this->assertSame(302, $attachment['status']);
        $this->assertNull($attachingJob->fresh()->backup_job_group_id);
    }

    public function test_no_runnable_group_decision_has_no_intermediate_committed_state(): void
    {
        $group = $this->backupGroup();
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
        ]);

        $worker = $this->fork(function (callable $barrier) use ($run): bool {
            Event::listen('eloquent.created: '.ActivityLog::class, function (ActivityLog $activity) use ($barrier): void {
                if ($activity->event_type === 'backup_group_run_failed') {
                    $barrier('no-runnable-state-written-but-uncommitted');
                }
            });

            app(RunBackupGroup::class)->handle($run);

            return true;
        });

        $this->awaitBarrier($worker, 'no-runnable-state-written-but-uncommitted');
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, BackupGroupRun::query()->findOrFail($run->id)->status);
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, BackupJobGroup::query()->findOrFail($group->id)->status);
        $this->assertFalse(ActivityLog::query()->where('event_type', 'backup_group_run_failed')->exists());

        $this->releaseBarrier($worker);
        $this->assertTrue($this->finish($worker));
        $this->assertSame(BackupGroupRun::STATUS_FAILED, BackupGroupRun::query()->findOrFail($run->id)->status);
        $this->assertSame(BackupJobGroup::STATUS_ERROR, BackupJobGroup::query()->findOrFail($group->id)->status);
        $this->assertTrue(ActivityLog::query()->where('event_type', 'backup_group_run_failed')->exists());
    }

    public function test_web_manual_source_update_is_atomic_and_competing_managed_job_creation_retries_without_a_duplicate(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'vol_a', 'exists' => true]);
        DockerVolume::create(['name' => 'vol_b', 'exists' => true]);
        $job = $this->backupJob([
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'host_path' => null,
            'backup_destination_id' => $destination->id,
        ], false);
        $admin = User::factory()->admin()->create();

        $updater = $this->fork(function (callable $barrier) use ($admin, $destination, $job): array {
            Event::listen('eloquent.updated: '.BackupJob::class, function (BackupJob $updated) use ($barrier, $job): void {
                if ($updated->id === $job->id && $updated->volume_name === 'vol_b') {
                    $barrier('manual-source-updated-but-uncommitted');
                }
            });

            $response = $this->actingAs($admin)->put(route('backup-jobs.update', $job), [
                'name' => 'Updated manual job',
                'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                'volume_name' => 'vol_b',
                'backup_destination_id' => $destination->id,
                'schedule_type' => BackupJob::SCHEDULE_DAILY,
                'schedule_config' => ['time' => '03:00'],
            ]);

            return [
                'status' => $response->getStatusCode(),
                'volume_name' => BackupJob::query()->findOrFail($job->id)->volume_name,
            ];
        });

        $this->awaitBarrier($updater, 'manual-source-updated-but-uncommitted');

        $this->assertSame('vol_a', BackupJob::query()->findOrFail($job->id)->volume_name);
        $this->assertFalse(BackupJob::query()->where('volume_name', 'vol_b')->exists());

        $competitor = $this->fork(function (callable $barrier) use ($destination): string {
            $paused = false;

            Event::listen('eloquent.creating: '.BackupJob::class, function (BackupJob $creating) use ($barrier, &$paused): void {
                if (! $paused && $creating->isDockerLabelManaged() && $creating->volume_name === 'vol_b') {
                    $paused = true;
                    $barrier('managed-job-ready-to-create-from-old-snapshot');
                }
            });

            return app(WithDockerLabelMutationLocks::class)->handle(
                [$destination->id],
                function () use ($destination): string {
                    $manualJobExists = BackupJob::query()
                        ->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_MANUAL)
                        ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
                        ->where('volume_name', 'vol_b')
                        ->exists();

                    if ($manualJobExists) {
                        return 'manual-conflict';
                    }

                    BackupJob::create([
                        'name' => 'Competing managed job',
                        'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                        'volume_name' => 'vol_b',
                        'backup_destination_id' => $destination->id,
                        'schedule_type' => BackupJob::SCHEDULE_DAILY,
                        'schedule_config' => ['time' => '04:00'],
                        'cron_expression' => '0 4 * * *',
                        'status' => BackupJob::STATUS_ACTIVE,
                        'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
                        'configuration_key' => hash('sha256', 'competing-managed-vol-b'),
                    ]);

                    return 'created';
                },
                ['vol_b'],
            );
        });

        $this->awaitBarrier($competitor, 'managed-job-ready-to-create-from-old-snapshot');

        $this->releaseBarrier($updater);
        $updateResult = $this->finish($updater);
        $this->assertSame(302, $updateResult['status']);
        $this->assertSame('vol_b', $updateResult['volume_name']);

        $this->releaseBarrier($competitor);
        $this->assertSame('manual-conflict', $this->finish($competitor));

        $this->assertSame('vol_b', BackupJob::query()->findOrFail($job->id)->volume_name);
        $this->assertSame(1, BackupJob::query()->where('volume_name', 'vol_b')->count());
        $this->assertFalse(BackupJob::query()->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)->exists());
    }

    public function test_competing_default_channel_creations_serialize_to_one_default(): void
    {
        $first = $this->fork(function (callable $barrier): int {
            Event::listen('eloquent.created: '.NotificationChannel::class, function (NotificationChannel $created) use ($barrier): void {
                if ($created->name === 'First concurrent default') {
                    $barrier('first-default-created-but-uncommitted');
                }
            });

            return app(MutateNotificationChannel::class)->create($this->notificationChannelAttributes('First concurrent default'))->id;
        });

        $this->awaitBarrier($first, 'first-default-created-but-uncommitted');

        $second = $this->fork(function (callable $barrier): int {
            $paused = false;

            DB::listen(function ($query) use ($barrier, &$paused): void {
                if (! $paused && DB::transactionLevel() > 0 && str_contains($query->sql, 'from "docker_label_backup_settings"')) {
                    $paused = true;
                    $barrier('second-default-read-serialization-row');
                }
            });

            return app(MutateNotificationChannel::class)->create($this->notificationChannelAttributes('Second concurrent default'))->id;
        });

        $this->awaitBarrier($second, 'second-default-read-serialization-row');
        $this->releaseBarrier($first);
        $this->finish($first);
        $this->releaseBarrier($second);
        $secondId = $this->finish($second);

        $this->assertSame([$secondId], NotificationChannel::query()->where('is_default', true)->pluck('id')->all());
    }

    /** @return array{BackupJob, BackupRun} */
    private function runningBackup(): array
    {
        $job = $this->backupJob(['status' => BackupJob::STATUS_RUNNING]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'started_at' => now()->subMinute(),
        ]);

        return [$job, $run];
    }

    private function backupJob(array $overrides = [], bool $createDestination = true): BackupJob
    {
        $destination = $createDestination ? $this->destination() : null;

        return BackupJob::create(array_merge([
            'name' => 'Concurrency job',
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => sys_get_temp_dir(),
            'backup_destination_id' => $destination?->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => now()->subDay(),
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_MANUAL,
        ], $overrides));
    }

    private function destination(): BackupDestination
    {
        $archivePath = $this->databasePath.'-backups';

        return BackupDestination::create([
            'name' => 'Local concurrency destination',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => $archivePath,
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => [
                'archive_path' => $archivePath,
                'archive_mount_source' => $archivePath,
            ],
        ]);
    }

    private function backupGroup(): BackupJobGroup
    {
        return BackupJobGroup::create([
            'name' => 'Concurrency group',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => BackupJobGroup::FAILURE_POLICY_CONTINUE,
            'notifications_enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);
    }

    private function notificationChannelAttributes(string $name): array
    {
        return [
            'name' => $name,
            'service' => NotificationChannel::SERVICE_ADVANCED,
            'url' => 'ntfy://ntfy.sh/'.str($name)->slug()->toString(),
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_default' => true,
        ];
    }

    /**
     * @param  callable(callable(string): void): mixed  $callback
     * @return array{pid: int, stream: resource}
     */
    private function fork(callable $callback): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create the process barrier socket pair.');
        }

        DB::disconnect('sqlite');
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);

            throw new RuntimeException('Unable to fork the concurrency test process.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], self::IPC_TIMEOUT_SECONDS);
            DB::purge('sqlite');
            DB::reconnect('sqlite');

            try {
                $barrier = function (string $name) use ($sockets): void {
                    $this->writeMessage($sockets[1], 'B:'.$name);

                    if ($this->readMessage($sockets[1]) !== 'C') {
                        throw new RuntimeException('The parent did not release the process barrier.');
                    }
                };
                $result = $callback($barrier);
                $this->writeMessage($sockets[1], 'R:'.base64_encode(serialize($result)));
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $exception) {
                $failure = [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ];
                $this->writeMessage($sockets[1], 'E:'.base64_encode(serialize($failure)));
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], self::IPC_TIMEOUT_SECONDS);
        $this->children[$pid] = $sockets[0];
        DB::reconnect('sqlite');

        return ['pid' => $pid, 'stream' => $sockets[0]];
    }

    /** @param array{pid: int, stream: resource} $child */
    private function awaitBarrier(array $child, string $name): void
    {
        $message = $this->readMessage($child['stream']);

        if (str_starts_with($message, 'E:')) {
            $this->fail($this->childFailureMessage($message));
        }

        $this->assertSame('B:'.$name, $message);
    }

    /** @param array{pid: int, stream: resource} $child */
    private function releaseBarrier(array $child): void
    {
        $this->writeMessage($child['stream'], 'C');
    }

    /** @param array{pid: int, stream: resource} $child */
    private function finish(array $child): mixed
    {
        $message = $this->readMessage($child['stream']);
        fclose($child['stream']);
        pcntl_waitpid($child['pid'], $status);
        unset($this->children[$child['pid']]);

        if (str_starts_with($message, 'E:')) {
            $this->fail($this->childFailureMessage($message));
        }

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertStringStartsWith('R:', $message);

        return unserialize(base64_decode(substr($message, 2), true), ['allowed_classes' => false]);
    }

    /** @param resource $stream */
    private function writeMessage($stream, string $message): void
    {
        $payload = $message."\n";

        while ($payload !== '') {
            $written = fwrite($stream, $payload);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write to the process barrier.');
            }

            $payload = substr($payload, $written);
        }
    }

    /** @param resource $stream */
    private function readMessage($stream): string
    {
        $message = fgets($stream);

        if ($message === false) {
            $metadata = stream_get_meta_data($stream);
            $reason = ($metadata['timed_out'] ?? false) ? 'timed out' : 'closed';

            throw new RuntimeException("The process barrier {$reason} before sending a message.");
        }

        return rtrim($message, "\r\n");
    }

    private function childFailureMessage(string $message): string
    {
        $failure = unserialize(base64_decode(substr($message, 2), true), ['allowed_classes' => false]);

        return sprintf("Child process failed with %s: %s\n%s", $failure['class'], $failure['message'], $failure['trace']);
    }
}
