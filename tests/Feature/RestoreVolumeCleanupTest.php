<?php

namespace Tests\Feature;

use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Restore\Modes\NewVolumeRestore;
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
use RuntimeException;
use Tests\TestCase;

class RestoreVolumeCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/volumevault-restore-cleanup-'.uniqid();
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

    public function test_failed_restore_retains_target_volume_for_manual_inspection(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: false);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->status);
        $this->assertNotContains(
            ['volume', 'rm', $run->target_volume_name],
            $docker->volumeCommands,
            'Automatic name-based removal cannot safely exclude a foreign replacement.'
        );
        $this->assertStringContainsString('remove it manually if appropriate', $run->logs);
        $this->assertNull($run->docker_container_id);
    }

    public function test_successful_restore_keeps_the_target_volume(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());

        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);
        $run->refresh();

        $this->assertSame(RestoreRun::STATUS_SUCCESS, $run->status);
        $this->assertNotContains(
            ['volume', 'rm', $run->target_volume_name],
            $docker->volumeCommands,
            'A successful restore must not delete the volume it just populated.'
        );
    }

    public function test_external_creation_during_archive_verification_never_extracts_or_deletes_foreign_volume(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $docker->externalCreationDuringVerification = true;
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());
        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertStringContainsString('ownership could not be verified', $run->error_message);
        $this->assertFalse($docker->extracted);
        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
        $this->assertSame(['external' => 'owner'], $docker->labels);
    }

    public function test_cleanup_preserves_a_same_name_replacement(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: false);
        $docker->replaceAfterHelperRemoval = true;
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());
        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertTrue($docker->extracted);
        $this->assertStringContainsString('tar: extraction failed', $run->error_message);
        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
        $this->assertSame('another-run', $docker->labels[CreateDockerVolume::RESTORE_OWNERSHIP_LABEL]);
    }

    public function test_cleanup_retains_even_a_volume_with_persisted_matching_ownership(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $this->app->instance(DockerProcess::class, $docker);
        $run = $this->restoreRun();
        app(NewVolumeRestore::class)->prepareTarget($run);

        $token = $run->fresh()->target_volume_ownership_token;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $docker->labels[CreateDockerVolume::RESTORE_OWNERSHIP_LABEL]);
        app(NewVolumeRestore::class)->cleanupAfterFailure($run->fresh());

        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
    }

    public function test_replacement_after_preparation_before_helper_creation_never_extracts(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $docker->replaceBeforeHelperCreation = true;
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());
        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertStringContainsString('before extraction', $run->error_message);
        $this->assertFalse($docker->extracted);
        $this->assertNull($run->docker_container_id);
        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
        $this->assertSame('another-run', $docker->labels[CreateDockerVolume::RESTORE_OWNERSHIP_LABEL]);
    }

    public function test_cleanup_without_persisted_ownership_never_removes_a_volume(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $this->app->instance(DockerProcess::class, $docker);
        app(NewVolumeRestore::class)->cleanupAfterFailure($this->restoreRun());

        $this->assertSame([], $docker->volumeCommands);
    }

    public function test_cleanup_inspection_failure_preserves_volume_and_original_failure(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: false);
        $docker->failCleanupInspection = true;
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());
        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertStringContainsString('tar: extraction failed', $run->error_message);
        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
    }

    public function test_create_failure_after_creation_retains_the_volume(): void
    {
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $docker->failAfterCreation = true;
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(DestinationStorage::class, $this->storageThatDownloads());
        $run = $this->restoreRun();

        app(RunRestore::class)->handle($run);

        $this->assertSame(RestoreRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertStringContainsString('create response lost', $run->error_message);
        $this->assertFalse($docker->extracted);
        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
    }

    public function test_each_preparation_persists_a_new_nonce_before_docker_creation(): void
    {
        $run = $this->restoreRun();
        $tokens = [];
        $this->mock(CreateDockerVolume::class)->shouldReceive('handle')->twice()
            ->andReturnUsing(function (string $name, string $token) use ($run, &$tokens): never {
                $this->assertSame($token, $run->fresh()->target_volume_ownership_token);
                $tokens[] = $token;

                throw new RuntimeException('create failed');
            });
        $docker = $this->dockerProcess(restoreSucceeds: true);
        $this->app->instance(DockerProcess::class, $docker);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(NewVolumeRestore::class)->prepareTarget($run->fresh());
                $this->fail('Creation must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('create failed', $exception->getMessage());
            }
        }

        $this->assertNotSame($tokens[0], $tokens[1]);
        $this->assertNotContains(['volume', 'rm', $run->target_volume_name], $docker->volumeCommands);
    }

    public function test_create_docker_volume_without_ownership_retains_existing_command(): void
    {
        $command = ['docker', 'volume', 'create', '--', 'ordinary-volume'];
        $this->mock(DockerProcess::class)->shouldReceive('run')->once()->with($command, 60)
            ->andReturn(new DockerProcessResult($command, 0, 'ordinary-volume', ''));

        app(CreateDockerVolume::class)->handle('ordinary-volume');
    }

    private function restoreRun(): RestoreRun
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
            'name' => 'Local app backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        return RestoreRun::create([
            'backup_job_id' => $job->id,
            'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'app_data',
            'target_volume_name' => 'app_data_restored_20260608_120000',
            'mode' => RestoreRun::MODE_NEW_VOLUME,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
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

    private function dockerProcess(bool $restoreSucceeds): DockerProcess
    {
        return new class($restoreSucceeds) extends DockerProcess
        {
            /** @var array<int, array<int, string>> */
            public array $volumeCommands = [];

            public ?array $labels = null;

            public bool $externalCreationDuringVerification = false;

            public bool $replaceAfterHelperRemoval = false;

            public bool $replaceBeforeHelperCreation = false;

            public bool $failCleanupInspection = false;

            public bool $failAfterCreation = false;

            public bool $extracted = false;

            public function __construct(private readonly bool $restoreSucceeds) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'volume') {
                    $this->volumeCommands[] = array_slice($command, 1);

                    if (($command[2] ?? null) === 'inspect') {
                        return $this->labels === null || ($this->extracted && $this->failCleanupInspection)
                            ? new DockerProcessResult($command, 1, '', 'no such volume')
                            : new DockerProcessResult($command, 0, json_encode([['Name' => $command[3], 'Labels' => $this->labels]]), '');
                    }

                    if (($command[2] ?? null) === 'create') {
                        [$key, $value] = explode('=', $command[4], 2);
                        $this->labels ??= [$key => $value];

                        if ($this->failAfterCreation) {
                            return new DockerProcessResult($command, 1, '', 'create response lost');
                        }
                    }

                    if (($command[2] ?? null) === 'rm') {
                        $this->labels = null;
                    }

                    return new DockerProcessResult($command, 0, '', '');
                }

                if (($command[1] ?? null) === 'create') {
                    if ($this->replaceBeforeHelperCreation) {
                        $this->labels = [CreateDockerVolume::RESTORE_OWNERSHIP_LABEL => 'another-run'];
                    }

                    return new DockerProcessResult($command, 0, str_repeat('a', 64), '');
                }

                if (($command[1] ?? null) === 'rm' && $this->replaceAfterHelperRemoval) {
                    $this->labels = [CreateDockerVolume::RESTORE_OWNERSHIP_LABEL => 'another-run'];
                }

                if (($command[1] ?? null) === 'start') {
                    $this->extracted = true;

                    return $this->restoreSucceeds
                        ? new DockerProcessResult($command, 0, 'restore complete', '')
                        : new DockerProcessResult($command, 1, '', 'tar: extraction failed');
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                // The archive readability check (tar -tzf) always passes; only the
                // extraction (tar -xzf) reflects the simulated restore outcome.
                if (collect($command)->contains(fn (string $arg): bool => str_contains($arg, 'tzf'))) {
                    if ($this->externalCreationDuringVerification) {
                        $this->labels = ['external' => 'owner'];
                    }

                    return new DockerProcessResult($command, 0, "data/\n", '');
                }

                return $this->run($command, $timeout, $environment);
            }
        };
    }
}
