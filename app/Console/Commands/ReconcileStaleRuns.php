<?php

namespace App\Console\Commands;

use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Docker\CleanupBackupRunSecretFiles;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Docker\StopDockerContainer;
use App\Actions\Restore\RunRestore;
use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Agents\HostWorkAdmission;
use App\Support\DeploymentMode;
use App\Support\RunHeartbeatLock;
use App\Support\VolumeJobLock;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ReconcileStaleRuns extends Command
{
    protected $signature = 'volumevault:reconcile-stale-runs
        {--minutes= : Age threshold in minutes before a queued/running run with no recent heartbeat is considered stale}';

    protected $description = 'Mark backup/restore runs stuck in queued/running as failed after a worker crash, timeout or restart, and restart application containers left stopped by an interrupted backup.';

    /**
     * Age threshold for runs whose worker stops renewing its progress lease.
     * Restore containers keep their existing direct liveness check.
     */
    public const DEFAULT_THRESHOLD_MINUTES = 15;

    // Orphaned WithoutOverlapping locks are not force-released here because a
    // successor may already own the same key. Their configured TTL bounds recovery
    // to 24 hours without risking release of a live worker's lock.

    public function __construct(
        private readonly ContainerIsAlive $containerIsAlive,
        private readonly StopDockerContainer $stopDockerContainer,
        private readonly RemoveDockerContainer $removeDockerContainer,
        private readonly CleanupBackupRunSecretFiles $cleanupBackupRunSecretFiles,
    ) {
        parent::__construct();
    }

    public function handle(RunBackup $runBackup, RunRestore $runRestore, RunBackupGroup $runBackupGroup): int
    {
        if (DeploymentMode::isOrchestrator()) {
            return self::SUCCESS;
        }

        $minutes = (int) ($this->option('minutes') ?: self::DEFAULT_THRESHOLD_MINUTES);

        if ($minutes < 1) {
            $minutes = self::DEFAULT_THRESHOLD_MINUTES;
        }

        $cutoff = now()->subMinutes($minutes);
        $reason = "Run reconciled as failed: stuck in queued/running for more than {$minutes} minute(s) (possible worker crash, timeout or restart).";
        $lostPayloadReason = "Run reconciled as failed: both queue publication attempts remained unclaimed for more than {$minutes} minute(s).";

        $backupCount = 0;
        $reconciledBackupRunIds = [];
        $this->exhaustedBackupPublications($cutoff)->each(function (BackupRun $run) use ($runBackup, $lostPayloadReason, $cutoff, &$backupCount, &$reconciledBackupRunIds): void {
            $lock = $this->backupOverlapLock($run);

            if ($lock->get()) {
                try {
                    if ($runBackup->markFailed($run, new RuntimeException($lostPayloadReason), BackupRun::STATUS_QUEUED, $cutoff, expectedDispatchToken: $run->dispatch_token)) {
                        $reconciledBackupRunIds[] = $run->id;
                        $runBackup->applyPendingLabelReconciliationIfReady($run);
                        $backupCount++;
                    }
                } finally {
                    $lock->release();
                }
            }
        });

        $this->staleBackupRuns($cutoff)->each(function (BackupRun $run) use ($runBackup, $reason, $cutoff, &$backupCount, &$reconciledBackupRunIds): void {
            if ($this->markStaleBackupFailed($run, $cutoff, $runBackup, $reason)) {
                $reconciledBackupRunIds[] = $run->id;
                $runBackup->applyPendingLabelReconciliationIfReady($run);
                $backupCount++;
            }
        });

        $restoreCount = 0;
        $this->exhaustedRestorePublications($cutoff)->each(function (RestoreRun $run) use ($runRestore, $lostPayloadReason, $cutoff, &$restoreCount): void {
            $lock = $this->restoreOverlapLock($run);

            if ($lock->get()) {
                try {
                    if ($runRestore->markFailed($run, new RuntimeException($lostPayloadReason), RestoreRun::STATUS_QUEUED, $cutoff, expectedDispatchToken: $run->dispatch_token)) {
                        $runRestore->applyPendingLabelReconciliationIfReady($run);
                        $restoreCount++;
                    }
                } finally {
                    $lock->release();
                }
            }
        });

        $this->staleRestoreRuns($cutoff)->each(function (RestoreRun $run) use ($runRestore, $reason, $cutoff, &$restoreCount): void {
            if ($this->markStaleRestoreFailed($run, $cutoff, $runRestore, $reason)) {
                $runRestore->applyPendingLabelReconciliationIfReady($run);
                $restoreCount++;
            }
        });

        // Close backup group runs whose worker crashed once no member run is still
        // active. Members reconciled above do not delay their parent until a later
        // sweep. markFailed emits the single aggregated failure notification.
        $groupCount = 0;
        $this->exhaustedGroupPublications($cutoff)->each(function (BackupGroupRun $run) use ($runBackupGroup, $lostPayloadReason, $cutoff, &$groupCount): void {
            $lock = Cache::lock(VolumeJobLock::cacheKeyFor('backup-group-'.$run->backup_job_group_id), 180);

            if ($lock->get()) {
                try {
                    if ($runBackupGroup->markFailed($run, new RuntimeException($lostPayloadReason), BackupGroupRun::STATUS_QUEUED, $cutoff, expectedDispatchToken: $run->dispatch_token)) {
                        $groupCount++;
                    }
                } finally {
                    $lock->release();
                }
            }
        });

        $this->staleGroupRuns($cutoff, $reconciledBackupRunIds)->each(function (BackupGroupRun $run) use ($runBackupGroup, $reason, $cutoff, &$groupCount): void {
            if ($runBackupGroup->markFailed($run, new RuntimeException($reason), $run->status, $cutoff)) {
                $groupCount++;
            }
        });

        $containerCleanupCount = 0;
        $this->backupRunsPendingContainerCleanup()->each(function (BackupRun $run) use ($runBackup, &$containerCleanupCount): void {
            if ($this->cleanupBackupContainer($run)) {
                $containerCleanupCount++;
                $runBackup->applyPendingLabelReconciliationIfReady($run);
            }
        });

        // Runs the sweep just failed (or runs whose worker died during restart)
        // may still have application containers stopped. Restart them now.
        $restartedCount = 0;
        $this->backupRunsWithStoppedContainers($cutoff)->each(function (BackupRun $run) use ($runBackup, &$restartedCount): void {
            try {
                if ($this->recoverBackupStoppedContainers($run, $runBackup)) {
                    $restartedCount++;
                }
            } catch (Throwable $exception) {
                $this->warn("Failed to restart containers for backup run {$run->id}: {$exception->getMessage()}");
            }
        });

        $this->restoreRunsWithStoppedContainers()->each(function (RestoreRun $run) use ($runRestore, &$restartedCount): void {
            try {
                if ($this->recoverRestoreStoppedContainers($run, $runRestore)) {
                    $restartedCount++;
                }
            } catch (Throwable $exception) {
                $this->warn("Failed to restart containers for restore run {$run->id}: {$exception->getMessage()}");
            }
        });

        $this->info("Reconciled {$backupCount} stale backup run(s), {$restoreCount} stale restore run(s) and {$groupCount} stale backup group run(s); completed {$containerCleanupCount} pending backup cleanup(s); restarted containers for {$restartedCount} interrupted run(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, BackupRun> */
    private function exhaustedBackupPublications(CarbonInterface $cutoff): Collection
    {
        return BackupRun::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where(fn ($query) => $query->whereNull('backup_group_run_id')
                ->orWhereHas('groupRun', fn ($query) => $query->whereNotNull('member_run_ids')
                    ->where('status', 'running')->whereColumn('current_member_run_id', 'backup_runs.id')))
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
            ->where('status', BackupRun::STATUS_QUEUED)
            ->whereNotNull('dispatch_token')
            ->whereNotNull('dispatch_published_at')
            ->whereColumn('dispatch_attempted_at', '>', 'dispatch_published_at')
            ->where('dispatch_attempted_at', '<', $cutoff)
            ->with('job')
            ->get()
            ->reject(fn (BackupRun $run): bool => app(HostWorkAdmission::class)->isWaiting($run) || $this->backupIsWaitingForVolumeLock($run));
    }

    /** @return Collection<int, RestoreRun> */
    private function exhaustedRestorePublications(CarbonInterface $cutoff): Collection
    {
        return RestoreRun::query()
            ->where('target_docker_host_id', DockerHost::LOCAL_ID)
            ->where('status', RestoreRun::STATUS_QUEUED)
            ->whereNotNull('dispatch_token')
            ->whereNotNull('dispatch_published_at')
            ->whereColumn('dispatch_attempted_at', '>', 'dispatch_published_at')
            ->where('dispatch_attempted_at', '<', $cutoff)
            ->get()
            ->reject(fn (RestoreRun $run): bool => app(HostWorkAdmission::class)->isWaiting($run) || $this->volumeHeldByAnotherActiveRun($run->target_volume_name, $run->target_docker_host_id, restoreId: $run->id));
    }

    /** @return Collection<int, BackupGroupRun> */
    private function exhaustedGroupPublications(CarbonInterface $cutoff): Collection
    {
        return BackupGroupRun::query()
            ->whereNull('member_run_ids')
            ->whereDoesntHave('group.members', fn ($query) => $query->where('docker_host_id', '!=', DockerHost::LOCAL_ID))
            ->whereDoesntHave('memberRuns', fn ($query) => $query->where('docker_host_id', '!=', DockerHost::LOCAL_ID))
            ->where('status', BackupGroupRun::STATUS_QUEUED)
            ->whereNotNull('dispatch_token')
            ->whereNotNull('dispatch_published_at')
            ->whereColumn('dispatch_attempted_at', '>', 'dispatch_published_at')
            ->where('dispatch_attempted_at', '<', $cutoff)
            ->get()
            ->reject(fn (BackupGroupRun $run): bool => app(HostWorkAdmission::class)->isWaiting($run) || $this->queuedGroupRunHasActivePredecessor($run, $cutoff, []));
    }

    private function backupOverlapLock(BackupRun $run): Lock
    {
        $key = VolumeJobLock::key($run->sourceVolumeName(), 'backup-job-'.$run->backup_job_id, $run->docker_host_id);

        return Cache::lock(VolumeJobLock::cacheKeyFor($key), 180);
    }

    private function restoreOverlapLock(RestoreRun $run): Lock
    {
        $key = VolumeJobLock::key($run->target_volume_name, 'restore-run-'.$run->id, $run->target_docker_host_id);

        return Cache::lock(VolumeJobLock::cacheKeyFor($key), 180);
    }

    /**
     * @param  array<int, int>  $reconciledBackupRunIds
     * @return Collection<int, BackupGroupRun>
     */
    private function staleGroupRuns(CarbonInterface $cutoff, array $reconciledBackupRunIds): Collection
    {
        return BackupGroupRun::query()
            ->whereNull('member_run_ids')
            ->whereDoesntHave('group.members', fn ($query) => $query->where('docker_host_id', '!=', DockerHost::LOCAL_ID))
            ->whereDoesntHave('memberRuns', fn ($query) => $query->where('docker_host_id', '!=', DockerHost::LOCAL_ID))
            ->whereIn('status', [BackupGroupRun::STATUS_QUEUED, BackupGroupRun::STATUS_RUNNING])
            ->get()
            ->filter(fn (BackupGroupRun $run) => $this->groupRunIsStale($run, $cutoff)
                && ! $this->groupRunHasActiveMemberRun($run, $cutoff, $reconciledBackupRunIds)
                && ($run->status !== BackupGroupRun::STATUS_QUEUED
                    || ! $this->queuedGroupRunHasActivePredecessor($run, $cutoff, $reconciledBackupRunIds)));
    }

    /**
     * A group run drives its members sequentially and updates last_heartbeat_at
     * after each one, so a running group with a stale heartbeat and no in-flight
     * member is a crashed worker. Queued groups belong to dispatch recovery,
     * regardless of age or maintenance history. Only exhaustedGroupPublications
     * may fail them after repeated unclaimed publications.
     */
    private function groupRunIsStale(BackupGroupRun $run, CarbonInterface $cutoff): bool
    {
        if ($run->status === BackupGroupRun::STATUS_QUEUED) {
            return false;
        }

        $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

        return $progressedAt !== null && $progressedAt->lessThan($cutoff);
    }

    /** @param array<int, int> $reconciledBackupRunIds */
    private function queuedGroupRunHasActivePredecessor(BackupGroupRun $run, CarbonInterface $cutoff, array $reconciledBackupRunIds): bool
    {
        return BackupGroupRun::query()
            ->where('backup_job_group_id', $run->backup_job_group_id)
            ->whereKeyNot($run->id)
            ->where('status', BackupGroupRun::STATUS_RUNNING)
            ->get()
            ->contains(fn (BackupGroupRun $predecessor): bool => ! $this->groupRunIsStale($predecessor, $cutoff)
                || $this->groupRunHasActiveMemberRun($predecessor, $cutoff, $reconciledBackupRunIds));
    }

    /**
     * Whether a member run of this group is still queued or running. A live member
     * is reconciled on its own container liveness first; the group is only closed
     * once every member has reached a terminal state, so its aggregated outcome is
     * not declared while a member is still working.
     *
     * @param  array<int, int>  $reconciledBackupRunIds
     */
    private function groupRunHasActiveMemberRun(BackupGroupRun $run, CarbonInterface $cutoff, array $reconciledBackupRunIds): bool
    {
        return BackupRun::query()
            ->where('backup_group_run_id', $run->id)
            ->where(function ($query) use ($cutoff, $reconciledBackupRunIds): void {
                $query
                    ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
                    // A member that finished after the cutoff means the group
                    // worker was active recently: it runs members synchronously and
                    // only refreshes the group heartbeat between them, so it may be
                    // finalizing that member (e.g. recording archive metadata) with
                    // a lagging heartbeat. Treat the group as still progressing so a
                    // long member does not get its live group run reconciled.
                    ->orWhere(fn ($q) => $q
                        ->whereNotIn('id', $reconciledBackupRunIds)
                        ->whereNotNull('finished_at')
                        ->where('finished_at', '>=', $cutoff));
            })
            ->exists();
    }

    /** @return Collection<int, BackupRun> */
    private function staleBackupRuns(CarbonInterface $cutoff): Collection
    {
        return BackupRun::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
            ->where(fn ($query) => $this->candidateConstraint($query, $cutoff, BackupRun::STATUS_RUNNING))
            ->with('job')
            ->get()
            ->filter(fn (BackupRun $run) => $this->backupIsStale($run, $cutoff)
                && ! $this->backupIsWaitingForVolumeLock($run));
    }

    /** @return Collection<int, BackupRun> */
    private function backupRunsPendingContainerCleanup(): Collection
    {
        return BackupRun::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where('docker_container_cleanup_pending', true)
            ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
            ->get();
    }

    /**
     * Stop an Offen helper whose owning worker stopped refreshing its heartbeat.
     * Application containers must stay stopped until the helper is confirmed gone.
     */
    private function markStaleBackupFailed(BackupRun $run, CarbonInterface $cutoff, RunBackup $runBackup, string $reason): bool
    {
        if ($run->status !== BackupRun::STATUS_RUNNING) {
            return $runBackup->markFailed($run, new RuntimeException($reason), BackupRun::STATUS_QUEUED, $cutoff);
        }

        $heartbeatLock = Cache::lock(RunHeartbeatLock::backup($run->id), 180);

        if (! $heartbeatLock->get()) {
            return false;
        }

        $released = false;

        try {
            $run->refresh();

            if ($run->status !== BackupRun::STATUS_RUNNING) {
                return false;
            }

            $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

            if ($progressedAt === null || ! $progressedAt->lessThan($cutoff)) {
                return false;
            }

            if (filled($run->docker_container_id)) {
                $alive = $this->containerIsAlive->handle($run->docker_container_id);

                if ($alive === null) {
                    $this->warn("Could not confirm whether backup container {$run->docker_container_id} is still running; recovery for backup run {$run->id} will be retried.");

                    return false;
                }

                if ($alive === true) {
                    try {
                        $this->stopDockerContainer->handle($run->docker_container_id);
                    } catch (Throwable $exception) {
                        $this->warn("Failed to stop orphaned backup container {$run->docker_container_id} for backup run {$run->id}: {$exception->getMessage()}");

                        return false;
                    }
                }

                if (! $this->cleanupBackupContainer($run)) {
                    return false;
                }
            } elseif ($run->docker_container_cleanup_pending && ! $this->cleanupBackupContainer($run)) {
                return false;
            }

            return $runBackup->markFailed(
                $run,
                new RuntimeException($reason),
                BackupRun::STATUS_RUNNING,
                $cutoff,
                function () use ($heartbeatLock, &$released): void {
                    $heartbeatLock->release();
                    $released = true;
                },
            );
        } finally {
            if (! $released) {
                $heartbeatLock->release();
            }
        }
    }

    /**
     * The heartbeat is the worker's lease. Docker liveness is checked under the
     * same lock only after that lease expires.
     */
    private function backupIsStale(BackupRun $run, CarbonInterface $cutoff): bool
    {
        if ($run->status !== BackupRun::STATUS_RUNNING) {
            if ($run->belongsToGroupRun() && $run->groupRun?->member_run_ids !== null) {
                return false;
            }
            if (! $run->belongsToGroupRun() && $run->trigger !== BackupRun::TRIGGER_PRE_RESTORE) {
                return false;
            }

            return $run->created_at !== null && $run->created_at->lessThan($cutoff);
        }

        $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

        return $progressedAt !== null && $progressedAt->lessThan($cutoff);
    }

    private function cleanupBackupContainer(BackupRun $run): bool
    {
        $successful = true;

        if (filled($run->docker_container_id)) {
            try {
                $this->removeDockerContainer->handle($run->docker_container_id);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Failed to remove pending backup container {$run->docker_container_id} for backup run {$run->id}: {$exception->getMessage()}");
                $successful = false;
            }
        }

        try {
            $this->cleanupBackupRunSecretFiles->handle($run);
        } catch (Throwable $exception) {
            report($exception);
            $this->warn("Failed to remove temporary secret files for backup run {$run->id}: {$exception->getMessage()}");
            $successful = false;
        }

        if ($successful && $run->docker_container_cleanup_pending) {
            try {
                $run->forceFill(['docker_container_cleanup_pending' => false])->save();
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Failed to record completed container cleanup for backup run {$run->id}: {$exception->getMessage()}");
                $successful = false;
            }
        }

        return $successful;
    }

    /** @return Collection<int, RestoreRun> */
    private function staleRestoreRuns(CarbonInterface $cutoff): Collection
    {
        return RestoreRun::query()
            ->where('target_docker_host_id', DockerHost::LOCAL_ID)
            ->where('status', RestoreRun::STATUS_RUNNING)
            ->get()
            ->filter(fn (RestoreRun $run) => $this->isStale($run, $cutoff, RestoreRun::STATUS_RUNNING)
                && ! $this->restoreIsProgressing($run, $cutoff)
                && ! $this->volumeHeldByAnotherActiveRun($run->target_volume_name, $run->target_docker_host_id, restoreId: $run->id));
    }

    /**
     * Revalidate a restore's lease under the same lock used by worker heartbeats.
     * The candidate collection is only a snapshot; a worker may have progressed
     * after it was loaded and must win over a stale reconciliation decision.
     */
    private function markStaleRestoreFailed(RestoreRun $run, CarbonInterface $cutoff, RunRestore $runRestore, string $reason): bool
    {
        $heartbeatLock = Cache::lock(RunHeartbeatLock::restore($run->id), 180);

        if (! $heartbeatLock->get()) {
            return false;
        }

        $released = false;

        try {
            $run->refresh();

            if ($run->status !== RestoreRun::STATUS_RUNNING || $this->restoreIsProgressing($run, $cutoff)) {
                return false;
            }

            $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

            if ($progressedAt === null || ! $progressedAt->lessThan($cutoff)) {
                return false;
            }

            if (filled($run->docker_container_id)) {
                $alive = $this->containerIsAlive->handle($run->docker_container_id);

                if ($alive === null) {
                    $this->warn("Could not confirm whether restore container {$run->docker_container_id} is still running; recovery for restore run {$run->id} will be retried.");

                    return false;
                }

                if ($alive) {
                    return false;
                }
            }

            return $runRestore->markFailed(
                $run,
                new RuntimeException($reason),
                RestoreRun::STATUS_RUNNING,
                $cutoff,
                function () use ($heartbeatLock, &$released): void {
                    $heartbeatLock->release();
                    $released = true;
                },
            );
        } finally {
            if (! $released) {
                $heartbeatLock->release();
            }
        }
    }

    /**
     * Whether a stale backup run should be left alone because it is a queued job
     * waiting on a volume lock held by another active run.
     *
     * A pre-restore safety backup is exempt: it runs inline inside its restore's
     * worker (never as a separately-queued job), so it is never a lock-waiter.
     * Exempting it also breaks a mutual-skip deadlock — its parent restore stays
     * "running" and would otherwise shield the safety backup from reconciliation,
     * while the restore sweep in turn shields the restore because the safety
     * backup is still "running". Reconciling the safety backup on its own liveness
     * frees both: once it is failed, the restore is no longer seen as progressing.
     */
    private function backupIsWaitingForVolumeLock(BackupRun $run): bool
    {
        if ($run->trigger === BackupRun::TRIGGER_PRE_RESTORE) {
            return false;
        }

        // Legacy group members execute inline and cannot be queue lock waiters.
        // Durable coordinated members have their own WithoutOverlapping queue jobs
        // and need the same active/recently-released holder protection as standalone runs.
        if ($run->belongsToGroupRun() && $run->groupRun?->member_run_ids === null) {
            return false;
        }

        // A host-path job has no volume: it serializes on its per-job lock, so a
        // queued run of the same job legitimately waiting on that lock must be
        // exempt just like a volume waiter — otherwise WithoutOverlapping requeuing
        // it (releaseAfter) would look stale and get failed out from under itself.
        return $this->lockHeldByAnotherActiveBackup($run, $run->sourceVolumeName());
    }

    /**
     * Whether the lock this backup run serializes on is held — or was only just
     * released — by another active run: the volume lock for a Docker-volume job,
     * the per-job lock for a host-path job (which restores never share).
     */
    private function lockHeldByAnotherActiveBackup(BackupRun $run, ?string $volume): bool
    {
        if (filled($volume)) {
            return $this->volumeHeldByAnotherActiveRun($volume, $run->docker_host_id, backupRunId: $run->id);
        }

        $recentlyReleased = now()->subSeconds(120);

        return BackupRun::query()
            ->where('docker_host_id', $run->docker_host_id)
            ->where('backup_job_id', $run->backup_job_id)
            ->whereKeyNot($run->id)
            // A pre-restore safety backup runs inline and never holds this lock.
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
            ->where(fn ($query) => $this->stillHoldsVolume($query, $recentlyReleased, includeBackupCleanup: true))
            ->exists();
    }

    /**
     * Whether another backup or restore on $volume holds — or only just released —
     * the volume lock. A queued run released by WithoutOverlapping while it waits
     * for that lock is legitimately pending, not stale; failing it would lose the
     * operation and (with the terminal-state guard) leave a confusingly failed run
     * with a queued job still in flight.
     *
     * "Just released" matters because a lock loser is requeued with releaseAfter(60):
     * for up to that delay the holder has finished (no RUNNING run) yet the waiter
     * keeps its old created_at and has not resumed. Treating a holder that reached a
     * terminal state within the release window + a buffer as still "busy" bridges
     * that gap, so the waiter survives long enough to pick the lock back up.
     */
    private function volumeHeldByAnotherActiveRun(?string $volume, int $dockerHostId, ?int $restoreId = null, ?int $backupRunId = null): bool
    {
        if (! filled($volume)) {
            return false;
        }

        // releaseAfter(60) on both jobs + a buffer for the worker to redeliver.
        $recentlyReleased = now()->subSeconds(120);

        $restoreHolds = RestoreRun::query()
            ->where('target_docker_host_id', $dockerHostId)
            ->where('target_volume_name', $volume)
            ->when($restoreId, fn ($query) => $query->whereKeyNot($restoreId))
            ->where(fn ($query) => $this->stillHoldsVolume($query, $recentlyReleased))
            ->exists();

        $backupHolds = BackupRun::query()
            ->where('docker_host_id', $dockerHostId)
            ->where(function ($query) use ($volume): void {
                $query->where('source_volume_name', $volume)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('source_type_snapshot')
                        ->whereHas('job', fn ($query) => $query->where('volume_name', $volume)));
            })
            ->when($backupRunId, fn ($query) => $query->whereKeyNot($backupRunId))
            // A pre-restore safety backup runs inline inside its restore's worker
            // and never holds the volume lock on its own, so it must not count as a
            // holder — otherwise a just-failed safety backup would shield its parent
            // restore from reconciliation, re-creating the mutual-skip deadlock.
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
            ->where(fn ($query) => $this->stillHoldsVolume($query, $recentlyReleased, includeBackupCleanup: true))
            ->exists();

        return $restoreHolds || $backupHolds;
    }

    /**
     * Constrain to runs that still hold the volume: running, terminal but only
     * just released (within the requeue window), terminal but still finalizing with
     * a fresh heartbeat, or terminal but still owning stopped containers their
     * finally has not restarted yet, or backup runs with pending helper cleanup.
     *
     * The heartbeat case matters because the WithoutOverlapping lock is held for the
     * whole job — the run flips terminal before archive-metadata listing and
     * notifications, which can outlast the fixed requeue window. The holder refreshes
     * its heartbeat across that finalization, so it keeps counting as a holder and a
     * legitimately-waiting run is not swept. The stopped-containers case keeps this
     * consistent with the jobs' volumeBusy guard.
     *
     * STATUS_* values are identical across BackupRun and RestoreRun, so the same
     * constants apply to either query.
     */
    private function stillHoldsVolume($query, CarbonInterface $recentlyReleased, bool $includeBackupCleanup = false): void
    {
        $query
            ->where('status', BackupRun::STATUS_RUNNING)
            ->orWhere(fn ($q) => $q
                ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
                ->where(fn ($inner) => $inner
                    ->where('finished_at', '>=', $recentlyReleased)
                    ->orWhere('last_heartbeat_at', '>=', $recentlyReleased)))
            ->orWhere(fn ($q) => $q
                ->whereNotNull('stopped_container_ids')
                ->whereJsonLength('stopped_container_ids', '>', 0));

        if ($includeBackupCleanup) {
            $query->orWhere('docker_container_cleanup_pending', true);
        }
    }

    /**
     * Terminal backup runs whose application containers were stopped for the
     * backup but never restarted (worker crash between stop and restart).
     *
     * @return Collection<int, BackupRun>
     */
    private function backupRunsWithStoppedContainers(CarbonInterface $cutoff): Collection
    {
        return BackupRun::query()
            ->where('docker_host_id', DockerHost::LOCAL_ID)
            ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED])
            ->whereNotNull('stopped_container_ids')
            ->whereJsonLength('stopped_container_ids', '>', 0)
            ->where('docker_container_cleanup_pending', false)
            ->where(function ($query) use ($cutoff): void {
                // Recover the containers unless we would be racing the group worker
                // that is *currently* restarting this member's containers in its
                // finally (docker start is idempotent, but a transient failure
                // there could clash with the live restart).
                $query
                    // Not the current member of a live group run: either it has no
                    // live group run at all...
                    ->whereDoesntHave('groupRun', fn ($group) => $group
                        ->where('status', BackupGroupRun::STATUS_RUNNING)
                        ->where('last_heartbeat_at', '>=', $cutoff))
                    // A durable coordinator heartbeat does not imply a live local
                    // worker. Recovery still takes the worker's volume lock and
                    // refreshes the member before restarting any containers.
                    ->orWhereHas('groupRun', fn ($group) => $group->whereNotNull('member_run_ids'))
                    // ...or the worker has already moved on to a later member (a
                    // higher-id member run exists), so this member's restart has
                    // finished or failed and must be recovered now — not left down
                    // until the whole group ends.
                    ->orWhereExists(fn ($exists) => $exists
                        ->selectRaw('1')
                        ->from('backup_runs as later_member')
                        ->whereColumn('later_member.docker_host_id', 'backup_runs.docker_host_id')
                        ->whereColumn('later_member.backup_group_run_id', 'backup_runs.backup_group_run_id')
                        ->whereColumn('later_member.id', '>', 'backup_runs.id'));
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Recover stopped containers only while owning the same lock as every worker
     * that can touch the source volume. The query that selected this run is a
     * snapshot: refresh it under the lock so a worker that already restarted and
     * cleared the ids cannot be followed by a stale docker start.
     */
    private function recoverBackupStoppedContainers(BackupRun $run, RunBackup $runBackup): bool
    {
        $run->loadMissing('job');
        $lockKey = VolumeJobLock::key($run->sourceVolumeName(), 'backup-job-'.$run->backup_job_id, $run->docker_host_id);
        $volumeLock = Cache::lock(VolumeJobLock::cacheKeyFor($lockKey), 86400);

        if (! $volumeLock->get()) {
            return false;
        }

        try {
            $run->refresh();

            if (! in_array($run->status, [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED, BackupRun::STATUS_CANCELLED], true)
                || ! $run->stopped_container_ids
                || $run->docker_container_cleanup_pending) {
                return false;
            }

            // Never restart a container that an active sibling member of the same
            // live group still needs stopped for its own archive.
            return $runBackup->restartStoppedContainers($run, $this->containersHeldByActiveGroupMembers($run));
        } finally {
            $volumeLock->release();
        }
    }

    private function recoverRestoreStoppedContainers(RestoreRun $run, RunRestore $runRestore): bool
    {
        $lockKey = VolumeJobLock::key($run->target_volume_name, 'restore-run-'.$run->id, $run->target_docker_host_id);
        $volumeLock = Cache::lock(VolumeJobLock::cacheKeyFor($lockKey), 86400);

        if (! $volumeLock->get()) {
            return false;
        }

        try {
            $run->refresh();

            if (! in_array($run->status, [RestoreRun::STATUS_SUCCESS, RestoreRun::STATUS_FAILED, RestoreRun::STATUS_CANCELLED], true)
                || ! $run->stopped_container_ids) {
                return false;
            }

            $runRestore->restartStoppedContainers($run);

            return true;
        } finally {
            $volumeLock->release();
        }
    }

    /**
     * Container ids that a still-running sibling member of this run's group run
     * has deliberately stopped for its own in-flight backup. These must not be
     * restarted while recovering this run's leftover containers — doing so would
     * bring the application up in the middle of the sibling's archive. Empty for a
     * standalone run or one whose group run is no longer running.
     *
     * @return array<int, string>
     */
    private function containersHeldByActiveGroupMembers(BackupRun $run): array
    {
        if ($run->backup_group_run_id === null) {
            return [];
        }

        return BackupRun::query()
            ->where('docker_host_id', $run->docker_host_id)
            ->where('backup_group_run_id', $run->backup_group_run_id)
            ->whereKeyNot($run->id)
            ->where('status', BackupRun::STATUS_RUNNING)
            ->whereNotNull('stopped_container_ids')
            ->pluck('stopped_container_ids')
            ->flatMap(fn ($ids) => is_array($ids) ? $ids : [])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Terminal restore runs whose application containers were stopped for a safe
     * in-place restore but never restarted (worker crash between stop and
     * restart). Mirrors {@see backupRunsWithStoppedContainers()}.
     *
     * @return Collection<int, RestoreRun>
     */
    private function restoreRunsWithStoppedContainers(): Collection
    {
        return RestoreRun::query()
            ->where('target_docker_host_id', DockerHost::LOCAL_ID)
            ->whereIn('status', [RestoreRun::STATUS_SUCCESS, RestoreRun::STATUS_FAILED, RestoreRun::STATUS_CANCELLED])
            ->whereNotNull('stopped_container_ids')
            ->whereJsonLength('stopped_container_ids', '>', 0)
            ->get();
    }

    /**
     * Narrow the candidate set before the per-run liveness decision: every
     * running run (its container is checked for liveness regardless of age) plus
     * queued runs old enough to count as never picked up by a worker.
     */
    private function candidateConstraint($query, CarbonInterface $cutoff, string $runningStatus): void
    {
        $query
            ->where('status', $runningStatus)
            ->orWhere(fn ($q) => $q->where('status', '!=', $runningStatus)->where('created_at', '<', $cutoff));
    }

    /**
     * Restore-only liveness for the long phases that run before any Docker
     * container (and thus any liveness-checkable docker_container_id) exists:
     * the optional safety backup and the archive download. Either can legitimately
     * exceed the short stale threshold, so a restore actively working through them
     * must not be reconciled as dead.
     */
    private function restoreIsProgressing(RestoreRun $run, CarbonInterface $cutoff): bool
    {
        if ($run->status !== RestoreRun::STATUS_RUNNING) {
            return false;
        }

        // Safety backup in flight: it runs as its own BackupRun, reconciled on its
        // own liveness/age, so the restore waiting on it is not itself stale. A
        // just-finished child also bridges the handoff until the parent worker can
        // renew its heartbeat after RunBackup returns.
        if ($run->pre_restore_backup_run_id !== null) {
            $backup = $run->preRestoreBackup()->where('docker_host_id', $run->target_docker_host_id)->first();

            if ($backup && in_array($backup->status, [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING], true)) {
                return true;
            }

            if ($backup
                && $backup->status === BackupRun::STATUS_SUCCESS
                && (($backup->finished_at?->greaterThanOrEqualTo($cutoff)) || ($backup->last_heartbeat_at?->greaterThanOrEqualTo($cutoff)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a candidate run is genuinely dead.
     *
     * A running run that recorded a Docker container is reconciled only when
     * that container is confirmed gone — the backup/restore container runs with
     * `--rm`, so a missing container means the process is gone, while a live one
     * means a long but healthy run we must leave untouched. When Docker can't be
     * reached (indeterminate liveness), we fall back to the age threshold rather
     * than failing a healthy recent run on a transient blip. Running runs with no
     * recorded container (worker died between marking running and creating the
     * container) and queued runs also fall back to the age threshold.
     */
    private function isStale(Model $run, CarbonInterface $cutoff, string $runningStatus): bool
    {
        if (app(HostWorkAdmission::class)->isWaiting($run)) {
            return false;
        }

        if ($run->status === $runningStatus) {
            if (filled($run->docker_container_id)) {
                $alive = $this->containerIsAlive->handle($run->docker_container_id);

                if ($alive !== null) {
                    return $alive === false;
                }
                // Indeterminate (Docker unreachable): fall through to the age gate.
            }

            // No live container yet (worker died between marking running and
            // creating the container) — or Docker was unreachable. Prefer the
            // progress heartbeat when the run records one: a restore spends its
            // pre-container time on a safety backup and archive download, each of
            // which refreshes last_heartbeat_at, so a slow-but-healthy restore is
            // not failed on the short default threshold. Backup runs have no
            // heartbeat column and fall back to started_at as before.
            $progressedAt = $run->last_heartbeat_at ?? $run->started_at ?? $run->created_at;

            return $progressedAt !== null && $progressedAt->lessThan($cutoff);
        }

        return $run->created_at !== null && $run->created_at->lessThan($cutoff);
    }
}
