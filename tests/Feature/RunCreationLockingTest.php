<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\GenerateRestoreVolumeName;
use App\Actions\Restore\ResolveRestoreDestination;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RunCreationLockingTest extends TestCase
{
    use RefreshDatabase;

    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupPath = sys_get_temp_dir().'/volumevault-run-creation-locking-'.uniqid();
        config(['volumevault.host_path_allowlist' => [sys_get_temp_dir()]]);
        File::ensureDirectoryExists($this->backupPath);
        File::put($this->backupPath.'/backup.tar.gz', 'fake-archive');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);

        parent::tearDown();
    }

    public function test_targeted_lock_only_selects_requested_label_managed_jobs(): void
    {
        $destination = $this->destination('Destination');
        $requested = $this->job($destination, [
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'requested'),
        ]);
        $other = $this->job($destination, [
            'name' => 'Other label job',
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'other'),
        ]);

        $selectedIds = app(WithDockerLabelMutationLocks::class)->handleForJobs(
            [$requested->id],
            [$destination->id],
            fn ($destinations, $settings, $jobs): array => $jobs->keys()->all(),
        );

        $this->assertSame([$requested->id], $selectedIds);
        $this->assertNotContains($other->id, $selectedIds);
    }

    public function test_manual_backup_run_reloads_and_retries_when_destination_changes_before_locking(): void
    {
        $originalDestination = $this->destination('Original');
        $replacementDestination = $this->destination('Replacement');
        $job = $this->job($originalDestination, [
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'volume_name' => null,
            'host_path' => '/srv/data',
        ]);
        $locks = new class($job, $replacementDestination) extends WithDockerLabelMutationLocks
        {
            public array $destinationRequests = [];

            private bool $changed = false;

            public function __construct(
                private readonly BackupJob $job,
                private readonly BackupDestination $replacementDestination,
            ) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                $this->destinationRequests[] = $destinationIds;

                if (! $this->changed) {
                    $this->changed = true;
                    $this->job->update(['backup_destination_id' => $this->replacementDestination->id]);
                }

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateBackupRun(app(BackupScheduleCalculator::class), $locks);

        $run = $action->handle($job, BackupRun::TRIGGER_MANUAL);

        $this->assertSame($job->id, $run->backup_job_id);
        $this->assertSame([[$originalDestination->id], [$replacementDestination->id]], $locks->destinationRequests);
        $this->assertTrue(ActivityLog::query()->where('event_type', 'backup_run_queued')->where('subject_id', $run->id)->exists());
    }

    public function test_backup_run_uses_the_locked_destination_state(): void
    {
        $destination = $this->destination('Destination');
        $job = $this->job($destination, [
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'volume_name' => null,
            'host_path' => '/srv/data',
        ]);
        $locks = new class($destination) extends WithDockerLabelMutationLocks
        {
            private bool $deactivated = false;

            public function __construct(private readonly BackupDestination $destination) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->deactivated) {
                    $this->deactivated = true;
                    $this->destination->update(['is_active' => false]);
                }

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateBackupRun(app(BackupScheduleCalculator::class), $locks);

        try {
            $action->handle($job, BackupRun::TRIGGER_MANUAL);
            $this->fail('Expected the inactive locked destination to reject the run.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('destination', $exception->errors());
        }

        $this->assertSame(0, BackupRun::count());
    }

    public function test_restore_run_reloads_and_retries_with_frozen_references(): void
    {
        $originalDestination = $this->destination('Original');
        $replacementDestination = $this->destination('Replacement');
        $job = $this->job($originalDestination);
        $locks = new class($job, $replacementDestination) extends WithDockerLabelMutationLocks
        {
            public array $destinationRequests = [];

            private bool $changed = false;

            public function __construct(
                private readonly BackupJob $job,
                private readonly BackupDestination $replacementDestination,
            ) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                $this->destinationRequests[] = $destinationIds;

                if (! $this->changed) {
                    $this->changed = true;
                    $this->job->update([
                        'backup_destination_id' => $this->replacementDestination->id,
                        'volume_name' => 'replacement_data',
                    ]);
                }

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateRestoreRun(app(GenerateRestoreVolumeName::class), $locks, app(ResolveRestoreDestination::class), app(ListBackupObjects::class));

        $run = $action->handle($job, [
            'selected_backup_key' => 'backup.tar.gz',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'target_volume_name' => 'restored_data',
        ]);

        $this->assertSame($replacementDestination->id, $run->backup_destination_id);
        $this->assertSame('replacement_data', $run->source_volume_name);
        $this->assertSame([[$originalDestination->id], [$replacementDestination->id]], $locks->destinationRequests);
        $this->assertTrue(ActivityLog::query()->where('event_type', 'restore_run_started')->where('subject_id', $run->id)->exists());
    }

    public function test_restore_run_revalidates_in_place_confirmation_after_retry(): void
    {
        $destination = $this->destination('Destination');
        $job = $this->job($destination);
        $locks = new class($job) extends WithDockerLabelMutationLocks
        {
            private bool $changed = false;

            public function __construct(private readonly BackupJob $job) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->changed) {
                    $this->changed = true;
                    $this->job->update(['volume_name' => 'replacement_data']);
                }

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateRestoreRun(app(GenerateRestoreVolumeName::class), $locks, app(ResolveRestoreDestination::class), app(ListBackupObjects::class));

        try {
            $action->handle($job, [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => 'app_data',
            ]);
            $this->fail('Expected the stale in-place confirmation to reject the run.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation_text', $exception->errors());
        }

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_restore_run_relists_when_changed_destination_settings_remove_the_selected_key(): void
    {
        $originalPath = $this->backupPath.'/original';
        $changedPath = $this->backupPath.'/changed';
        File::ensureDirectoryExists($originalPath);
        File::ensureDirectoryExists($changedPath);
        File::put($originalPath.'/selected.tar.gz', 'original-archive');
        File::put($changedPath.'/other.tar.gz', 'changed-archive');

        $destination = $this->destination('Destination');
        $destination->update(['settings' => ['archive_path' => $originalPath, 'archive_mount_source' => $originalPath]]);
        $job = $this->job($destination);
        $listBackupObjects = new class(app(DestinationStorage::class)) extends ListBackupObjects
        {
            public array $transactionLevels = [];

            public function handle(BackupDestination $destination): array
            {
                $this->transactionLevels[] = DB::transactionLevel();

                return parent::handle($destination);
            }
        };
        $baselineTransactionLevel = DB::transactionLevel();

        $locks = new class($destination, $changedPath) extends WithDockerLabelMutationLocks
        {
            public function __construct(
                private readonly BackupDestination $destination,
                private readonly string $changedPath,
            ) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                $this->destination->update([
                    'settings' => ['archive_path' => $this->changedPath, 'archive_mount_source' => $this->changedPath],
                ]);

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateRestoreRun(app(GenerateRestoreVolumeName::class), $locks, app(ResolveRestoreDestination::class), $listBackupObjects);

        try {
            $action->handle($job, [
                'selected_backup_key' => 'selected.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
                'target_volume_name' => 'restored_data',
            ]);
            $this->fail('Expected the locked destination listing to reject the stale selected key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected_backup_key', $exception->errors());
        }

        $this->assertSame([$baselineTransactionLevel, $baselineTransactionLevel], $listBackupObjects->transactionLevels);
        $this->assertSame(['other.tar.gz'], collect($listBackupObjects->handle($destination->fresh()))->pluck('key')->all());
        $this->assertSame(0, RestoreRun::count());
    }

    public function test_restore_run_relists_before_rejecting_a_key_added_by_changed_destination_settings(): void
    {
        $originalPath = $this->backupPath.'/original-missing';
        $changedPath = $this->backupPath.'/changed-present';
        File::ensureDirectoryExists($originalPath);
        File::ensureDirectoryExists($changedPath);
        File::put($originalPath.'/other.tar.gz', 'original-archive');
        File::put($changedPath.'/selected.tar.gz', 'changed-archive');

        $destination = $this->destination('Destination');
        $destination->update(['settings' => ['archive_path' => $originalPath, 'archive_mount_source' => $originalPath]]);
        $job = $this->job($destination);
        $locks = new class($destination, $changedPath) extends WithDockerLabelMutationLocks
        {
            public bool $active = false;

            private bool $changed = false;

            public function __construct(
                private readonly BackupDestination $destination,
                private readonly string $changedPath,
            ) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->changed) {
                    $this->changed = true;
                    $this->destination->update([
                        'settings' => ['archive_path' => $this->changedPath, 'archive_mount_source' => $this->changedPath],
                    ]);
                }

                $this->active = true;

                try {
                    return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
                } finally {
                    $this->active = false;
                }
            }
        };
        $listBackupObjects = new class(app(DestinationStorage::class), $locks) extends ListBackupObjects
        {
            public array $transactionLevels = [];

            public array $lockScopes = [];

            public function __construct(DestinationStorage $storage, private readonly WithDockerLabelMutationLocks $locks)
            {
                parent::__construct($storage);
            }

            public function handle(BackupDestination $destination): array
            {
                $this->transactionLevels[] = DB::transactionLevel();
                $this->lockScopes[] = $this->locks->active;

                return parent::handle($destination);
            }
        };
        $baselineTransactionLevel = DB::transactionLevel();
        $action = new CreateRestoreRun(app(GenerateRestoreVolumeName::class), $locks, app(ResolveRestoreDestination::class), $listBackupObjects);

        $run = $action->handle($job, [
            'selected_backup_key' => 'selected.tar.gz',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'target_volume_name' => 'restored_data',
        ]);

        $this->assertSame([$baselineTransactionLevel, $baselineTransactionLevel], $listBackupObjects->transactionLevels);
        $this->assertSame([false, false], $listBackupObjects->lockScopes);
        $this->assertSame($destination->id, $run->backup_destination_id);
        $this->assertSame('selected.tar.gz', $run->selected_backup_key);
    }

    public function test_restore_run_retries_a_failed_listing_when_the_destination_fingerprint_changed(): void
    {
        $originalPath = $this->backupPath.'/listing-failure-original';
        $changedPath = $this->backupPath.'/listing-failure-changed';
        File::ensureDirectoryExists($originalPath);
        File::ensureDirectoryExists($changedPath);
        File::put($changedPath.'/selected.tar.gz', 'archive');
        $destination = $this->destination('Destination');
        $destination->update(['settings' => ['archive_path' => $originalPath, 'archive_mount_source' => $originalPath]]);
        $job = $this->job($destination);
        $listBackupObjects = new class(app(DestinationStorage::class)) extends ListBackupObjects
        {
            public int $calls = 0;

            public function handle(BackupDestination $destination): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new RuntimeException('Transient listing failure.');
                }

                return parent::handle($destination);
            }
        };
        $locks = new class($destination, $changedPath) extends WithDockerLabelMutationLocks
        {
            private bool $changed = false;

            public function __construct(private readonly BackupDestination $destination, private readonly string $changedPath) {}

            public function handleForJobs(array $managedJobIds, array $destinationIds, callable $callback, array $volumeNames = [], array $notificationChannelIds = [], array $explicitJobIds = []): mixed
            {
                if (! $this->changed) {
                    $this->changed = true;
                    $this->destination->update([
                        'settings' => ['archive_path' => $this->changedPath, 'archive_mount_source' => $this->changedPath],
                    ]);
                }

                return parent::handleForJobs($managedJobIds, $destinationIds, $callback, $volumeNames, $notificationChannelIds, $explicitJobIds);
            }
        };
        $action = new CreateRestoreRun(app(GenerateRestoreVolumeName::class), $locks, app(ResolveRestoreDestination::class), $listBackupObjects);

        $run = $action->handle($job, [
            'selected_backup_key' => 'selected.tar.gz',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'target_volume_name' => 'restored_data',
        ]);

        $this->assertSame(2, $listBackupObjects->calls);
        $this->assertSame('selected.tar.gz', $run->selected_backup_key);
    }

    public function test_restore_run_surfaces_a_generic_listing_error_when_the_fingerprint_is_unchanged(): void
    {
        $destination = $this->destination('Destination');
        $job = $this->job($destination);
        $listBackupObjects = new class(app(DestinationStorage::class)) extends ListBackupObjects
        {
            public function handle(BackupDestination $destination): array
            {
                throw new RuntimeException('Sensitive provider failure.');
            }
        };
        $action = new CreateRestoreRun(app(GenerateRestoreVolumeName::class), app(WithDockerLabelMutationLocks::class), app(ResolveRestoreDestination::class), $listBackupObjects);

        try {
            $action->handle($job, [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
                'target_volume_name' => 'restored_data',
            ]);
            $this->fail('Expected the unchanged failed listing to reject the restore.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Unable to verify the selected backup against the destination listing.'],
                $exception->errors()['selected_backup_key'],
            );
        }

        $this->assertSame(0, RestoreRun::count());
    }

    private function destination(string $name): BackupDestination
    {
        return BackupDestination::create([
            'name' => $name,
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => $this->backupPath,
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $this->backupPath, 'archive_mount_source' => $this->backupPath],
        ]);
    }

    private function job(BackupDestination $destination, array $overrides = []): BackupJob
    {
        return BackupJob::create(array_merge([
            'name' => 'Application data',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'status' => BackupJob::STATUS_ACTIVE,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_MANUAL,
        ], $overrides));
    }
}
