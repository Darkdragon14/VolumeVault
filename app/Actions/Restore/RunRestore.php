<?php

namespace App\Actions\Restore;

use App\Actions\Docker\RunRestoreContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Docker\VerifyRestoreArchive;
use App\Actions\Restore\Modes\InPlaceRestore;
use App\Actions\Restore\Modes\NewVolumeRestore;
use App\Actions\Restore\Modes\RestoreModeHandler;
use App\Actions\Restore\Modes\SafeInPlaceRestore;
use App\Models\ActivityLog;
use App\Models\DockerVolume;
use App\Models\Host;
use App\Models\RestoreRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Logging\AppendRunLog;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunRestore
{
    public function __construct(
        private readonly RunRestoreContainer $runRestoreContainer,
        private readonly VerifyRestoreArchive $verifyRestoreArchive,
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
        $startedAt = now();

        // Atomically claim the run: flip a QUEUED row → RUNNING in a single
        // conditional UPDATE. Claiming only a QUEUED row (not any non-terminal one)
        // means a redelivered copy — the queue can deliver a job twice under
        // retryUntil — finds the row already RUNNING and bails instead of re-running
        // a (possibly destructive) restore a live worker is mid-way through. A row
        // reconciliation already marked terminal also matches zero rows, so a
        // delayed lock loser never resurrects a finalized restore. Mirrors RunBackup.
        $claimed = RestoreRun::query()
            ->whereKey($run->getKey())
            ->where('status', RestoreRun::STATUS_QUEUED)
            ->update([
                'status' => RestoreRun::STATUS_RUNNING,
                'started_at' => $startedAt,
                'last_heartbeat_at' => $startedAt,
            ]);

        if ($claimed === 0) {
            return;
        }

        $run->refresh();
        $run->loadMissing('job.destination', 'job.host', 'destination', 'host');
        $archivePath = storage_path('app/restore-runs/'.$run->id.'/backup.tar.gz');
        $handler = $this->handlerFor($run->mode);
        $prepared = false;

        // No Docker container exists yet, so the heartbeat is the only liveness
        // signal; refresh it per channel so slow start notifications don't get this
        // live restore reconciled as stale (which would release its lock).
        $this->notify($run, fn () => $this->heartbeat($run));

        try {
            $host = $run->host ?: $run->job?->host;

            if (! $host || $host->type !== Host::TYPE_LOCAL) {
                throw new RuntimeException('Only local restore runs can run through the local Docker worker.');
            }

            if ($run->job && $run->job->host_id !== $run->host_id) {
                throw new RuntimeException('The restore job host changed after this run was queued.');
            }

            // Read-only precondition check first, so an invalid target (missing
            // source volume, or a taken new-volume name) fails fast — before the
            // download, the safety backup, and anything destructive.
            $handler->validate($run);

            // Download AND verify the selected archive BEFORE anything else can
            // touch it. Doing this before the safety backup matters: the safety
            // backup reuses the job's filename template and retention, so with a
            // non-unique template it could overwrite, or with tight retention
            // prune, the very object being restored. Holding a verified local copy
            // first makes that harmless. It also keeps the destructive in-place
            // wipe (in prepareTarget) from running without a usable archive.
            // $prepared stays false until the wipe, so any failure here aborts with
            // the volume untouched.
            File::ensureDirectoryExists(dirname($archivePath));

            $this->appendRunLog->handle($run, 'Downloading selected backup object from backup destination.');
            $this->heartbeat($run);
            $this->storage->download($run->destination, $run->selected_backup_key, $archivePath);
            $this->verifyArchive($run, $archivePath);
            $this->heartbeat($run);

            // Optional safety net for the destructive in-place modes: back up the
            // source volume's current contents before anything wipes it. Runs after
            // the download (so it cannot clobber the selected archive) but before
            // prepareTarget, so a backup failure still aborts the restore while the
            // volume is untouched ($prepared stays false → no cleanup, no clear).
            if ($run->backup_before_overwrite && in_array($run->mode, [RestoreRun::MODE_INPLACE, RestoreRun::MODE_SAFE_INPLACE], true)) {
                $this->runPreRestoreBackup->handle($run);
                $this->heartbeat($run);
            }

            // Re-read the run immediately before the destructive step. If stale-run
            // reconciliation (or any out-of-band actor) finalized it while we were
            // downloading, verifying or running the safety backup, abort now rather
            // than wipe a volume for a run already marked failed. Sync the model
            // first so the catch's markFailed sees the terminal state and no-ops
            // (no duplicate failure notification).
            if ($run->fresh()->status !== RestoreRun::STATUS_RUNNING) {
                $run->refresh();

                throw new RuntimeException('Restore was finalized out of band before the target was prepared; aborting to avoid an unexpected volume change.');
            }

            // prepareTarget may stop containers and/or create/clear a volume. It
            // runs only after the archive is downloaded and verified; the
            // $prepared flag gates cleanupAfterFailure so we never remove a
            // volume that already existed (prepareTarget threw on its guard).
            $handler->prepareTarget($run);
            $prepared = true;

            // The in-place clear container (--rm) recorded as docker_container_id is
            // gone now; drop the dead reference and refresh the heartbeat so the gap
            // before the extraction container starts is covered by the heartbeat
            // instead of a stale container id a sweep would read as dead.
            $run->forceFill(['docker_container_id' => null, 'last_heartbeat_at' => now()])->save();

            $this->appendRunLog->handle($run, 'Extracting backup archive into target volume.');
            $result = $this->runRestoreContainer->handle($run->fresh(), $archivePath);
            $this->appendRunLog->handle($run, $result->combinedOutput());

            if (! $result->successful()) {
                throw new RuntimeException($result->combinedOutput() ?: 'Restore container failed.');
            }

            DockerVolume::updateOrCreate(
                ['host_id' => $host->id, 'name' => $run->target_volume_name],
                [
                    'exists' => true,
                    'last_seen_at' => now(),
                ]
            );

            $finishedAt = now();
            $run->forceFill([
                'status' => RestoreRun::STATUS_SUCCESS,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
            ])->save();

            // The run is terminal but the queue job still holds the overlap lock
            // through these notifications. Refresh the heartbeat up front and after
            // each channel (each can take ~60s) so a legitimately-waiting same-volume
            // run is not reconciled as stale while this holder finishes notifying.
            $this->heartbeat($run);
            $this->notify($run, fn () => $this->heartbeat($run));
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
     *
     * The transition is a conditional UPDATE (non-terminal → failed), not an
     * in-memory check + save: the reconciliation command holds models it
     * materialized earlier, and the worker may finish a run between that snapshot
     * and this call. The condition makes the write lose that race instead of
     * overwriting a just-succeeded run. Returns whether it actually transitioned,
     * so callers know if a stuck run was genuinely failed (and e.g. its lock can
     * be released) versus the call being a no-op on an already-terminal run.
     */
    public function markFailed(RestoreRun $run, Throwable $exception): bool
    {
        $finishedAt = now();
        $startedAt = $run->started_at ?? $finishedAt;
        $message = str($exception->getMessage() ?: 'Restore failed.')->limit(1000)->toString();

        $transitioned = RestoreRun::query()
            ->whereKey($run->getKey())
            ->whereNotIn('status', [RestoreRun::STATUS_SUCCESS, RestoreRun::STATUS_FAILED, RestoreRun::STATUS_CANCELLED])
            ->update([
                'status' => RestoreRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'error_message' => $message,
            ]);

        if ($transitioned === 0) {
            return false;
        }

        $run->refresh();

        $this->appendRunLog->handle($run, $message);

        // Central failure notification: markFailed is reached from the in-process
        // catch block, the queue job's failed() hook and stale-run reconciliation,
        // so every failure path notifies. The conditional transition above keeps it
        // to a single send. Refresh the heartbeat up front and after each channel
        // (each ~60s) so a terminal restore still holding the overlap lock through
        // slow notifications is not reconciled as stale.
        $this->heartbeat($run);
        $this->notify($run, fn () => $this->heartbeat($run));

        return true;
    }

    /**
     * Send a restore lifecycle notification without ever letting a notification
     * failure interrupt the restore. Mirrors RunBackup::sendNotifications.
     */
    private function notify(RestoreRun $run, ?callable $afterEach = null): void
    {
        try {
            $this->sendShoutrrrNotification->sendRestoreRun($run, $afterEach);
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

        // Read the whole archive (gzip + tar) without extracting, so a truncated
        // or corrupt download fails here rather than partway through tar -xzf —
        // after the in-place wipe has already cleared the volume. The check runs
        // in a named container recorded as the run's container id so a long verify
        // on a huge archive is reconciled on liveness, not failed (no heartbeat
        // fires during the blocking call, and the archive's mtime is now frozen).
        $containerName = 'volumevault-verify-'.$run->id.'-'.Str::lower(Str::random(8));
        $run->forceFill(['docker_container_id' => $containerName])->save();

        $result = $this->verifyRestoreArchive->handle($archivePath, $containerName);

        // The verify container is gone (--rm); drop the now-dead reference and
        // refresh the heartbeat so liveness falls back to the heartbeat until the
        // next container (clear/extract) starts.
        $run->forceFill(['docker_container_id' => null, 'last_heartbeat_at' => now()])->save();

        if (! $result->successful()) {
            throw new RuntimeException(
                'Downloaded backup archive is not a readable .tar.gz; aborting before touching the target volume. '.
                ($result->combinedOutput() ?: $run->selected_backup_key)
            );
        }

        $this->appendRunLog->handle($run, 'Verified downloaded archive ('.File::size($archivePath).' bytes, readable .tar.gz) before preparing the target volume.');
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

    private function queueAgentRestore(RestoreRun $run, Host $host): void
    {
        AgentCommand::create([
            'host_id' => $host->id,
            'type' => AgentCommand::TYPE_RESTORE_RUN,
            'status' => AgentCommand::STATUS_PENDING,
            'restore_run_id' => $run->id,
            'payload' => [
                'restore_run_id' => $run->id,
                'selected_backup_key' => $run->selected_backup_key,
                'source_volume_name' => $run->source_volume_name,
                'target_volume_name' => $run->target_volume_name,
                'mode' => $run->mode,
                'destination' => $this->destinationPayload($run->destination),
            ],
            'secret_payload' => [
                'destination' => $this->destinationSecretPayload($run->destination),
            ],
        ]);

        $this->appendRunLog->handle($run, 'Queued restore command for agent host '.$host->name.'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function destinationPayload(?BackupDestination $destination): array
    {
        if (! $destination) {
            return [];
        }

        return [
            'id' => $destination->id,
            'provider' => $destination->provider,
            'endpoint' => $destination->endpoint,
            'region' => $destination->region,
            'bucket' => $destination->bucket,
            'path_prefix' => $destination->path_prefix,
            'settings' => $destination->settings ?: [],
            'use_path_style_endpoint' => $destination->use_path_style_endpoint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function destinationSecretPayload(?BackupDestination $destination): array
    {
        if (! $destination) {
            return [];
        }

        return [
            'access_key_id' => $destination->secret('access_key_id'),
            'secret_access_key' => $destination->secret('secret_access_key'),
            'secrets' => $destination->secrets ?: [],
        ];
    }
}
