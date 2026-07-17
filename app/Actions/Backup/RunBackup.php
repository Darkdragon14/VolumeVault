<?php

namespace App\Actions\Backup;

use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\ListDockerContainers;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Docker\StopDockerContainers;
use App\Jobs\RecordArchiveMetadataJob;
use App\Models\ActivityLog;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\Host;
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

        // Atomically claim the run: flip a QUEUED row → RUNNING in one conditional
        // UPDATE. Claiming only a QUEUED row (not any non-terminal one) means a
        // redelivered copy — the queue can deliver a job twice under retryUntil —
        // finds the row already RUNNING and bails instead of re-executing the same
        // backup. A row reconciliation already marked terminal also matches zero
        // rows. Mirrors RunBackupGroup.
        $claimed = BackupRun::query()
            ->whereKey($run->getKey())
            ->where('status', BackupRun::STATUS_QUEUED)
            ->update([
                'status' => BackupRun::STATUS_RUNNING,
                'started_at' => $startedAt,
                'last_heartbeat_at' => $startedAt,
            ]);

        if ($claimed === 0) {
            return;
        }

        $run->refresh();
        $run->loadMissing('job.destination', 'job.host');

        $job = $run->job;
        $host = $job?->host;
        $stoppedContainers = [];

        // A pre-restore safety backup borrows the full backup pipeline but must
        // stay invisible to the job's lifecycle: touching the job here would, for
        // a manually paused job, flip it to running/active and clear pause_reason,
        // silently unpausing it. Only real scheduled/manual runs drive job state.
        if (! $this->isPreRestore($run)) {
            // Flip the job to RUNNING atomically, only from a non-paused state. An
            // admin can pause the job while this run sits queued (job pause uses a
            // matching `where status != running` guard), so the two conditional
            // updates serialize: if the pause won (0 rows here), honour it — cancel
            // this run without unpausing the job.
            $flipped = BackupJob::query()
                ->whereKey($job->id)
                ->where('status', '!=', BackupJob::STATUS_PAUSED)
                ->update([
                    'status' => BackupJob::STATUS_RUNNING,
                    'last_run_at' => $startedAt,
                ]);

            if ($flipped === 0) {
                $run->forceFill([
                    'status' => BackupRun::STATUS_CANCELLED,
                    'finished_at' => now(),
                    'error_message' => 'Job was paused before this run started.',
                ])->save();

                ActivityLog::record('backup_run_cancelled', 'Backup run cancelled: the job was paused before it started.', $run, [
                    'backup_job_id' => $job->id,
                ]);

                return;
            }

            $job->refresh();
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
            if ($job->host_id !== $run->host_id) {
                throw new RuntimeException('The backup job host changed after this run was queued.');
            }

            if (! $host || $host->type !== Host::TYPE_LOCAL) {
                throw new RuntimeException('Only local backup jobs can run through the local Docker worker.');
            }

            if (! $job->destination?->is_active) {
                throw new RuntimeException('The backup destination is inactive.');
            }

            if ($job->isDockerVolumeSource()) {
                $this->inspectDockerVolume->handle($job->volume_name);
                DockerVolume::updateOrCreate(
                    ['host_id' => $host->id, 'name' => $job->volume_name],
                    ['exists' => true, 'last_seen_at' => now()],
                );
            } else {
                $this->hostPathPolicy->assertValid((string) $job->host_path);
            }

            if ($job->stop_containers_before_backup) {
                // Docker volumes auto-discover the containers mounting them; host
                // path jobs can't be mapped back to containers, so the user picks
                // them by name and we resolve those to running containers here.
                $containers = $job->isDockerVolumeSource()
                    ? $this->findContainersUsingVolume->handle($job->volume_name)
                    : $this->selectContainersByName($run, $job->stop_container_names ?? [], $this->siblingStoppedContainerIds($run));

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
                    // No Docker backup container exists yet, so reconciliation falls
                    // back to the heartbeat to judge liveness. docker stop is 120s
                    // each and sequential, so refresh per container to keep a slow
                    // multi-container stop from being reconciled as a dead worker.
                    $this->heartbeat($run);
                    $this->stopDockerContainers->handle($stoppedContainers, fn () => $this->heartbeat($run));
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

            // Record archive metadata and, for a standalone run, send the finished
            // notification off the critical path via a job that holds no volume
            // lock. The destination listing can be slow (WebDAV Depth: infinity,
            // recursive SFTP, slow NFS) and this backup's queue job keeps its overlap
            // lock until it returns — doing the listing inline would block a
            // legitimately-waiting same-volume run past reconciliation's grace and
            // could get its lock force-released. A group member's metadata is
            // deferred the same way; its group emits the single aggregated
            // notification, so the member stays silent. Whether to notify is decided
            // here (a standalone run does; a member does not) and passed to the job,
            // not re-derived from backup_group_run_id when it runs — deleting a
            // finished group nulls that column, which would otherwise make a member's
            // pending metadata job send an unexpected standalone notification.
            RecordArchiveMetadataJob::dispatch($run->id, ! $run->belongsToGroupRun());
        } catch (Throwable $exception) {
            $this->markFailed($run, $exception);
        } finally {
            if ($stoppedContainers) {
                try {
                    // Restarting containers happens after the run is already
                    // terminal, but this job still holds its overlap lock and a
                    // member run must keep its group run alive. docker start is 120s
                    // each and sequential, so refresh the heartbeat up front and
                    // after each container to keep a live run/group from being
                    // falsely closed during a slow multi-container restart.
                    $this->heartbeat($run);
                    $this->startDockerContainers->handle($stoppedContainers, fn () => $this->heartbeat($run));
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
    /**
     * Restart the containers a run left stopped. $exclude lists containers that an
     * active sibling group member still needs stopped for its own backup; those are
     * left stopped (kept on the run for a later sweep) so we never bring the
     * application up in the middle of the sibling's archive. Returns whether any
     * container was actually restarted.
     *
     * @param  array<int, string>  $exclude
     */
    public function restartStoppedContainers(BackupRun $run, array $exclude = []): bool
    {
        $containerIds = $run->stopped_container_ids ?? [];

        if (! $containerIds) {
            return false;
        }

        $toRestart = array_values(array_diff($containerIds, $exclude));

        if (! $toRestart) {
            // Every leftover container is still needed stopped by an active
            // sibling; leave them for a later sweep once the sibling finishes.
            return false;
        }

        $this->startDockerContainers->handle($toRestart);

        // Keep any containers still held by an active sibling; clear the rest.
        $remaining = array_values(array_intersect($containerIds, $exclude));
        $run->forceFill(['stopped_container_ids' => $remaining ?: null])->save();

        $message = 'Restarted containers left stopped after an interrupted run: '.implode(', ', $toRestart);
        $this->appendRunLog->handle($run, $message);

        ActivityLog::record('backup_run_containers_reconciled', $message, $run, [
            'backup_job_id' => $run->backup_job_id,
        ]);

        return true;
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
     *
     * Exception for a group member: a selected container that is already stopped
     * *because a sibling member left it stopped* (its id is in $adoptStoppedIds) is
     * adopted — recorded so this run restarts it, and so reconciliation sees it as
     * needed-stopped while this run's archive is in flight. Without this a shared
     * container a sibling failed to restart would be silently restarted by
     * reconciliation mid-archive. A container stopped by the user (not a sibling)
     * is still left untouched.
     *
     * @param  array<int, string>  $adoptStoppedIds
     */
    private function selectContainersByName(BackupRun $run, array $names, array $adoptStoppedIds = []): array
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
                if (filled($container['id'] ?? null) && in_array($container['id'], $adoptStoppedIds, true)) {
                    // Left stopped by a sibling member of this group run: adopt it so
                    // this run keeps it stopped for its archive and restarts it after.
                    $this->appendRunLog->handle($run, "Selected container \"{$hit}\" was left stopped by another group member; keeping it stopped for this backup.");
                    $matched[] = $container;

                    continue;
                }

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

            // Flip to error only if the job is not currently paused, atomically —
            // an admin can pause between reconciliation's stale selection and here,
            // and $job is a possibly-stale snapshot. A conditional UPDATE loses that
            // race instead of resurrecting a paused (member) job that grouped
            // runnableMembers() (which excludes only paused) would then pick up again.
            BackupJob::query()
                ->whereKey($job->id)
                ->where('status', '!=', BackupJob::STATUS_PAUSED)
                ->update(['status' => BackupJob::STATUS_ERROR]);
        }

        ActivityLog::record('backup_run_failed', 'Backup run failed.', $run, [
            'backup_job_id' => $job?->id,
        ]);

        // Member runs stay silent: the group run aggregates the outcome and emits
        // the single success/fail notification for the whole set. A standalone run
        // notifies inline while still holding the overlap lock, so refresh the
        // heartbeat up front and after each channel (each ~60s) to keep a waiting
        // same-volume run from being reconciled as stale.
        if (! $run->belongsToGroupRun()) {
            $this->heartbeat($run);
            $this->sendNotifications($run->fresh(['job.destination']), fn () => $this->heartbeat($run));
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
     * Record a completed run's archive metadata (backup key + size) by id. Runs
     * the potentially-slow destination listing; dispatched as a job for group
     * members so it never blocks the group run's critical path.
     */
    public function recordArchiveMetadata(int $backupRunId, bool $sendFinishedNotification = false): void
    {
        $run = BackupRun::with('job.destination')->find($backupRunId);

        if ($run === null) {
            return;
        }

        $this->recordBackupArchiveMetadata($run);

        // Whether to send the finished notification was decided at dispatch (true for
        // a standalone run, false for a group member) and passed in — not re-derived
        // from belongsToGroupRun() here, since a group deleted between dispatch and
        // now nulls backup_group_run_id and would turn a member into a false
        // standalone notification. Deferred so the queue job that held the volume
        // lock returns right after marking the run terminal instead of blocking a
        // waiting same-volume run through the slow listing above.
        if ($sendFinishedNotification) {
            $this->sendNotifications($run->fresh(['job.destination']));
        }
    }

    /**
     * Refresh the parent group run's heartbeat from inside a member run. Used
     * during the post-backup container restart (which the group worker cannot
     * heartbeat around otherwise) so a live group run is not reconciled as stale.
     * No-op for a standalone run.
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

    /**
     * Refresh the run's liveness marker (and, for a member run, its group run's).
     * Used whenever the worker is doing bounded work with no Docker backup
     * container to prove liveness — the pre-backup container stop, the post-backup
     * restart, and the post-success metadata/notification finalization — so
     * stale-run reconciliation does not mistake a slow-but-healthy run for a crash.
     */
    private function heartbeat(BackupRun $run): void
    {
        BackupRun::query()
            ->whereKey($run->getKey())
            ->update(['last_heartbeat_at' => now()]);

        $this->touchGroupRunHeartbeat($run);
    }

    /**
     * Container ids that sibling members of this run's group run currently have
     * stopped. A host-path member uses these to adopt a container a sibling left
     * stopped (see {@see selectContainersByName()}). Empty for a standalone run.
     *
     * @return array<int, string>
     */
    private function siblingStoppedContainerIds(BackupRun $run): array
    {
        if ($run->backup_group_run_id === null) {
            return [];
        }

        return BackupRun::query()
            ->where('backup_group_run_id', $run->backup_group_run_id)
            ->whereKeyNot($run->id)
            ->whereNotNull('stopped_container_ids')
            ->pluck('stopped_container_ids')
            ->flatMap(fn ($ids) => is_array($ids) ? $ids : [])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function sendNotifications(BackupRun $run, ?callable $afterEach = null): void
    {
        try {
            $this->sendShoutrrrNotification->sendBackupRunFinished($run, $afterEach);
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup notification failed.', $run, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }

    private function sendStartNotification(BackupRun $run): void
    {
        try {
            // No Docker container exists yet at start, so the heartbeat is the only
            // liveness signal; refresh it per channel so slow start notifications
            // don't let reconciliation fail this live run and release its lock.
            $this->sendShoutrrrNotification->sendBackupRunStarted($run, fn () => $this->heartbeat($run));
        } catch (Throwable $exception) {
            ActivityLog::record('notification_send_failed', 'Backup start notification failed.', $run, [
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ]);
        }
    }
}
