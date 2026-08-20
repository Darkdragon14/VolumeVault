<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

class RunBackupCleanupLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_helper_removal_keeps_applications_stopped_until_reconciliation(): void
    {
        Exceptions::fake();
        $docker = $this->dockerWithOneRemovalFailure();
        $this->app->instance(DockerProcess::class, $docker);

        $destination = BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => 'ssh.example.com', 'port' => 22, 'remote_path' => '/backups'],
            'secrets' => ['user' => 'backup', 'private_key' => "-----BEGIN KEY-----\nabc\n-----END KEY-----"],
        ]);
        $job = BackupJob::create([
            'name' => 'Application',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'stop_containers_before_backup' => true,
        ]);
        $run = BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);

        app(RunBackup::class)->handle($run);

        $run->refresh();
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->docker_container_cleanup_pending);
        $this->assertSame(['app-1'], $run->stopped_container_ids);
        $this->assertNotContains(['docker', 'start', 'app-1'], $docker->commands);
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => $exception->getMessage() === 'Docker remove failed.');

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $run->refresh();
        $this->assertFalse($run->docker_container_cleanup_pending);
        $this->assertNull($run->stopped_container_ids);
        $removeIndexes = array_keys(array_filter(
            $docker->commands,
            fn (array $command): bool => ($command[1] ?? null) === 'rm',
        ));
        $startIndex = array_search(['docker', 'start', 'app-1'], $docker->commands, true);
        $this->assertCount(2, $removeIndexes);
        $this->assertIsInt($startIndex);
        $this->assertLessThan($startIndex, $removeIndexes[1]);
    }

    private function dockerWithOneRemovalFailure(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public array $commands = [];

            private int $removalAttempts = 0;

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = $command;

                if (array_slice($command, 1, 2) === ['volume', 'inspect']) {
                    return new DockerProcessResult($command, 0, '[{"Name":"app_data","Driver":"local"}]', '');
                }

                if (($command[1] ?? null) === 'ps') {
                    return new DockerProcessResult($command, 0, '{"ID":"app-1","Names":"application","State":"running"}', '');
                }

                if (($command[1] ?? null) === 'rm') {
                    $this->removalAttempts++;

                    if ($this->removalAttempts === 1) {
                        return new DockerProcessResult($command, 1, '', 'Docker remove failed.');
                    }
                }

                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
    }
}
