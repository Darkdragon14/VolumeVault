<?php

namespace App\Console\Commands;

use App\Actions\Runs\DispatchQueuedRun;
use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Support\DeploymentMode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class DispatchQueuedRuns extends Command
{
    protected $signature = 'volumevault:dispatch-queued-runs';

    protected $description = 'Recover queued run dispatch and advance durable sequential backup groups';

    public function handle(DispatchQueuedRun $dispatchQueuedRun): int
    {
        $dispatched = 0;

        \App\Models\AgentOperation::where('kind', 'destination')->where('docker_host_id', DockerHost::LOCAL_ID)
            ->where(fn ($query) => $query->where('status', 'pending')->orWhere(fn ($query) => $query->where('status', 'running')
                ->where(fn ($query) => $query->whereNull('last_progress_at')->orWhere('last_progress_at', '<=', now()->subMinutes(\App\Jobs\RunDestinationOperation::RECOVERY_MINUTES)))))
            ->each(function (\App\Models\AgentOperation $operation) use (&$dispatched): void {
                if ($operation->status === 'pending' && in_array($operation->payload['destination']['provider'], ['local', 'docker_volume'], true)
                    && (! DeploymentMode::localExecutionEnabled() || app(\App\Services\Agents\HostWorkAdmission::class)->isMaintained(DockerHost::LOCAL_ID))) {
                    return;
                }
                \App\Jobs\RunDestinationOperation::dispatch($operation->id);
                $dispatched++;
            });

        $this->eligible(BackupRun::query()
            ->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->where('docker_host_id', '!=', DockerHost::LOCAL_ID))
            ->whereNull('backup_group_run_id')
            ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE))
            ->each(fn (BackupRun $run) => $this->dispatch($run, $dispatchQueuedRun, $dispatched));

        $this->eligible(RestoreRun::query()->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->where('target_docker_host_id', '!=', DockerHost::LOCAL_ID)))
            ->each(fn (RestoreRun $run) => $this->dispatch($run, $dispatchQueuedRun, $dispatched));

        if (DeploymentMode::localExecutionEnabled()) {
            $this->eligible(BackupGroupRun::query()->whereNull('member_run_ids'))
                ->each(fn (BackupGroupRun $run) => $this->dispatch($run, $dispatchQueuedRun, $dispatched));
        }

        BackupGroupRun::whereNotNull('member_run_ids')->whereIn('status', ['queued', 'running'])->orderBy('id')
            ->each(fn (BackupGroupRun $run) => $this->dispatch($run, $dispatchQueuedRun, $dispatched));

        $this->info("Dispatched {$dispatched} queued run(s).");

        return self::SUCCESS;
    }

    private function eligible(Builder $query): Builder
    {
        return $query
            ->where('status', BackupRun::STATUS_QUEUED)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull('dispatch_published_at')
                        ->where(function (Builder $query): void {
                            $query->whereNull('dispatch_attempted_at')
                                ->orWhere('dispatch_attempted_at', '<=', now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES));
                        });
                })->orWhere(function (Builder $query): void {
                    $query->where('dispatch_published_at', '<=', now()->subMinutes(DispatchQueuedRun::LEASE_MINUTES))
                        ->whereColumn('dispatch_attempted_at', '<=', 'dispatch_published_at');
                });
            })
            ->orderBy('id');
    }

    private function dispatch(BackupRun|RestoreRun|BackupGroupRun $run, DispatchQueuedRun $dispatchQueuedRun, int &$dispatched): void
    {
        try {
            if ($dispatchQueuedRun->handle($run)) {
                $dispatched++;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('Failed to dispatch '.$run::class." {$run->getKey()}: {$exception->getMessage()}");
        }
    }
}
