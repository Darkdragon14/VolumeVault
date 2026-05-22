<?php

namespace App\Actions\Backup;

use App\Models\ActivityLog;
use App\Models\BackupJob;
use App\Models\Host;

class MarkMissingVolumeJobs
{
    public function handle(array $missingVolumeNames, Host|int $host): int
    {
        $hostId = $host instanceof Host ? (int) $host->id : $host;
        $names = collect($missingVolumeNames)->filter()->unique()->values();

        if ($names->isEmpty()) {
            return 0;
        }

        $affected = 0;

        BackupJob::query()
            ->where('host_id', $hostId)
            ->whereIn('volume_name', $names->all())
            ->where('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME)
            ->where('status', '!=', BackupJob::STATUS_RUNNING)
            ->get()
            ->each(function (BackupJob $job) use (&$affected): void {
                $message = 'Docker volume not found: '.$job->volume_name;
                $payload = [
                    'last_error' => $message,
                    'last_error_at' => now(),
                ];

                if ($job->status !== BackupJob::STATUS_PAUSED) {
                    $payload['status'] = BackupJob::STATUS_ERROR;
                    $payload['pause_reason'] = $message;
                }

                $job->forceFill($payload)->save();

                ActivityLog::record('missing_volume_detected', $message, $job, [
                    'host_id' => $job->host_id,
                    'volume_name' => $job->volume_name,
                ]);

                $affected++;
            });

        return $affected;
    }
}
