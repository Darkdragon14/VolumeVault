<?php

namespace App\Actions\Backup;

use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Docker\StopDockerContainers;
use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Services\Logging\AppendRunLog;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Services\Scheduling\BackupScheduleCalculator;
use RuntimeException;
use Throwable;

class RunBackup
{
    public function __construct(
        private readonly InspectDockerVolume $inspectDockerVolume,
        private readonly FindContainersUsingVolume $findContainersUsingVolume,
        private readonly StopDockerContainers $stopDockerContainers,
        private readonly StartDockerContainers $startDockerContainers,
        private readonly RunBackupContainer $runBackupContainer,
        private readonly AppendRunLog $appendRunLog,
        private readonly SendShoutrrrNotification $sendShoutrrrNotification,
        private readonly BackupScheduleCalculator $scheduleCalculator,
    ) {}

    public function handle(BackupRun $run): void
    {
        $run->loadMissing('job.destination');

        $job = $run->job;
        $startedAt = now();
        $stoppedContainers = [];

        $run->forceFill([
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => $startedAt,
        ])->save();

        $job->forceFill([
            'status' => BackupJob::STATUS_RUNNING,
            'last_run_at' => $startedAt,
        ])->save();

        ActivityLog::record('backup_run_started', 'Backup run started.', $run, [
            'backup_job_id' => $job->id,
        ]);

        try {
            if (! $job->destination?->is_active) {
                throw new RuntimeException('The backup destination is inactive.');
            }

            $this->inspectDockerVolume->handle($job->volume_name);
            DockerVolume::updateOrCreate(['name' => $job->volume_name], ['exists' => true, 'last_seen_at' => now()]);

            if ($job->stop_containers_before_backup) {
                $containers = $this->findContainersUsingVolume->handle($job->volume_name);
                $stoppedContainers = collect($containers)->pluck('id')->filter()->values()->all();

                if ($stoppedContainers) {
                    $this->appendRunLog->handle($run, 'Stopping containers before backup: '.implode(', ', $stoppedContainers));
                    $this->stopDockerContainers->handle($stoppedContainers);
                }
            }

            $result = $this->runBackupContainer->handle($run->fresh(['job.destination']));
            $this->appendRunLog->handle($run, $result->combinedOutput());

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: 'Backup container failed.');
            }

            $finishedAt = now();
            $run->forceFill([
                'status' => BackupRun::STATUS_SUCCESS,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            ])->save();

            $job->forceFill([
                'status' => BackupJob::STATUS_ACTIVE,
                'last_success_at' => $finishedAt,
                'last_error' => null,
                'pause_reason' => null,
                'next_run_at' => $this->scheduleCalculator->nextRunAt($job->schedule_type, $job->schedule_config ?? [], $finishedAt),
            ])->save();

            $this->sendNotifications($run->fresh(['job.destination']));
        } catch (Throwable $exception) {
            $message = str($exception->getMessage() ?: 'Backup failed.')->limit(1000)->toString();
            $finishedAt = now();

            $run->forceFill([
                'status' => BackupRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'error_message' => $message,
            ])->save();

            $this->appendRunLog->handle($run, $message);

            $job->forceFill([
                'status' => BackupJob::STATUS_ERROR,
                'last_error' => $message,
                'next_run_at' => $this->scheduleCalculator->nextRunAt($job->schedule_type, $job->schedule_config ?? [], $finishedAt),
            ])->save();

            ActivityLog::record('backup_run_failed', 'Backup run failed.', $run, [
                'backup_job_id' => $job->id,
            ]);

            $this->sendNotifications($run->fresh(['job.destination']));
        } finally {
            if ($stoppedContainers) {
                try {
                    $this->startDockerContainers->handle($stoppedContainers);
                    $this->appendRunLog->handle($run->fresh(), 'Restarted containers: '.implode(', ', $stoppedContainers));
                } catch (Throwable $exception) {
                    $this->appendRunLog->handle($run->fresh(), 'Failed to restart containers: '.$exception->getMessage());
                }
            }
        }
    }

    private function sendNotifications(BackupRun $run): void
    {
        try {
            $this->sendShoutrrrNotification->sendBackupRunFinished($run);
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup notification failed.', $run, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }
}
