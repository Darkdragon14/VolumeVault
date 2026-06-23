<?php

namespace App\Actions\Restore;

use App\Actions\Docker\RunRestoreContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Restore\Modes\InPlaceRestore;
use App\Actions\Restore\Modes\NewVolumeRestore;
use App\Actions\Restore\Modes\RestoreModeHandler;
use App\Actions\Restore\Modes\SafeInPlaceRestore;
use App\Models\ActivityLog;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Logging\AppendRunLog;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class RunRestore
{
    public function __construct(
        private readonly RunRestoreContainer $runRestoreContainer,
        private readonly StartDockerContainers $startDockerContainers,
        private readonly DestinationStorage $storage,
        private readonly AppendRunLog $appendRunLog,
        private readonly NewVolumeRestore $newVolumeRestore,
        private readonly InPlaceRestore $inPlaceRestore,
        private readonly SafeInPlaceRestore $safeInPlaceRestore,
        private readonly SendShoutrrrNotification $sendShoutrrrNotification,
        private readonly RunPreRestoreBackup $runPreRestoreBackup,
    ) {}

    public function handle(RestoreRun $run): void
    {
        $run->loadMissing('job.destination', 'destination');
        $startedAt = now();
        $archivePath = storage_path('app/restore-runs/'.$run->id.'/backup.tar.gz');
        $handler = $this->handlerFor($run->mode);
        $prepared = false;

        $run->forceFill([
            'status' => RestoreRun::STATUS_RUNNING,
            'started_at' => $startedAt,
            'last_heartbeat_at' => $startedAt,
        ])->save();

        $this->notify($run);

        try {
            // Read-only precondition check first, so an invalid target (missing
            // source volume, or a taken new-volume name) fails fast — before the
            // safety backup or the potentially-slow download, and before anything
            // destructive runs.
            $handler->validate($run);

            // Optional safety net for the destructive in-place modes: back up the
            // source volume's current contents before anything wipes it. Runs
            // before prepareTarget so a backup failure aborts the restore while the
            // volume is still untouched ($prepared stays false → no cleanup, no
            // ClearDockerVolume).
            if ($run->backup_before_overwrite && in_array($run->mode, [RestoreRun::MODE_INPLACE, RestoreRun::MODE_SAFE_INPLACE], true)) {
                $this->runPreRestoreBackup->handle($run);
                $this->heartbeat($run);
            }

            // Download AND verify the archive BEFORE any destructive step. The
            // in-place modes wipe the live source volume in prepareTarget(); doing
            // that only once we already hold a usable archive means a vanished
            // backup object or a network failure can never leave the volume empty
            // with nothing to restore from. $prepared stays false until the wipe,
            // so a failure here aborts with the volume untouched.
            File::ensureDirectoryExists(dirname($archivePath));

            $this->appendRunLog->handle($run, 'Downloading selected backup object from backup destination.');
            $this->heartbeat($run);
            $this->storage->download($run->destination, $run->selected_backup_key, $archivePath);
            $this->verifyArchive($run, $archivePath);
            $this->heartbeat($run);

            // prepareTarget may stop containers and/or create/clear a volume. It
            // runs only after the archive is downloaded and verified; the
            // $prepared flag gates cleanupAfterFailure so we never remove a
            // volume that already existed (prepareTarget threw on its guard).
            $handler->prepareTarget($run);
            $prepared = true;

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

            $this->notify($run);
        } catch (Throwable $exception) {
            if ($prepared) {
                $handler->cleanupAfterFailure($run);
            }

            $this->markFailed($run, $exception);
        } finally {
            $this->restartStoppedContainersQuietly($run->fresh());

            if (File::exists($archivePath)) {
                File::delete($archivePath);
            }
        }
    }

    /**
     * Force a restore run into the FAILED state.
     *
     * Shared by the in-process catch block, the queue job's failed() hook
     * (worker timeout / restart) and the stale-run reconciliation command.
     * Idempotent: runs that already reached a terminal state are left untouched.
     */
    public function markFailed(RestoreRun $run, Throwable $exception): void
    {
        if (in_array($run->status, [RestoreRun::STATUS_SUCCESS, RestoreRun::STATUS_FAILED, RestoreRun::STATUS_CANCELLED], true)) {
            return;
        }

        $finishedAt = now();
        $startedAt = $run->started_at ?? $finishedAt;
        $message = str($exception->getMessage() ?: 'Restore failed.')->limit(1000)->toString();

        $run->forceFill([
            'status' => RestoreRun::STATUS_FAILED,
            'finished_at' => $finishedAt,
            'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            'error_message' => $message,
        ])->save();

        $this->appendRunLog->handle($run, $message);

        // Central failure notification: markFailed is reached from the in-process
        // catch block, the queue job's failed() hook and stale-run reconciliation,
        // so every failure path notifies. The terminal-state guard above keeps it
        // to a single send.
        $this->notify($run);
    }

    /**
     * Send a restore lifecycle notification without ever letting a notification
     * failure interrupt the restore. Mirrors RunBackup::sendNotifications.
     */
    private function notify(RestoreRun $run): void
    {
        try {
            $this->sendShoutrrrNotification->sendRestoreRun($run);
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Restore notification failed.', $run, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }

    /**
     * Restart application containers a previous safe in-place restore stopped
     * but never restarted (worker crash between stop and restart).
     *
     * Used by the stale-run reconciliation command. Idempotent: re-running
     * `docker start` on an already-running container succeeds, and the IDs are
     * only cleared once every container is back up. Propagates failures so the
     * caller can report them — the finally block uses the quiet variant instead.
     */
    public function restartStoppedContainers(RestoreRun $run): void
    {
        $containerIds = $run->stopped_container_ids ?? [];

        if (! $containerIds) {
            return;
        }

        $this->startDockerContainers->handle($containerIds);

        $run->forceFill(['stopped_container_ids' => null])->save();

        $message = 'Restarted containers left stopped after an interrupted restore: '.implode(', ', $containerIds);
        $this->appendRunLog->handle($run, $message);

        ActivityLog::record('restore_run_containers_reconciled', $message, $run, [
            'backup_job_id' => $run->backup_job_id,
        ]);
    }

    /**
     * Restart stopped containers from RunRestore's finally block, swallowing
     * failures: a failed restart must not mask the restore outcome. The IDs are
     * left set so reconciliation can retry.
     */
    private function restartStoppedContainersQuietly(RestoreRun $run): void
    {
        $containerIds = $run->stopped_container_ids ?? [];

        if (! $containerIds) {
            return;
        }

        try {
            $this->startDockerContainers->handle($containerIds);
            $run->forceFill(['stopped_container_ids' => null])->save();
            $this->appendRunLog->handle($run->fresh(), 'Restarted containers: '.implode(', ', $containerIds));
        } catch (Throwable $exception) {
            $this->appendRunLog->handle($run->fresh(), 'Failed to restart containers: '.$exception->getMessage());
        }
    }

    /**
     * Refresh the run's progress marker. While a restore is downloading the
     * archive or running a safety backup there is no Docker container to check
     * for liveness, so stale-run reconciliation falls back to this timestamp to
     * avoid failing a healthy-but-slow restore (see ReconcileStaleRuns::isStale).
     */
    private function heartbeat(RestoreRun $run): void
    {
        $run->forceFill(['last_heartbeat_at' => now()])->save();
    }

    /**
     * Confirm the download produced a usable archive before any destructive step.
     *
     * A vanished object or a half-finished transfer can yield a missing or
     * zero-byte file; catching that here keeps the in-place wipe from running
     * with nothing to restore.
     */
    private function verifyArchive(RestoreRun $run, string $archivePath): void
    {
        if (! File::exists($archivePath) || File::size($archivePath) === 0) {
            throw new RuntimeException('Downloaded backup archive is missing or empty; aborting before touching the target volume: '.$run->selected_backup_key);
        }

        $this->appendRunLog->handle($run, 'Verified downloaded archive ('.File::size($archivePath).' bytes) before preparing the target volume.');
    }

    private function handlerFor(string $mode): RestoreModeHandler
    {
        return match ($mode) {
            RestoreRun::MODE_NEW_VOLUME => $this->newVolumeRestore,
            RestoreRun::MODE_INPLACE => $this->inPlaceRestore,
            RestoreRun::MODE_SAFE_INPLACE => $this->safeInPlaceRestore,
            default => throw new RuntimeException('Unsupported restore mode: '.$mode),
        };
    }
}
