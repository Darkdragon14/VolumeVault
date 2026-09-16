<?php

namespace Tests\Feature;

use App\Actions\Backup\ApplyPendingDockerLabelReconciliation;
use App\Actions\Backup\RunBackup;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunBackupAtomicFinalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['volumevault.host_path_allowlist' => [sys_get_temp_dir()]]);
    }

    public function test_pending_disable_cannot_be_overwritten_during_success_finalization(): void
    {
        Queue::fake();
        $this->app->instance(DockerProcess::class, $this->successfulDocker());
        $run = $this->managedRun();
        $reconciled = false;

        Event::listen('eloquent.updated: '.BackupRun::class, function (BackupRun $updatedRun) use ($run, &$reconciled): void {
            if ($reconciled || $updatedRun->id !== $run->id || $updatedRun->status !== BackupRun::STATUS_SUCCESS) {
                return;
            }

            $reconciled = true;
            app(ApplyPendingDockerLabelReconciliation::class)->handle($updatedRun->job()->firstOrFail());
        });

        app(RunBackup::class)->handle($run);

        $job = $run->job()->firstOrFail();
        $this->assertTrue($reconciled);
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->refresh()->status);
        $this->assertSame(BackupJob::STATUS_ERROR, $job->status);
        $this->assertNull($job->next_run_at);
        $this->assertNull($job->pending_label_reconciliation);
        $this->assertSame('Definition removed.', $job->label_reconciliation_error);
    }

    public function test_pending_disable_runs_after_failure_lifecycle_is_committed(): void
    {
        $run = $this->managedRun();
        $run->update([
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => now()->subMinute(),
        ]);
        $run->job()->update(['status' => BackupJob::STATUS_RUNNING]);
        $callbackRan = false;

        $transitioned = app(RunBackup::class)->markFailed(
            $run,
            new RuntimeException('backup failed'),
            afterTransition: function () use ($run, &$callbackRan): void {
                $callbackRan = true;
                $job = $run->job()->firstOrFail();

                $this->assertSame(BackupRun::STATUS_FAILED, $run->fresh()->status);
                $this->assertSame(BackupJob::STATUS_ERROR, $job->status);
                $this->assertSame('backup failed', $job->last_error);

                app(ApplyPendingDockerLabelReconciliation::class)->handle($job);
            },
        );

        $job = $run->job()->firstOrFail();
        $this->assertTrue($transitioned);
        $this->assertTrue($callbackRan);
        $this->assertNull($job->next_run_at);
        $this->assertNull($job->pending_label_reconciliation);
        $this->assertSame('Definition removed.', $job->label_reconciliation_error);
    }

    public function test_reconciliation_exception_does_not_annotate_newer_pending_work(): void
    {
        Queue::fake();
        $this->app->instance(DockerProcess::class, $this->successfulDocker());
        $run = $this->managedRun();
        $reconciliation = Mockery::mock(ApplyPendingDockerLabelReconciliation::class);
        $reconciliation->shouldReceive('handle')->once()->andReturnUsing(function (BackupJob $job): never {
            $job->update([
                'pending_label_reconciliation' => ['action' => 'disable', 'message' => 'Newer definition removed.'],
                'label_reconciliation_error' => null,
            ]);

            throw new RuntimeException('stale reconciliation failure');
        });
        $this->app->instance(ApplyPendingDockerLabelReconciliation::class, $reconciliation);

        app(RunBackup::class)->handle($run);

        $job = $run->job()->firstOrFail();
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->refresh()->status);
        $this->assertSame('Newer definition removed.', $job->pending_label_reconciliation['message']);
        $this->assertNull($job->label_reconciliation_error);
    }

    public function test_early_cancellation_applies_pending_configuration_without_unpausing_managed_job(): void
    {
        $docker = Mockery::mock(DockerProcess::class);
        $docker->shouldNotReceive('run', 'runWithInputFile');
        $this->app->instance(DockerProcess::class, $docker);
        $run = $this->managedRunWithPendingConfiguration(BackupJob::STATUS_PAUSED, 'Maintenance window.');

        app(RunBackup::class)->handle($run);

        $job = $run->job()->firstOrFail();
        $this->assertSame(BackupRun::STATUS_CANCELLED, $run->refresh()->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(BackupJob::STATUS_PAUSED, $job->status);
        $this->assertSame('Maintenance window.', $job->pause_reason);
        $this->assertSame(9, $job->retention_count);
        $this->assertNull($job->pending_label_reconciliation);
    }

    public function test_early_cancellation_applies_pending_configuration_to_errored_managed_standalone_job(): void
    {
        $docker = Mockery::mock(DockerProcess::class);
        $docker->shouldNotReceive('run', 'runWithInputFile');
        $this->app->instance(DockerProcess::class, $docker);
        $run = $this->managedRunWithPendingConfiguration(BackupJob::STATUS_ERROR, 'Previous failure.');

        app(RunBackup::class)->handle($run);

        $job = $run->job()->firstOrFail();
        $this->assertSame(BackupRun::STATUS_CANCELLED, $run->refresh()->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(BackupJob::STATUS_ACTIVE, $job->status);
        $this->assertNull($job->pause_reason);
        $this->assertSame(9, $job->retention_count);
        $this->assertNull($job->pending_label_reconciliation);
    }

    private function managedRun(): BackupRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => [
                'archive_path' => sys_get_temp_dir(),
                'archive_mount_source' => sys_get_temp_dir(),
            ],
        ]);
        $job = BackupJob::create([
            'name' => 'Managed backup',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'next_run_at' => now()->addDay(),
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'managed-backup'),
            'pending_label_reconciliation' => ['action' => 'disable', 'message' => 'Definition removed.'],
        ]);

        return BackupRun::create([
            'backup_job_id' => $job->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
        ]);
    }

    private function managedRunWithPendingConfiguration(string $status, ?string $pauseReason): BackupRun
    {
        $run = $this->managedRun();
        $job = $run->job()->firstOrFail();
        DockerVolume::create(['name' => $job->volume_name, 'exists' => true]);
        $payload = $job->only($job->getFillable());
        $payload['retention_count'] = 9;

        $job->update([
            'status' => $status,
            'pause_reason' => $pauseReason,
            'pending_label_reconciliation' => [
                'action' => 'apply',
                'payload' => $payload,
                'next_run_at' => now()->addDay()->toIso8601String(),
                'notification_channel_ids' => [],
            ],
        ]);

        return $run;
    }

    private function successfulDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'volume' && ($command[2] ?? null) === 'inspect') {
                    return new DockerProcessResult($command, 0, '[{"Name":"app_data"}]', '');
                }

                return new DockerProcessResult($command, 0, 'backup complete', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'backup complete', '');
            }
        };
    }
}
