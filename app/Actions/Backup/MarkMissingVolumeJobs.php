<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\DockerVolume;
use Illuminate\Support\Facades\DB;

class MarkMissingVolumeJobs
{
    public function handle(array $missingVolumeNames): int
    {
        $names = collect($missingVolumeNames)->filter()->unique()->values();

        if ($names->isEmpty()) {
            return 0;
        }

        $affected = 0;

        BackupJob::query()
            ->whereIn('volume_name', $names->all())
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->pluck('id')
            ->each(function (int $jobId) use ($names, &$affected): void {
                DB::transaction(function () use ($jobId, $names, &$affected): void {
                    $jobVolumeName = BackupJob::query()->whereKey($jobId)->value('volume_name');

                    if (blank($jobVolumeName) || ! $names->contains($jobVolumeName)) {
                        return;
                    }

                    $volume = DockerVolume::query()
                        ->where('name', $jobVolumeName)
                        ->lockForUpdate()
                        ->first();

                    if ($volume?->isAvailable()) {
                        return;
                    }

                    $job = BackupJob::query()->lockForUpdate()->find($jobId);

                    if ($job === null || ! $job->isDockerVolumeSource() || $job->volume_name !== $jobVolumeName) {
                        return;
                    }

                    $pending = $job->pending_label_reconciliation;

                    if ($job->isDockerLabelManaged()
                        && $job->label_reconciliation_error === null
                        && is_array($pending)
                        && ($pending['action'] ?? null) === 'apply'
                        && ($pending['payload']['source_type'] ?? BackupJob::SOURCE_TYPE_DOCKER_VOLUME) === BackupJob::SOURCE_TYPE_DOCKER_VOLUME
                        && filled($pending['payload']['volume_name'] ?? null)
                        && $pending['payload']['volume_name'] !== $job->volume_name) {
                        return;
                    }

                    $message = 'Docker volume not found: '.$job->volume_name;
                    $payload = [];

                    if ($job->isDockerLabelManaged()) {
                        if ($job->label_reconciliation_error !== $message) {
                            $payload['label_reconciliation_error'] = $message;
                        }

                        if (is_array($job->pending_label_reconciliation)
                            && ($job->pending_label_reconciliation['message'] ?? null) !== $message) {
                            $payload['pending_label_reconciliation'] = [
                                ...$job->pending_label_reconciliation,
                                'message' => $message,
                            ];
                        }
                    }

                    if ($job->status === BackupJob::STATUS_RUNNING) {
                        if ($payload === []) {
                            return;
                        }
                    } else {
                        if ($job->last_error !== $message) {
                            $payload['last_error'] = $message;
                            $payload['last_error_at'] = now();
                        }

                        if ($job->status !== BackupJob::STATUS_PAUSED) {
                            if ($job->status !== BackupJob::STATUS_ERROR) {
                                $payload['status'] = BackupJob::STATUS_ERROR;
                            }

                            if ($job->pause_reason !== $message) {
                                $payload['pause_reason'] = $message;
                            }
                        }
                    }

                    if ($payload === []) {
                        return;
                    }

                    $job->forceFill($payload)->save();

                    ActivityLog::record('missing_volume_detected', $message, $job, [
                        'volume_name' => $job->volume_name,
                    ]);

                    $affected++;
                });
            });

        return $affected;
    }
}
