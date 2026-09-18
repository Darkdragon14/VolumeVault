<?php

namespace App\Actions\Runs;

use App\Actions\Backup\AdvanceBackupGroupRun;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\Agents\HostWorkAdmission;
use App\Support\DeploymentMode;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DispatchQueuedRun
{
    public const LEASE_MINUTES = 5;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly QueueFactory $queue,
    ) {}

    public function handle(BackupRun|RestoreRun|BackupGroupRun $run): bool
    {
        $run->refresh();

        if ($run instanceof BackupGroupRun && $run->member_run_ids !== null) {
            app(AdvanceBackupGroupRun::class)->handle($run);

            return true;
        }
        if ($run instanceof BackupRun && $run->belongsToGroupRun() && ! AdvanceBackupGroupRun::authorizes($run)) {
            return false;
        }

        if (($run instanceof BackupRun && $run->docker_host_id !== DockerHost::LOCAL_ID)
            || ($run instanceof RestoreRun && $run->target_docker_host_id !== DockerHost::LOCAL_ID)) {
            return app(DispatchAgentOperation::class)->handle($run);
        }

        if (app(HostWorkAdmission::class)->isWaiting($run)) {
            return false;
        }

        if (DeploymentMode::isOrchestrator()) {
            return false;
        }

        if ($this->queue->connection() instanceof SyncQueue) {
            throw new RuntimeException('Queued backup and restore runs require an asynchronous queue connection; QUEUE_CONNECTION=sync is not supported.');
        }

        if ($run instanceof BackupRun && $run->trigger === BackupRun::TRIGGER_PRE_RESTORE) {
            return false;
        }

        $run->refresh();

        if ($run->dispatch_published_at !== null) {
            return $this->republish($run);
        }

        $attemptedAt = now();
        $dispatchToken = (string) Str::uuid();
        $claimed = $run->newQuery()
            ->whereKey($run->getKey())
            ->where('status', BackupRun::STATUS_QUEUED)
            ->whereNull('dispatch_published_at')
            ->where(function ($query): void {
                $query->whereNull('dispatch_attempted_at')
                    ->orWhere('dispatch_attempted_at', '<=', now()->subMinutes(self::LEASE_MINUTES));
            })
            ->tap(fn ($query) => app(HostWorkAdmission::class)->constrain($query))
            ->update([
                'dispatch_token' => $dispatchToken,
                'dispatch_attempted_at' => $attemptedAt,
            ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $this->dispatch($run, $dispatchToken);
        } catch (Throwable $exception) {
            $run->newQuery()
                ->whereKey($run->getKey())
                ->where('status', BackupRun::STATUS_QUEUED)
                ->where('dispatch_token', $dispatchToken)
                ->update([
                    'dispatch_token' => null,
                    'dispatch_attempted_at' => null,
                    'dispatch_published_at' => null,
                ]);

            throw $exception;
        }

        $publishedAt = now();
        $published = $run->newQuery()
            ->whereKey($run->getKey())
            ->where('status', BackupRun::STATUS_QUEUED)
            ->where('dispatch_token', $dispatchToken)
            ->whereNull('dispatch_published_at')
            ->update(['dispatch_published_at' => $publishedAt]);

        if ($published === 1) {
            $run->forceFill([
                'dispatch_token' => $dispatchToken,
                'dispatch_attempted_at' => $attemptedAt,
                'dispatch_published_at' => $publishedAt,
            ]);
        } else {
            $run->refresh();
        }

        return true;
    }

    private function republish(BackupRun|RestoreRun|BackupGroupRun $run): bool
    {
        $dispatchToken = (string) $run->dispatch_token;

        if ($dispatchToken === '' || $run->dispatch_attempted_at === null || $run->dispatch_published_at === null) {
            return false;
        }

        $previousAttemptedAt = $run->dispatch_attempted_at;
        $publishedAt = $run->dispatch_published_at;
        $attemptedAt = now();
        $claimed = $run->newQuery()
            ->whereKey($run->getKey())
            ->where('status', BackupRun::STATUS_QUEUED)
            ->where('dispatch_token', $dispatchToken)
            ->where('dispatch_attempted_at', '<=', $publishedAt)
            ->where('dispatch_published_at', '<=', now()->subMinutes(self::LEASE_MINUTES))
            ->tap(fn ($query) => app(HostWorkAdmission::class)->constrain($query))
            ->update(['dispatch_attempted_at' => $attemptedAt]);

        if ($claimed === 0) {
            $run->refresh();

            return false;
        }

        try {
            $this->dispatch($run, $dispatchToken);
        } catch (Throwable $exception) {
            $run->newQuery()
                ->whereKey($run->getKey())
                ->where('status', BackupRun::STATUS_QUEUED)
                ->where('dispatch_token', $dispatchToken)
                ->where('dispatch_attempted_at', $attemptedAt)
                ->where('dispatch_published_at', $publishedAt)
                ->update(['dispatch_attempted_at' => $previousAttemptedAt]);

            $run->forceFill(['dispatch_attempted_at' => $previousAttemptedAt]);

            throw $exception;
        }

        $run->forceFill(['dispatch_attempted_at' => $attemptedAt]);

        return true;
    }

    private function dispatch(BackupRun|RestoreRun|BackupGroupRun $run, string $dispatchToken): void
    {
        $job = match (true) {
            $run instanceof BackupRun => new RunBackupJob($run->getKey(), $dispatchToken),
            $run instanceof RestoreRun => new RunRestoreJob($run->getKey(), $dispatchToken),
            $run instanceof BackupGroupRun => new RunBackupGroupJob($run->getKey(), $dispatchToken),
        };

        $this->dispatcher->dispatch($job);
    }
}
