<?php

namespace App\Services\Agents;

use App\Actions\Backup\AdvanceBackupGroupRun;
use App\Actions\Backup\RunBackup;
use App\Actions\Restore\RunRestore;
use App\Models\AgentOperation;
use App\Models\ArchiveRelay;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReconcileAgentOperations
{
    public function handle(): void
    {
        $cutoff = now()->subMinutes(max(1, (int) config('volumevault.agents.recovery_minutes', 15)));
        foreach (DockerHost::where('driver', DockerHost::DRIVER_AGENT)->lazyById() as $host) {
            DB::transaction(function () use ($host, $cutoff): void {
                $host = DockerHost::query()->lockForUpdate()->findOrFail($host->id);
                if ($host->docker_status === 'unavailable' && $host->agent_docker_unavailable_at === null) {
                    $host->forceFill(['agent_docker_unavailable_at' => now()])->save();
                }
                foreach (AgentOperation::where('docker_host_id', $host->id)->whereIn('status', ['pending', 'running'])->lockForUpdate()->get() as $operation) {
                    if ($operation->status === 'pending' && $host->maintenance_requested_at !== null && $host->agent_revoked_at === null) {
                        continue;
                    }
                    $invalidOwner = $operation->status === 'running' && $operation->owner_instance_id !== $host->agent_instance_id;
                    $progress = $operation->last_progress_at ?? $operation->claimed_at ?? $operation->created_at;
                    if ($host->agent_revoked_at !== null || $invalidOwner
                        || ($operation->status === 'running' && $this->dockerUnavailable($host, $cutoff))
                        || ($progress->lessThanOrEqualTo($cutoff) && ($operation->status === 'running' || $this->unavailable($host, $operation->kind, $operation->created_at, $cutoff)))) {
                        $this->cancel($operation, 'Remote operation failed: agent unavailable, incompatible, replaced, or no operation progress within the recovery deadline.');
                    }
                }
                foreach ($this->queuedRuns($host) as $run) {
                    $kind = $run instanceof BackupRun ? 'backup' : 'restore';
                    if ($run instanceof BackupRun && $run->belongsToGroupRun() && ! AdvanceBackupGroupRun::authorizes($run)) {
                        continue;
                    }
                    if ($host->maintenance_requested_at === null && $run->created_at->lessThanOrEqualTo($cutoff)
                        && $this->unavailable($host, $kind, $run->created_at, $cutoff)) {
                        $this->cancelQueued($host, $run, 'Remote run failed: agent unavailable or incompatible beyond the recovery deadline.');
                    }
                }
            }, attempts: 3);
        }
        $this->finishCancelled();
    }

    /** Called under the registry's host lock; never acquire job/group locks here. */
    public function invalidate(DockerHost $host, string $reason): void
    {
        foreach (AgentOperation::where('docker_host_id', $host->id)->whereIn('status', ['pending', 'running'])->lockForUpdate()->get() as $operation) {
            $this->cancel($operation, $reason);
        }
        foreach ($this->queuedRuns($host) as $run) {
            $this->cancelQueued($host, $run, $reason);
        }
        DB::afterCommit(function (): void {
            try {
                $this->finishCancelled();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function cleanupPending(DockerHost $host): bool
    {
        return AgentOperation::where('docker_host_id', $host->id)->where('status', 'cancelled')->get()
            ->contains(fn (AgentOperation $operation): bool => ($operation->context['cleanup_required'] ?? false) === true);
    }

    /** A cancelled receipt confirms cleanup only; it must never overwrite the failed run. */
    public function acknowledgeCleanup(AgentOperation $operation): void
    {
        $operation->update(['context' => [...($operation->context ?? []), 'cleanup_required' => false]]);
        foreach ([$operation->backupRun, $operation->restoreRun] as $run) {
            if ($run !== null) {
                $run->update(['docker_container_id' => null, 'docker_container_cleanup_pending' => false, 'stopped_container_ids' => null]);
            }
        }
    }

    /** Retried independently of cancellation so notification/job locks follow their established order. */
    private function finishCancelled(): void
    {
        foreach (AgentOperation::where('status', 'cancelled')->lazyById() as $operation) {
            if (! ($operation->context['recovery_pending'] ?? false)) {
                continue;
            }
            $reason = $operation->result['error_message'];
            $relay = $operation->kind === 'archive_export'
                ? ArchiveRelay::where('source_agent_operation_id', $operation->id)->first()
                : ($operation->restore_run_id ? ArchiveRelay::where('restore_run_id', $operation->restore_run_id)->first() : null);
            if ($relay !== null) {
                $relay->update(['status' => 'failed', 'error_message' => $reason]);
                foreach (AgentOperation::where(fn ($query) => $query->whereKey($relay->source_agent_operation_id)->orWhere('restore_run_id', $relay->restore_run_id))
                    ->whereIn('status', ['pending', 'running'])->get() as $peer) {
                    DB::transaction(function () use ($peer, $reason): void {
                        DockerHost::query()->lockForUpdate()->findOrFail($peer->docker_host_id);
                        $peer = AgentOperation::query()->lockForUpdate()->findOrFail($peer->id);
                        if ($peer->docker_host_id !== DockerHost::LOCAL_ID || $peer->status === 'pending') {
                            $this->cancel($peer, $reason);
                        }
                    }, attempts: 3);
                }
                app(RunRestore::class)->markFailed($relay->restoreRun, new RuntimeException($reason));
            } elseif ($operation->backupRun !== null) {
                app(RunBackup::class)->markFailed($operation->backupRun, new RuntimeException($reason));
            } elseif ($operation->restoreRun !== null) {
                app(RunRestore::class)->markFailed($operation->restoreRun, new RuntimeException($reason));
            }
            DB::transaction(function () use ($operation): void {
                DockerHost::query()->lockForUpdate()->findOrFail($operation->docker_host_id);
                $operation = AgentOperation::query()->lockForUpdate()->findOrFail($operation->id);
                $operation->update(['context' => [...($operation->context ?? []), 'recovery_pending' => false]]);
            }, attempts: 3);
        }
    }

    /** The caller must hold the host and operation locks. */
    public function cancel(AgentOperation $operation, string $reason): void
    {
        if (! in_array($operation->status, ['pending', 'running'], true)) {
            return;
        }
        $operation->update([
            'status' => 'cancelled', 'completed_at' => now(), 'payload' => null,
            'context' => [...($operation->context ?? []), 'cleanup_required' => $operation->status === 'running', 'recovery_pending' => true],
            'result' => ['status' => 'failed', 'error_message' => $reason],
        ]);
    }

    private function cancelQueued(DockerHost $host, BackupRun|RestoreRun $run, string $reason): void
    {
        $operation = AgentOperation::firstOrCreate([$run instanceof BackupRun ? 'backup_run_id' : 'restore_run_id' => $run->id], [
            'id' => (string) Str::uuid(), 'docker_host_id' => $host->id,
            'kind' => $run instanceof BackupRun ? 'backup' : 'restore', 'status' => 'pending',
        ]);
        $this->cancel($operation, $reason);
        if ($run->started_at !== null || $run->docker_container_id || $run->stopped_container_ids || $run->docker_container_cleanup_pending) {
            $operation->update(['context' => [...($operation->context ?? []), 'cleanup_required' => true]]);
        }
    }

    /** @return Collection<int, BackupRun|RestoreRun> */
    private function queuedRuns(DockerHost $host): Collection
    {
        return BackupRun::where('docker_host_id', $host->id)->where('status', 'queued')
            ->whereNotIn('id', AgentOperation::whereNotNull('backup_run_id')->select('backup_run_id'))->get()
            ->concat(RestoreRun::where('target_docker_host_id', $host->id)->where('status', 'queued')
                ->whereNotIn('id', AgentOperation::whereNotNull('restore_run_id')->select('restore_run_id'))->get());
    }

    private function unavailable(DockerHost $host, string $kind, CarbonInterface $createdAt, CarbonInterface $cutoff): bool
    {
        $capability = match ($kind) {
            'backup' => 'backup-v1', 'restore' => 'restore-v1', 'archive_export' => 'archive-relay-v1', default => 'destination-v1',
        };

        return ! app(AgentExecution::class)->supportsHost($host, $capability)
            || $this->dockerUnavailable($host, $cutoff)
            || ($host->last_seen_at ?? $host->agent_registered_at ?? $createdAt)->lessThanOrEqualTo($cutoff);
    }

    private function dockerUnavailable(DockerHost $host, CarbonInterface $cutoff): bool
    {
        return $host->docker_status === 'unavailable' && $host->agent_docker_unavailable_at?->lessThanOrEqualTo($cutoff);
    }
}
