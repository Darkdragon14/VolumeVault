<?php

namespace Tests\Feature;

use App\Actions\Restore\RunRestore;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class RunRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/volumevault-run-restore-'.uniqid();
        File::ensureDirectoryExists($this->storagePath);
        $this->app->useStoragePath($this->storagePath);
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== '') {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_run_fails_when_the_target_volume_already_exists(): void
    {
        $storage = Mockery::mock(DestinationStorage::class);
        // The pre-flight guard must trip before we ever try to download anything.
        $storage->shouldNotReceive('download');
        $this->app->instance(DestinationStorage::class, $storage);
        $this->app->instance(DockerProcess::class, $this->docker(volumeExists: true));

        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Target Docker volume already exists', $run->error_message);
    }

    public function test_in_place_restore_clears_then_extracts_into_the_source_volume(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($docker->ranClear, 'In-place restore must wipe the source volume before extracting.');
        $this->assertTrue($docker->ranRestore, 'In-place restore must extract the archive.');
        // The source volume is reused, never created or removed.
        $this->assertNotContains('create', $docker->volumeSubcommands);
        $this->assertNotContains('rm', $docker->volumeSubcommands);
    }

    public function test_in_place_restore_fails_when_the_source_volume_is_missing(): void
    {
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldNotReceive('download');
        $this->app->instance(DestinationStorage::class, $storage);
        $this->app->instance(DockerProcess::class, $this->docker(volumeExists: false));

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('does not exist', $run->error_message);
    }

    public function test_safe_in_place_restore_stops_and_restarts_affected_containers(): void
    {
        $docker = $this->docker(volumeExists: true, containers: ['c1', 'c2']);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_SAFE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(['c1', 'c2'], $docker->stopped);
        $this->assertSame(['c1', 'c2'], $docker->started);
        $this->assertTrue($docker->ranClear);
        // Recorded for the UI audit trail, then cleared once everything restarts.
        $this->assertCount(2, $run->affected_containers);
        $this->assertNull($run->stopped_container_ids);
    }

    public function test_in_place_restore_does_not_wipe_when_the_download_yields_an_empty_archive(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);

        // The destination "succeeds" but produces a zero-byte file (vanished
        // object / truncated transfer). The verify step must catch it before the
        // in-place wipe runs, so the live volume is never cleared.
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldReceive('download')
            ->andReturnUsing(function (BackupDestination $destination, string $key, string $targetPath): void {
                File::ensureDirectoryExists(dirname($targetPath));
                File::put($targetPath, '');
            });
        $this->app->instance(DestinationStorage::class, $storage);

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('missing or empty', $run->error_message);
        $this->assertFalse($docker->ranClear, 'The source volume must not be wiped when the archive is unusable.');
        $this->assertFalse($docker->ranRestore);
    }

    public function test_in_place_restore_does_not_wipe_when_the_archive_is_corrupt(): void
    {
        // The downloaded file is non-empty but not a readable .tar.gz (truncated /
        // corrupt). The readability check must catch it before the in-place wipe,
        // so the live volume is never cleared and extraction never runs.
        $docker = $this->docker(volumeExists: true, archiveReadable: false);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('not a readable .tar.gz', $run->error_message);
        $this->assertTrue($docker->ranVerify, 'The archive must be verified before any wipe.');
        $this->assertFalse($docker->ranClear, 'A corrupt archive must not trigger the in-place wipe.');
        $this->assertFalse($docker->ranRestore);
    }

    public function test_safe_in_place_restore_only_stops_running_containers(): void
    {
        // c2 was already stopped before the restore; it must not be recorded as a
        // container we stopped, so the restart step leaves it as the user left it.
        $docker = $this->docker(volumeExists: true, containers: ['c1', 'c2'], containerStates: ['c2' => 'exited']);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_SAFE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(['c1'], $docker->stopped, 'Only the running container should be stopped.');
        $this->assertSame(['c1'], $docker->started, 'The already-stopped container must not be started.');
        // Both still appear in the audit trail of containers using the volume.
        $this->assertCount(2, $run->affected_containers);
    }

    public function test_safety_backup_runs_after_the_archive_is_downloaded(): void
    {
        // If the download fails, the safety backup must never have run — otherwise
        // it could overwrite/prune the very archive being restored before we hold
        // it. A throwing download proves the ordering: no safety backup, no wipe.
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);

        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldReceive('download')->andThrow(new \RuntimeException('network down'));
        $this->app->instance(DestinationStorage::class, $storage);

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
            'backup_before_overwrite' => true,
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->pre_restore_backup_run_id, 'The safety backup must not run before the download succeeds.');
        $this->assertFalse($docker->ranClear);
    }

    public function test_terminal_restore_is_not_rerun(): void
    {
        // A queued lock-loser delivered after reconciliation already failed it must
        // not be moved back to running and re-executed (it could be destructive).
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldNotReceive('download');
        $this->app->instance(DestinationStorage::class, $storage);

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
            'status' => RestoreRun::STATUS_FAILED,
            'finished_at' => now()->subHour(),
        ]);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertFalse($docker->ranClear, 'A terminal restore must not be re-executed.');
        $this->assertFalse($docker->ranRestore);
    }

    public function test_in_place_restore_aborts_before_wiping_if_finalized_out_of_band(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        // Simulate stale-run reconciliation failing the run mid-download (a raw
        // update, mirroring markFailed touching only the DB row). The worker must
        // notice before prepareTarget and never wipe the volume.
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldReceive('download')
            ->andReturnUsing(function (BackupDestination $destination, string $key, string $targetPath) use ($run): void {
                File::ensureDirectoryExists(dirname($targetPath));
                File::put($targetPath, 'archive');
                RestoreRun::whereKey($run->id)->update(['status' => RestoreRun::STATUS_FAILED]);
            });
        $this->app->instance(DestinationStorage::class, $storage);

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertFalse($docker->ranClear, 'A run finalized out of band must not wipe the volume.');
        $this->assertFalse($docker->ranRestore);
    }

    public function test_in_place_clear_runs_in_a_named_container_for_liveness(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun([
            'mode' => RestoreRun::MODE_INPLACE,
            'target_volume_name' => 'app_data',
        ]);

        app(RunRestore::class)->handle($run);

        // The clear runs as a named container so reconciliation can confirm it is
        // alive on a large volume instead of failing the restore mid-delete.
        $this->assertContains('--name', $docker->clearCommand);
        $this->assertNotEmpty(array_filter($docker->clearCommand, fn ($arg) => str_starts_with((string) $arg, 'volumevault-clear-')));
    }

    public function test_archive_verification_discards_the_listing_output(): void
    {
        $docker = $this->docker(volumeExists: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun(['mode' => RestoreRun::MODE_INPLACE, 'target_volume_name' => 'app_data']);

        app(RunRestore::class)->handle($run);

        // The listing is sent to /dev/null so a many-file archive cannot exhaust
        // memory through DockerProcess's in-memory stdout capture.
        $this->assertTrue($docker->ranVerify);
        $this->assertNotEmpty(array_filter($docker->verifyCommand, fn ($arg) => str_contains((string) $arg, '/dev/null')));
    }

    public function test_mark_failed_is_idempotent_for_terminal_runs(): void
    {
        $run = $this->restoreRun(['status' => RestoreRun::STATUS_SUCCESS, 'finished_at' => now()->subHour()]);

        app(RunRestore::class)->markFailed($run, new \RuntimeException('late failure'));
        $run->refresh();

        // A run that already succeeded must not be flipped to FAILED by a late failed() hook.
        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertNull($run->error_message);
    }

    public function test_mark_failed_does_not_overwrite_a_run_that_already_succeeded(): void
    {
        $run = $this->restoreRun(['status' => RestoreRun::STATUS_SUCCESS, 'finished_at' => now()]);

        // Reconciliation holds a snapshot that still says running, but the worker
        // already finished. The conditional transition must lose this race.
        $run->forceFill(['status' => RestoreRun::STATUS_RUNNING]); // in-memory only, not saved

        $failed = app(RunRestore::class)->markFailed($run, new \RuntimeException('stale sweep'));

        $this->assertFalse($failed, 'markFailed must report no transition for an already-terminal run.');
        $this->assertSame(RestoreRun::STATUS_SUCCESS, RestoreRun::findOrFail($run->id)->status);
    }

    private function restoreRun(array $overrides = []): RestoreRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);

        $job = BackupJob::create([
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return RestoreRun::create(array_merge([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ], $overrides));
    }

    private function storageThatDownloads(): DestinationStorage
    {
        $storage = Mockery::mock(DestinationStorage::class);
        $storage->shouldReceive('download')
            ->andReturnUsing(function (BackupDestination $destination, string $key, string $targetPath): void {
                File::ensureDirectoryExists(dirname($targetPath));
                File::put($targetPath, 'archive');
            });

        return $storage;
    }

    private function docker(bool $volumeExists, array $containers = [], array $containerStates = [], bool $archiveReadable = true): DockerProcess
    {
        return new class($volumeExists, $containers, $containerStates, $archiveReadable) extends DockerProcess
        {
            /** @var array<int, string> */
            public array $volumeSubcommands = [];

            /** @var array<int, string> */
            public array $stopped = [];

            /** @var array<int, string> */
            public array $started = [];

            public bool $ranClear = false;

            public bool $ranRestore = false;

            public bool $ranVerify = false;

            /** @var array<int, string> */
            public array $clearCommand = [];

            /** @var array<int, string> */
            public array $verifyCommand = [];

            public function __construct(private readonly bool $volumeExists, private readonly array $containers, private readonly array $containerStates, private readonly bool $archiveReadable) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $verb = $command[1] ?? null;

                if ($verb === 'volume') {
                    $this->volumeSubcommands[] = (string) ($command[2] ?? '');

                    if (($command[2] ?? null) === 'inspect') {
                        return $this->volumeExists
                            ? new DockerProcessResult($command, 0, '[{"Name":"app_data"}]', '')
                            : new DockerProcessResult($command, 1, '', 'no such volume');
                    }

                    return new DockerProcessResult($command, 0, '', '');
                }

                if ($verb === 'ps') {
                    $lines = array_map(
                        fn (string $id) => json_encode([
                            'ID' => $id,
                            'Names' => $id.'-name',
                            'State' => $this->containerStates[$id] ?? 'running',
                        ]),
                        $this->containers,
                    );

                    return new DockerProcessResult($command, 0, implode("\n", $lines), '');
                }

                if ($verb === 'stop') {
                    $this->stopped[] = (string) ($command[2] ?? '');

                    return new DockerProcessResult($command, 0, '', '');
                }

                if ($verb === 'start') {
                    $this->started[] = (string) ($command[2] ?? '');

                    return new DockerProcessResult($command, 0, '', '');
                }

                if ($verb === 'run' && in_array('find', $command, true)) {
                    // The only `docker run` going through run() is ClearDockerVolume;
                    // the restore extraction streams via runWithInputFile() below.
                    $this->ranClear = true;
                    $this->clearCommand = $command;
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                // tar -tzf is the readability check; tar -xzf is the extraction.
                if (collect($command)->contains(fn (string $arg): bool => str_contains($arg, 'tzf'))) {
                    $this->ranVerify = true;
                    $this->verifyCommand = $command;

                    return $this->archiveReadable
                        ? new DockerProcessResult($command, 0, "data/\n", '')
                        : new DockerProcessResult($command, 1, '', 'gzip: stdin: unexpected end of file');
                }

                $this->ranRestore = true;

                return new DockerProcessResult($command, 0, 'restore complete', '');
            }
        };
    }
}
