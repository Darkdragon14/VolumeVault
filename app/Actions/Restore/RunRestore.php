<?php

namespace App\Actions\Restore;

use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\RunRestoreContainer;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Logging\AppendRunLog;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class RunRestore
{
    public function __construct(
        private readonly InspectDockerVolume $inspectDockerVolume,
        private readonly CreateDockerVolume $createDockerVolume,
        private readonly RunRestoreContainer $runRestoreContainer,
        private readonly DestinationStorage $storage,
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function handle(RestoreRun $run): void
    {
        $run->loadMissing('job.destination', 'destination');
        $startedAt = now();
        $archivePath = storage_path('app/restore-runs/'.$run->id.'/backup.tar.gz');

        $run->forceFill([
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => $startedAt,
        ])->save();

        try {
            if ($run->mode !== RestoreRun::MODE_NEW_VOLUME) {
                throw new RuntimeException('Only restore-to-new-volume is currently enabled.');
            }

            if ($this->volumeExists($run->target_volume_name)) {
                throw new RuntimeException('Target Docker volume already exists: '.$run->target_volume_name);
            }

            File::ensureDirectoryExists(dirname($archivePath));

            $this->appendRunLog->handle($run, 'Downloading selected backup object from backup destination.');
            $this->storage->download($run->destination, $run->selected_backup_key, $archivePath);

            $this->appendRunLog->handle($run, 'Creating target Docker volume '.$run->target_volume_name.'.');
            $this->createDockerVolume->handle($run->target_volume_name);

            $this->appendRunLog->handle($run, 'Extracting backup archive into target volume.');
            $result = $this->runRestoreContainer->handle($run->fresh(), $archivePath);
            $this->appendRunLog->handle($run, $result->combinedOutput());

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: 'Restore container failed.');
            }

            DockerVolume::updateOrCreate(['name' => $run->target_volume_name], [
                'exists' => true,
                'last_seen_at' => now(),
            ]);

            $finishedAt = now();
            $run->forceFill([
                'status' => RestoreRun::STATUS_SUCCESS,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            ])->save();
        } catch (Throwable $exception) {
            $finishedAt = now();
            $message = str($exception->getMessage() ?: 'Restore failed.')->limit(1000)->toString();

            $run->forceFill([
                'status' => RestoreRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'error_message' => $message,
            ])->save();

            $this->appendRunLog->handle($run, $message);
        } finally {
            if (File::exists($archivePath)) {
                File::delete($archivePath);
            }
        }
    }

    private function volumeExists(string $volumeName): bool
    {
        try {
            $this->inspectDockerVolume->handle($volumeName);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
