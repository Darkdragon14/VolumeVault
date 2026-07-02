<?php

namespace App\Actions\Backup;

use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\ListDockerContainers;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Docker\StopDockerContainers;
use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\BackupSources\HostPathPolicy;
use App\Services\Docker\SelfContainerResolver;
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
        private readonly ListDockerContainers $listDockerContainers,
        private readonly StopDockerContainers $stopDockerContainers,
        private readonly StartDockerContainers $startDockerContainers,
        private readonly RunBackupContainer $runBackupContainer,
        private readonly SelfContainerResolver $selfContainerResolver,
        private readonly AppendRunLog $appendRunLog,
        private readonly ListBackupObjects $listBackupObjects,
        private readonly HostPathPolicy $hostPathPolicy,
        private readonly SendShoutrrrNotification $sendShoutrrrNotification,
        private readonly BackupScheduleCalculator $scheduleCalculator,
    ) {}

    public function handle(BackupRun $run): void
    {
        $startedAt = now();

        // Atomically claim the run: flip a non-terminal row → RUNNING in one
        // conditional UPDATE. A lock loser requeued by WithoutOverlapping may be
        // delivered after stale-run reconciliation marked the row failed; checking
        // the in-memory status then saving would race that and re-run it. If the
        // row is already terminal the update matches zero rows and we bail.
        $claimed = BackupRun::query()
            ->whereKey($run->getKey())
            ->whereNotIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
            ->update([
                'status' => BackupRun::STATUS_RUNNING,
                'started_at' => $startedAt,
            ]);

        if ($claimed === 0) {
            return;
        }

        $run->refresh();
        $run->loadMissing('job.destination');

        $job = $run->job;
        $stoppedContainers = [];

        // A pre-restore safety backup borrows the full backup pipeline but must
        // stay invisible to the job's lifecycle: touching the job here would, for
        // a manually paused job, flip it to running/active and clear pause_reason,
        // silently unpausing it. Only real scheduled/manual runs drive job state.
        if (! $this->isPreRestore($run)) {
            $job->forceFill([
                'status' => BackupJob::STATUS_RUNNING,
                'last_run_at' => $startedAt,
            ])->save();
        }

        ActivityLog::record('backup_run_started', 'Backup run started.', $run, [
            'backup_job_id' => $job->id,
        ]);

        // A pre-restore safety backup stays invisible to the job lifecycle, so it
        // must not emit a start notification either (see the job-state guard above).
        // A member run of a group defers to its group run, which emits a single
        // aggregated start notification for the whole set — stay silent here too.
        if (! $this->isPreRestore($run) && ! $run->belongsToGroupRun()) {
            $this->sendStartNotification($run);
        }

        try {
            if (! $job->destination?->is_active) {
                throw new RuntimeException('The backup destination is inactive.');
            }

            if ($job->isDockerVolumeSource()) {
                $this->inspectDockerVolume->handle($job->volume_name);
                DockerVolume::updateOrCreate(['name' => $job->volume_name], ['exists' => true, 'last_seen_at' => now()]);
            } else {
                $this->hostPathPolicy->assertValid((string) $job->host_path);
            }

            if ($job->stop_containers_before_backup) {
                // Docker volumes auto-discover the containers mounting them; host
                // path jobs can't be mapped back to containers, so the user picks
                // them by name and we resolve those to running containers here.
                $containers = $job->isDockerVolumeSource()
                    ? $this->findContainersUsingVolume->handle($job->volume_name)
                    : $this->selectContainersByName($run, $job->stop_container_names ?? []);

                // Never stop VolumeVault's own container: if it happens to mount
                // the targeted volume, stopping it would kill this very backup
                // (and the worker) mid-run. Skip and log it instead.
                [$containers, $selfContainers] = $this->selfContainerResolver->partitionSelf($containers);

                if ($selfContainers) {
                    $skipped = collect($selfContainers)->pluck('id')->filter()->implode(', ');
                    $this->appendRunLog->handle($run, "Skipping VolumeVault's own container ({$skipped}) to avoid interrupting the backup.");
                }

                $stoppedContainers = collect($containers)->pluck('id')->filter()->values()->all();

                if ($stoppedContainers) {
                    // Persist the IDs before stopping so a worker crash mid-run
                    // can be reconciled later (the finally block clears them).
                    $run->forceFill(['stopped_container_ids' => $stoppedContainers])->save();
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

            // next_run_at is owned by CreateBackupRun, which already advanced it to
            // the next theoretical slot when this run was queued. Recomputing it here
            // from finishedAt would skip the slot whenever a run overruns its interval.
            // Skipped for a pre-restore safety backup so it never unpauses or
            // reschedules the job (see the start-of-run note).
            if (! $this->isPreRestore($run)) {
                $job->forceFill([
                    'status' => BackupJob::STATUS_ACTIVE,
                    'last_success_at' => $finishedAt,
                    'last_error' => null,
                    'last_error_at' => null,
                    'pause_reason' => null,
                ])->save();
            }

            // For a group member, refresh the group run heartbeat before the
            // (bounded, but possibly slow) archive-metadata listing. The member run
            // just went terminal, so it no longer protects the group run from
            // stale-run reconciliation; a fresh heartbeat keeps the live group run
            // from being falsely closed while this post-backup phase runs.
            $this->touchGroupRunHeartbeat($run);

            $this->recordBackupArchiveMetadata($run->fresh(['job.destination']));

            // Member runs stay silent: the group run aggregates all members and
            // emits the single success/fail notification for the whole set.
            if (! $run->belongsToGroupRun()) {
                $this->sendNotifications($run->fresh(['job.destination']));
            }
        } catch (Throwable $exception) {
            $this->markFailed($run, $exception);
        } finally {
            if ($stoppedContainers) {
                try {
                    $this->startDockerContainers->handle($stoppedContainers);
                    $run->forceFill(['stopped_container_ids' => null])->save();
                    $this->appendRunLog->handle($run->fresh(), 'Restarted containers: '.implode(', ', $stoppedContainers));
                } catch (Throwable $exception) {
                    // Leave stopped_container_ids set so reconciliation can retry.
                    $this->appendRunLog->handle($run->fresh(), 'Failed to restart containers: '.$exception->getMessage());
                }
            }
        }
    }

    /**
     * Restart application containers that a previous run stopped but never
     * restarted (worker crash between stop and restart).
     *
     * Used by the stale-run reconciliation command. Idempotent: re-running
     * `docker start` on an already-running container succeeds, and the IDs are
     * only cleared once every container is back up.
     */
    public function restartStoppedContainers(BackupRun $run): void
    {
        $containerIds = $run->stopped_container_ids ?? [];

        if (! $containerIds) {
            return;
        }

        $this->startDockerContainers->handle($containerIds);

        $run->forceFill(['stopped_container_ids' => null])->save();

        $message = 'Restarted containers left stopped after an interrupted run: '.implode(', ', $containerIds);
        $this->appendRunLog->handle($run, $message);

        ActivityLog::record('backup_run_containers_reconciled', $message, $run, [
            'backup_job_id' => $run->backup_job_id,
        ]);
    }

    /**
     * Resolve the container names a host-path job selected to the running
     * containers that still carry them.
     *
     * Names (not ids) are stored so the selection survives container
     * recreation. Names that no longer resolve to a container are logged and
     * skipped — a missing or removed container must never fail the backup.
     * Only running containers are returned: we must not "restart" a container
     * the user had deliberately stopped, so it never enters stopped_container_ids.
     */
    private function selectContainersByName(BackupRun $run, array $names): array
    {
        $wanted = collect($names)
            ->map(fn ($name) => strtolower(trim((string) $name, " \t\n\r\0\x0B/")))
            ->filter()
            ->unique()
            ->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        $containers = $this->listDockerContainers->handle();
        $matched = [];
        $seen = collect();

        foreach ($containers as $container) {
            $containerNames = collect(explode(',', (string) ($container['names'] ?? '')))
                ->map(fn ($name) => strtolower(trim($name, " \t\n\r\0\x0B/")))
                ->filter();

            $hit = $containerNames->first(fn (string $name) => $wanted->contains($name));

            if (! $hit) {
                continue;
            }

            $seen->push($hit);

            if (strtolower((string) ($container['state'] ?? '')) !== 'running') {
                $this->appendRunLog->handle($run, "Selected container \"{$hit}\" is not running, skipping.");

                continue;
            }

            $matched[] = $container;
        }

        $missing = $wanted->reject(fn (string $name) => $seen->contains($name));

        foreach ($missing as $name) {
            $this->appendRunLog->handle($run, "Selected container \"{$name}\" not found, skipping.");
        }

        return $matched;
    }

    /**
     * A safety backup taken automatically before an in-place restore overwrites a
     * volume. It reuses this pipeline for the actual tar+upload but must not drive
     * the job's lifecycle (status, pause, scheduling).
     */
    private function isPreRestore(BackupRun $run): bool
    {
        return $run->trigger === BackupRun::TRIGGER_PRE_RESTORE;
    }

    /**
     * Force a run into the FAILED state and reschedule its job.
     *
     * Shared by the in-process catch block, the queue job's failed() hook
     * (worker timeout / restart) and the stale-run reconciliation command.
     *
     * The transition is a conditional UPDATE (non-terminal → failed), not an
     * in-memory check + save: reconciliation holds models it materialized earlier
     * and the worker may finish a run between that snapshot and this call. The
     * condition makes the write lose that race rather than overwrite a
     * just-succeeded run. Returns whether it actually transitioned so callers can
     * tell a genuinely-failed stuck run from a no-op on an already-terminal run.
     */
    public function markFailed(BackupRun $run, Throwable $exception): bool
    {
        $run->loadMissing('job.destination');

        $job = $run->job;
        $finishedAt = now();
        $startedAt = $run->started_at ?? $finishedAt;
        $message = str($exception->getMessage() ?: 'Backup failed.')->limit(1000)->toString();

        $transitioned = BackupRun::query()
            ->whereKey($run->getKey())
            ->whereNotIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
            ->update([
                'status' => BackupRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $startedAt->diffInSeconds($finishedAt),
                'error_message' => $message,
            ]);

        if ($transitioned === 0) {
            return false;
        }

        $run->refresh();

        $this->appendRunLog->handle($run, $message);

        // A failed pre-restore safety backup must not flip the job to error or
        // reschedule it — the failure belongs to the restore, which aborts and is
        // surfaced through its own RestoreRun. Leaving the job (incl. a paused one)
        // untouched keeps its lifecycle intact.
        if ($job && ! $this->isPreRestore($run)) {
            $attributes = [
                'status' => BackupJob::STATUS_ERROR,
                'last_error' => $message,
                'last_error_at' => $finishedAt,
            ];

            // A group member delegates scheduling to its group — never advance its
            // own next_run_at (which stays null); the group owns the next slot.
            if (! $job->isGroupMember()) {
                $attributes['next_run_at'] = $this->scheduleCalculator->nextRunAt(
                    $job->schedule_type,
                    $job->schedule_config ?? [],
                    $job->next_run_at && $job->next_run_at->isPast() ? $job->next_run_at : null,
                    $job->timezone,
                );
            }

            $job->forceFill($attributes)->save();
        }

        ActivityLog::record('backup_run_failed', 'Backup run failed.', $run, [
            'backup_job_id' => $job?->id,
        ]);

        // Member runs stay silent: the group run aggregates the outcome and emits
        // the single success/fail notification for the whole set.
        if (! $run->belongsToGroupRun()) {
            $this->sendNotifications($run->fresh(['job.destination']));
        }

        return true;
    }

    private function recordBackupArchiveMetadata(BackupRun $run): void
    {
        $expectedFilename = $this->runBackupContainer->backupFilename($run);

        try {
            $object = collect($this->listBackupObjects->handle($run->job->destination))
                ->first(fn (array $object): bool => $this->matchesExpectedBackupObject($object, $expectedFilename));
        } catch (Throwable) {
            $this->appendRunLog->handle($run, 'Backup archive size could not be detected.');

            return;
        }

        if (! $object) {
            $this->appendRunLog->handle($run, 'Backup archive size could not be detected.');

            return;
        }

        $run->forceFill([
            'backup_key' => (string) ($object['key'] ?? $object['display_name'] ?? $expectedFilename),
            'backup_size_bytes' => array_key_exists('size', $object) ? (int) $object['size'] : null,
        ])->save();
    }

    private function matchesExpectedBackupObject(array $object, string $expectedFilename): bool
    {
        foreach (['key', 'display_name'] as $field) {
            $value = (string) ($object[$field] ?? '');

            if ($value === $expectedFilename || str_ends_with($value, '/'.$expectedFilename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the parent group run's heartbeat fresh from inside a member run. The
     * group worker only bumps the heartbeat between members, so a member's own
     * post-backup work (metadata listing) would otherwise let the heartbeat lag
     * and a live group run be reconciled as stale. No-op for standalone runs.
     */
    private function touchGroupRunHeartbeat(BackupRun $run): void
    {
        if ($run->backup_group_run_id === null) {
            return;
        }

        BackupGroupRun::query()
            ->whereKey($run->backup_group_run_id)
            ->update(['last_heartbeat_at' => now()]);
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

    private function sendStartNotification(BackupRun $run): void
    {
        try {
            $this->sendShoutrrrNotification->sendBackupRunStarted($run);
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup start notification failed.', $run, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }
}
