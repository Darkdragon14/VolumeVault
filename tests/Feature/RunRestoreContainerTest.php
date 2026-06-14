<?php

namespace Tests\Feature;

use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\RunRestoreContainer;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunRestoreContainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_command_mounts_volume_streams_archive_and_strips_components(): void
    {
        $docker = $this->recordingDocker();
        $run = $this->restoreRun();
        $archivePath = '/var/lib/restore/backup.tar.gz';

        (new RunRestoreContainer($docker))->handle($run, $archivePath);

        $command = $docker->command;
        $this->assertSame(['docker', 'run', '--rm', '--name'], array_slice($command, 0, 4));

        // The target volume is writable; the archive is streamed over stdin so
        // containerized deployments do not depend on host-visible app paths.
        $this->assertContains('-i', $command);
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
            'mode' => RestoreRun::MODE_NEW_VOLUME,
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
