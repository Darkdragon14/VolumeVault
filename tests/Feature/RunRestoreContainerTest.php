<?php

namespace Tests\Feature;

use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\RunRestoreContainer;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\TestCase;

class RunRestoreContainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_command_mounts_volume_streams_archive_and_strips_components(): void
    {
        config(['volumevault.docker_network' => 'volumevault_proxy-net']);
        $docker = $this->recordingDocker();
        $run = $this->restoreRun();
        $archivePath = '/var/lib/restore/backup.tar.gz';

        (new RunRestoreContainer($docker))->handle($run, $archivePath);

        $command = $docker->command;
        $this->assertSame(['docker', 'run', '--rm', '--name'], array_slice($command, 0, 4));

        // The target volume is writable; the archive is streamed over stdin so
        // containerized deployments do not depend on host-visible app paths.
        $this->assertContains('-i', $command);
        $this->assertNotContains('--network', $command);
        $this->assertContains('app_data_restored:/restore', $command);
        $this->assertSame($archivePath, $docker->inputPath);
        $this->assertNotContains($archivePath.':/archive/backup.tar.gz:ro', $command);

        // tar extracts the archive into the volume, stripping the wrapping path segments.
        $this->assertSame(RunBackupContainer::IMAGE, $command[array_search('--entrypoint', $command, true) + 2]);
        $this->assertContains('tar', $command);
        $this->assertContains('-xzf', $command);
        $this->assertSame('-', $command[array_search('-xzf', $command, true) + 1]);
        $this->assertContains('--strip-components', $command);
        $this->assertSame('2', $command[array_search('--strip-components', $command, true) + 1]);

        // Keep the restore compatible with BusyBox tar in the Alpine-based backup image.
        $this->assertNotContains('--no-absolute-names', $command);
        $this->assertNotContains('--no-overwrite-dir', $command);
    }

    public function test_restore_container_id_is_persisted(): void
    {
        $docker = $this->recordingDocker();
        $run = $this->restoreRun();

        (new RunRestoreContainer($docker))->handle($run, '/tmp/backup.tar.gz');

        $containerName = $run->fresh()->docker_container_id;
        $this->assertNotNull($containerName);
        $this->assertStringStartsWith('volumevault-restore-'.$run->id.'-', $containerName);
        $this->assertContains($containerName, $docker->command);
    }

    public function test_restore_extraction_is_monitored_with_a_heartbeat(): void
    {
        $docker = $this->recordingDocker();
        $heartbeats = 0;

        (new RunRestoreContainer($docker))->handle(
            $this->restoreRun(),
            '/tmp/backup.tar.gz',
            function () use (&$heartbeats): void {
                $heartbeats++;
            },
        );

        $this->assertSame(1, $heartbeats);
    }

    public function test_new_volume_is_pinned_then_checked_before_start_and_helper_is_removed(): void
    {
        $run = $this->ownedRun();
        $docker = $this->pinnedDocker($run);

        $result = (new RunRestoreContainer($docker))->handle($run, '/tmp/archive');

        $this->assertTrue($result->successful());
        $this->assertSame(['create', 'volume', 'start', 'rm'], array_column($docker->commands, 1));
        $this->assertContains('type=volume,source=app_data_restored,target=/restore,volume-nocopy', $docker->commands[0]);
        $this->assertContains('-xzf', $docker->commands[0]);
        $this->assertSame(['docker', 'start', '--attach', '--interactive', str_repeat('a', 64)], $docker->commands[2]);
        $this->assertSame('/tmp/archive', $docker->inputPath);
        $this->assertTrue($docker->removalDeniedWhilePinned);
        $this->assertFalse($docker->pinned);
        $this->assertNull($run->fresh()->docker_container_id);
        $this->assertFalse($run->fresh()->docker_container_cleanup_pending);
    }

    public function test_replacement_before_helper_creation_never_starts_extraction(): void
    {
        $run = $this->ownedRun();
        $docker = $this->pinnedDocker($run);
        $docker->foreignReplacement = true;

        try {
            (new RunRestoreContainer($docker))->handle($run, '/tmp/archive');
            $this->fail('Foreign replacement must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership could not be verified', $exception->getMessage());
        }

        $this->assertSame(['create', 'volume', 'rm'], array_column($docker->commands, 1));
        $this->assertFalse($docker->pinned);
        $this->assertNull($run->fresh()->docker_container_id);
    }

    public function test_partial_helper_creation_failure_is_cleaned_using_persisted_name(): void
    {
        $run = $this->ownedRun();
        $docker = $this->pinnedDocker($run);
        $docker->failCreate = true;

        try {
            (new RunRestoreContainer($docker))->handle($run, '/tmp/archive');
            $this->fail('Create must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('create response lost', $exception->getMessage());
        }

        $this->assertSame(['create', 'rm'], array_column($docker->commands, 1));
        $this->assertSame($docker->commands[0][3], $docker->commands[1][3]);
        $this->assertFalse($docker->pinned);
        $this->assertNull($run->fresh()->docker_container_id);
    }

    public function test_stream_exception_still_forcibly_removes_helper(): void
    {
        $run = $this->ownedRun();
        $docker = $this->pinnedDocker($run);
        $docker->failStream = true;

        try {
            (new RunRestoreContainer($docker))->handle($run, '/tmp/archive');
            $this->fail('Stream must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('stream interrupted', $exception->getMessage());
        }

        $this->assertSame(['docker', 'rm', '--force', str_repeat('a', 64)], $docker->commands[3]);
        $this->assertFalse($docker->pinned);
        $this->assertNull($run->fresh()->docker_container_id);
    }

    public function test_missing_nonce_fails_closed_without_creating_or_starting_helper(): void
    {
        $run = $this->ownedRun();
        $run->forceFill(['target_volume_ownership_token' => null])->save();
        $docker = $this->pinnedDocker($run);

        try {
            (new RunRestoreContainer($docker))->handle($run, '/tmp/archive');
            $this->fail('Missing ownership must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership token is missing', $exception->getMessage());
        }

        $this->assertSame(['rm'], array_column($docker->commands, 1));
    }

    public function test_failed_helper_removal_retains_identity_for_recovery(): void
    {
        $run = $this->ownedRun();
        $docker = $this->pinnedDocker($run);
        $docker->failRemove = true;

        try {
            (new RunRestoreContainer($docker))->handle($run, '/tmp/archive');
            $this->fail('Cleanup failure must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('daemon unavailable', $exception->getMessage());
        }

        $this->assertSame(str_repeat('a', 64), $run->fresh()->docker_container_id);
        $this->assertTrue($run->fresh()->docker_container_cleanup_pending);
    }

    private function ownedRun(): RestoreRun
    {
        $run = $this->restoreRun();
        $run->forceFill(['mode' => RestoreRun::MODE_NEW_VOLUME, 'target_volume_ownership_token' => bin2hex(random_bytes(32))])->save();

        return $run;
    }

    private function pinnedDocker(RestoreRun $run): DockerProcess
    {
        return new class($run) extends DockerProcess
        {
            public array $commands = [];

            public bool $pinned = false;

            public bool $foreignReplacement = false;

            public bool $removalDeniedWhilePinned = false;

            public bool $failCreate = false;

            public bool $failStream = false;

            public bool $failRemove = false;

            public ?string $inputPath = null;

            public function __construct(private RestoreRun $restoreRun) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = $command;

                if ($command[1] === 'create') {
                    Assert::assertSame($command[3], $this->restoreRun->fresh()->docker_container_id);
                    Assert::assertTrue($this->restoreRun->fresh()->docker_container_cleanup_pending);
                    $this->pinned = true;

                    return new DockerProcessResult($command, $this->failCreate ? 1 : 0, $this->failCreate ? '' : str_repeat('a', 64), $this->failCreate ? 'create response lost' : '');
                }

                if ($command[1] === 'volume') {
                    Assert::assertTrue($this->pinned, 'Ownership must be checked while Docker holds the mount.');
                    $this->removalDeniedWhilePinned = $this->pinned;

                    return new DockerProcessResult($command, 0, json_encode([['Labels' => [CreateDockerVolume::RESTORE_OWNERSHIP_LABEL => $this->foreignReplacement ? 'foreign' : $this->restoreRun->target_volume_ownership_token]]]), '');
                }

                Assert::assertSame('rm', $command[1]);

                if ($this->failRemove) {
                    return new DockerProcessResult($command, 1, '', 'daemon unavailable');
                }

                $this->pinned = false;

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = $command;
                $this->inputPath = $inputPath;
                Assert::assertTrue($this->pinned);
                Assert::assertSame(str_repeat('a', 64), $this->restoreRun->fresh()->docker_container_id);

                if ($this->failStream) {
                    throw new RuntimeException('stream interrupted');
                }

                return new DockerProcessResult($command, 0, 'restored', '');
            }
        };
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
            'name' => 'Job',
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
            'target_volume_name' => 'app_data_restored',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_QUEUED,
        ]);
    }

    private function recordingDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public array $command = [];

            public ?string $inputPath = null;

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->command = $command;
                $this->inputPath = $inputPath;

                return new DockerProcessResult($command, 0, 'restore complete', '');
            }
        };
    }
}
