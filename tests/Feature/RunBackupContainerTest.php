<?php

namespace Tests\Feature;

use App\Actions\Docker\RunBackupContainer;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunBackupContainerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Host-path sources are fail-closed without an allowlist; allow the
        // prefixes the happy-path tests in this file rely on.
        config([
            'volumevault.docker_host' => 'unix:///var/run/docker.sock',
            'volumevault.docker_network' => '',
            'volumevault.host_path_allowlist' => ['/srv'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_volume_source_is_mounted_read_only_and_env_names_are_forwarded(): void
    {
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $command = $docker->command;
        $this->assertSame(['docker', 'run', '--rm', '--name'], array_slice($command, 0, 4));
        $this->assertContains('--entrypoint', $command);
        $this->assertContains('/usr/bin/backup', $command);
        $this->assertSame(RunBackupContainer::IMAGE, end($command));

        // Docker volume sources mount as `-v name:/backup/<name>:ro`.
        $this->assertConsecutive($command, ['-v', 'app_data:/backup/app_data:ro']);
        // The socket is always mounted read-only.
        $this->assertConsecutive($command, ['-v', '/var/run/docker.sock:/var/run/docker.sock:ro']);

        // Secrets travel through the environment array, never as literal command arguments:
        // each variable is passed by name only.
        $this->assertConsecutive($command, ['--env', 'AWS_ACCESS_KEY_ID']);
        $this->assertNotContains('s3-access-key', $command);
        $this->assertNotContains('s3-secret-key', $command);

        $this->assertSame('s3-access-key', $docker->environment['AWS_ACCESS_KEY_ID'] ?? null);
        $this->assertSame('s3-secret-key', $docker->environment['AWS_SECRET_ACCESS_KEY'] ?? null);
        $this->assertSame('backups-bucket', $docker->environment['AWS_S3_BUCKET_NAME'] ?? null);
        $this->assertSame('/backup', $docker->environment['BACKUP_SOURCES'] ?? null);
        $this->assertSame('unix:///var/run/docker.sock', $docker->environment['DOCKER_HOST'] ?? null);
        $this->assertConsecutive($command, ['--env', 'DOCKER_HOST']);
    }

    public function test_tcp_docker_host_is_forwarded_without_mounting_a_unix_socket(): void
    {
        config(['volumevault.docker_host' => 'tcp://docker.example.test:2375']);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertSame('tcp://docker.example.test:2375', $docker->environment['DOCKER_HOST'] ?? null);
        $this->assertConsecutive($docker->command, ['--env', 'DOCKER_HOST']);
        $this->assertNotContains('/var/run/docker.sock:/var/run/docker.sock:ro', $docker->command);
    }

    public function test_configured_docker_network_is_used_for_the_backup_container(): void
    {
        config([
            'volumevault.docker_host' => 'tcp://socket-proxy:2375',
            'volumevault.docker_network' => 'volumevault_proxy-net',
        ]);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertConsecutive($docker->command, ['--network', 'volumevault_proxy-net']);
        $networkIndex = array_search('--network', $docker->command, true);
        $imageIndex = array_search(RunBackupContainer::IMAGE, $docker->command, true);
        $this->assertIsInt($networkIndex);
        $this->assertIsInt($imageIndex);
        $this->assertLessThan($imageIndex, $networkIndex);
    }

    public function test_blank_docker_network_is_ignored(): void
    {
        config(['volumevault.docker_network' => '   ']);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertNotContains('--network', $docker->command);
    }

    public function test_custom_unix_socket_is_forwarded_and_mounted_read_only(): void
    {
        config(['volumevault.docker_host' => 'unix:///run/docker-custom.sock']);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertSame('unix:///run/docker-custom.sock', $docker->environment['DOCKER_HOST'] ?? null);
        $this->assertConsecutive($docker->command, ['-v', '/run/docker-custom.sock:/run/docker-custom.sock:ro']);
    }

    public function test_container_id_is_persisted_and_filename_is_derived_from_the_source(): void
    {
        $docker = $this->recordingDocker();
        $action = new RunBackupContainer($docker);
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        $action->handle($run);

        $containerName = $run->fresh()->docker_container_id;
        $this->assertNotNull($containerName);
        $this->assertStringStartsWith('volumevault-backup-'.$run->id.'-', $containerName);
        $this->assertContains('--name', $docker->command);

        $this->assertSame('volumevault-app_data-run-'.$run->id.'.tar.gz', $action->backupFilename($run));
        $this->assertSame('volumevault-app_data-run-'.$run->id.'.tar.gz', $docker->environment['BACKUP_FILENAME']);
    }

    public function test_backup_container_execution_is_monitored_with_a_heartbeat(): void
    {
        $heartbeats = 0;
        $run = $this->backupRun($this->s3Destination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($this->recordingDocker()))->handle(
            $run,
            function () use (&$heartbeats): void {
                $heartbeats++;
            },
        );

        $this->assertSame(1, $heartbeats);
    }

    public function test_custom_filename_template_uses_sanitized_job_name_run_id_and_time_tokens(): void
    {
        Carbon::setTestNow('2026-06-13 14:15:16');
        $docker = $this->recordingDocker();
        $action = new RunBackupContainer($docker);
        $run = $this->backupRun($this->s3Destination(), [
            'name' => 'App data nightly backup!',
            'volume_name' => 'app_data',
            'backup_filename_template' => '{name}-{year}-{month}-{day}-{time}-{id}',
        ]);
        $run->forceFill(['started_at' => now()])->save();

        $action->handle($run);

        $expected = 'App_data_nightly_backup_-2026-06-13-14-15-16-'.$run->id.'.tar.gz';
        $this->assertSame($expected, $action->backupFilename($run->fresh(['job'])));
        $this->assertSame($expected, $docker->environment['BACKUP_FILENAME']);
    }

    public function test_host_path_source_is_mounted_as_a_read_only_bind(): void
    {
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), [
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => '/srv/data',
            'volume_name' => null,
        ]);

        (new RunBackupContainer($docker))->handle($run);

        // The leading slash is trimmed and the remaining separators are sanitised to underscores.
        $this->assertConsecutive($docker->command, ['--mount', 'type=bind,src=/srv/data,dst=/backup/srv_data,readonly']);
        $this->assertSame('volumevault-srv_data-run-'.$run->id.'.tar.gz', $docker->environment['BACKUP_FILENAME']);
    }

    public function test_retention_settings_are_forwarded_only_when_set(): void
    {
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), [
            'volume_name' => 'app_data',
            'retention_days' => 7,
            'backup_exclude_regexp' => '\.tmp$',
        ]);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertSame('7', $docker->environment['BACKUP_RETENTION_DAYS'] ?? null);
        $this->assertSame('\.tmp$', $docker->environment['BACKUP_EXCLUDE_REGEXP'] ?? null);
        $this->assertArrayNotHasKey('BACKUP_RETENTION_COUNT', $docker->environment);
    }

    public function test_ssh_private_key_is_copied_into_the_created_container_and_cleaned_up(): void
    {
        $docker = $this->recordingDocker();
        $destination = BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => 'ssh.example.com', 'port' => 2222, 'remote_path' => '/backups'],
            'secrets' => [
                'user' => 'backup',
                'private_key' => "-----BEGIN KEY-----\nabc\n-----END KEY-----",
                'private_key_passphrase' => 'key-passphrase',
            ],
        ]);
        $run = $this->backupRun($destination, ['volume_name' => 'app_data']);
        $docker->backupRun = $run;
        $heartbeats = 0;

        $result = (new RunBackupContainer($docker))->handle($run, function () use (&$heartbeats): void {
            $heartbeats++;
        });

        $containerName = $run->fresh()->docker_container_id;
        $this->assertTrue($result->successful());
        $this->assertFalse($run->fresh()->docker_container_cleanup_pending);
        $this->assertTrue($docker->cleanupPendingWhenCreateRan);
        $this->assertSame(1, $heartbeats);
        $this->assertSame(0, $docker->monitoringCommandCountAtStart);
        $this->assertSame(['create', 'cp', 'start', 'rm'], array_map(fn (array $command) => $command[1], $docker->commands));
        $this->assertSame([300, 300, 0, 60], $docker->timeouts);
        $this->assertSame(['docker', 'start', '--attach', $containerName], $docker->commands[2]);
        $this->assertSame(['docker', 'rm', '--force', $containerName], $docker->commands[3]);

        $createCommand = $docker->commands[0];
        $createEnvironment = $docker->environments[0];
        $this->assertSame(['docker', 'create', '--name'], array_slice($createCommand, 0, 3));
        $this->assertNotContains('--rm', $createCommand);
        $this->assertSame('/tmp/volumevault_ssh_key', $createEnvironment['SSH_IDENTITY_FILE'] ?? null);
        $this->assertSame('key-passphrase', $createEnvironment['SSH_IDENTITY_PASSPHRASE'] ?? null);
        $this->assertSame('ssh.example.com', $createEnvironment['SSH_HOST_NAME'] ?? null);
        $this->assertSame('2222', $createEnvironment['SSH_PORT'] ?? null);
        $this->assertFalse(collect($createCommand)->contains(fn (string $argument) => str_contains($argument, 'volumevault_ssh_key')));

        $this->assertSame($containerName.':/tmp/volumevault_ssh_key', $docker->commands[1][3]);
        $this->assertSame("-----BEGIN KEY-----\nabc\n-----END KEY-----", $docker->copiedFileContents);
        $this->assertSame(0600, $docker->copiedFilePermissions);
        $this->assertFileDoesNotExist($docker->copiedFilePath);
        $this->assertFalse(collect($docker->commands)->flatten()->contains("-----BEGIN KEY-----\nabc\n-----END KEY-----"));
    }

    public function test_ssh_private_key_create_failure_attempts_idempotent_removal_and_cleans_up(): void
    {
        Exceptions::fake();
        $docker = $this->recordingDocker();
        $docker->operationResults['create'] = new DockerProcessResult([], 1, '', 'create failed');
        $docker->operationResults['rm'] = new DockerProcessResult([], 1, '', 'Error: No such container: backup');
        $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

        $result = (new RunBackupContainer($docker))->handle($run);

        $this->assertSame(1, $result->exitCode);
        $this->assertSame(['create', 'rm'], array_map(fn (array $command) => $command[1], $docker->commands));
        Exceptions::assertNothingReported();
        $this->assertFalse($run->fresh()->docker_container_cleanup_pending);
        $this->assertSame([], File::files(storage_path('app/docker-secrets')));
    }

    public function test_ssh_private_key_creation_restricts_the_directory_and_restores_the_umask(): void
    {
        $previousUmask = umask(0000);

        try {
            $docker = $this->recordingDocker();
            $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

            (new RunBackupContainer($docker))->handle($run);

            $currentUmask = umask();
            umask($currentUmask);

            $this->assertSame(0000, $currentUmask);
            $this->assertSame(0700, fileperms(storage_path('app/docker-secrets')) & 0777);
            $this->assertSame(0600, $docker->copiedFilePermissions);
        } finally {
            umask($previousUmask);
        }
    }

    public function test_ssh_private_key_removal_failure_is_reported_without_masking_the_create_failure(): void
    {
        Exceptions::fake();
        $docker = $this->recordingDocker();
        $docker->operationResults['create'] = new DockerProcessResult([], 1, '', 'create failed');
        $docker->operationResults['rm'] = new DockerProcessResult([], 1, '', 'removal denied');
        $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

        $result = (new RunBackupContainer($docker))->handle($run);

        $this->assertSame('create failed', $result->errorOutput);
        $this->assertSame(['create', 'rm'], array_map(fn (array $command) => $command[1], $docker->commands));
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => $exception->getMessage() === 'removal denied');
        $this->assertTrue($run->fresh()->docker_container_cleanup_pending);
        $this->assertSame([], File::files(storage_path('app/docker-secrets')));
    }

    public function test_ssh_private_key_copy_failure_stops_before_starting_and_cleans_up(): void
    {
        $docker = $this->recordingDocker();
        $docker->operationResults['cp'] = new DockerProcessResult([], 1, '', 'copy failed');
        $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

        $result = (new RunBackupContainer($docker))->handle($run);

        $this->assertSame(1, $result->exitCode);
        $this->assertSame(['create', 'cp', 'rm'], array_map(fn (array $command) => $command[1], $docker->commands));
        $this->assertFileDoesNotExist($docker->copiedFilePath);
    }

    public function test_ssh_private_key_start_failure_is_returned_and_cleans_up(): void
    {
        $docker = $this->recordingDocker();
        $docker->operationResults['start'] = new DockerProcessResult([], 1, '', 'backup failed');
        $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

        $result = (new RunBackupContainer($docker))->handle($run);

        $this->assertSame(1, $result->exitCode);
        $this->assertSame('backup failed', $result->errorOutput);
        $this->assertSame(['create', 'cp', 'start', 'rm'], array_map(fn (array $command) => $command[1], $docker->commands));
        $this->assertFileDoesNotExist($docker->copiedFilePath);
    }

    public function test_ssh_private_key_copy_works_with_a_tcp_docker_host(): void
    {
        config(['volumevault.docker_host' => 'tcp://docker.example.test:2375']);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertSame(['create', 'cp', 'start', 'rm'], array_map(fn (array $command) => $command[1], $docker->commands));
        $this->assertSame('tcp://docker.example.test:2375', $docker->environments[0]['DOCKER_HOST'] ?? null);
        $this->assertNotContains('/var/run/docker.sock:/var/run/docker.sock:ro', $docker->commands[0]);
    }

    public function test_ssh_private_key_cleanup_runs_when_the_heartbeat_throws(): void
    {
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->sshKeyDestination(), ['volume_name' => 'app_data']);

        try {
            (new RunBackupContainer($docker))->handle($run, function (): void {
                throw new \RuntimeException('heartbeat failed');
            });

            $this->fail('The heartbeat exception should be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('heartbeat failed', $exception->getMessage());
        }

        $this->assertSame([], $docker->commands);
        $this->assertFalse($run->fresh()->docker_container_cleanup_pending);
        $this->assertSame([], File::files(storage_path('app/docker-secrets')));
    }

    public function test_ssh_password_authentication_keeps_the_single_run_command(): void
    {
        $docker = $this->recordingDocker();
        $destination = BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => 'ssh.example.com', 'port' => 22, 'remote_path' => '/backups'],
            'secrets' => ['user' => 'backup', 'password' => 'secret'],
        ]);
        $run = $this->backupRun($destination, ['volume_name' => 'app_data']);

        (new RunBackupContainer($docker))->handle($run);

        $this->assertSame(['run'], array_map(fn (array $command) => $command[1], $docker->commands));
    }

    public function test_ssh_private_key_is_cleaned_up_when_source_mount_validation_fails(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv']]);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->sshKeyDestination(), [
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => '/etc',
            'volume_name' => null,
        ]);

        try {
            (new RunBackupContainer($docker))->handle($run);

            $this->fail('The disallowed source mount should be rejected.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $docker->commands);
        $this->assertSame([], File::files(storage_path('app/docker-secrets')));
    }

    public function test_host_path_source_outside_the_allowlist_is_refused_at_runtime(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv']]);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), [
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => '/etc',
            'volume_name' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new RunBackupContainer($docker))->handle($run);
    }

    public function test_local_destination_outside_the_allowlist_is_refused_at_runtime(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv']]);
        $docker = $this->recordingDocker();
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => '/root/.ssh', 'archive_mount_source' => '/root/.ssh'],
        ]);
        $run = $this->backupRun($destination, ['volume_name' => 'app_data']);

        $this->expectException(\InvalidArgumentException::class);
        (new RunBackupContainer($docker))->handle($run);
    }

    public function test_empty_allowlist_refuses_a_host_path_source_at_runtime(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $docker = $this->recordingDocker();
        $run = $this->backupRun($this->s3Destination(), [
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => '/srv/data',
            'volume_name' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new RunBackupContainer($docker))->handle($run);
    }

    public function test_unsupported_provider_throws(): void
    {
        $docker = $this->recordingDocker();
        $destination = $this->s3Destination();
        $destination->forceFill(['provider' => 'carrier-pigeon'])->save();
        $run = $this->backupRun($destination, ['volume_name' => 'app_data']);

        $this->expectException(\RuntimeException::class);
        (new RunBackupContainer($docker))->handle($run);
    }

    private function assertConsecutive(array $command, array $pair): void
    {
        for ($i = 0; $i < count($command) - 1; $i++) {
            if ($command[$i] === $pair[0] && $command[$i + 1] === $pair[1]) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail('Expected consecutive arguments ['.implode(', ', $pair).'] in the docker command.');
    }

    private function s3Destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups-bucket',
            'region' => 'eu-west-1',
            'access_key_id' => 's3-access-key',
            'secret_access_key' => 's3-secret-key',
        ]);
    }

    private function sshKeyDestination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => 'ssh.example.com', 'port' => 22, 'remote_path' => '/backups'],
            'secrets' => ['user' => 'backup', 'private_key' => "-----BEGIN KEY-----\nabc\n-----END KEY-----"],
        ]);
    }

    private function backupRun(BackupDestination $destination, array $jobOverrides = []): BackupRun
    {
        $job = BackupJob::create(array_merge([
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ], $jobOverrides));

        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
    }

    private function recordingDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public array $command = [];

            public array $commands = [];

            public array $environment = [];

            public array $environments = [];

            public array $operationResults = [];

            public array $timeouts = [];

            public ?int $monitoringCommandCountAtStart = null;

            public ?BackupRun $backupRun = null;

            public ?bool $cleanupPendingWhenCreateRan = null;

            public ?string $copiedFileContents = null;

            public ?int $copiedFilePermissions = null;

            public ?string $copiedFilePath = null;

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->command = $command;
                $this->commands[] = $command;
                $this->environment = $environment;
                $this->environments[] = $environment;
                $this->timeouts[] = $timeout;

                if (($command[1] ?? null) === 'create' && $this->backupRun !== null) {
                    $this->cleanupPendingWhenCreateRan = $this->backupRun->fresh()->docker_container_cleanup_pending;
                }

                if (($command[1] ?? null) === 'cp') {
                    $this->copiedFilePath = $command[2];
                    $this->copiedFileContents = File::get($command[2]);
                    $this->copiedFilePermissions = fileperms($command[2]) & 0777;
                }

                return $this->operationResults[$command[1] ?? ''] ?? new DockerProcessResult($command, 0, 'ok', '');
            }

            public function whileMonitoring(callable $callback, callable $operation, int $intervalSeconds = 30): mixed
            {
                $this->monitoringCommandCountAtStart = count($this->commands);

                return parent::whileMonitoring($callback, $operation, $intervalSeconds);
            }
        };
    }
}
