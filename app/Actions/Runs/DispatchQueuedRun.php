<?php

namespace App\Actions\Runs;

use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\BackupGroupRun;
use App\Models\BackupRun;
use App\Models\RestoreRun;
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
        if ($this->queue->connection() instanceof SyncQueue) {
            throw new RuntimeException('Queued backup and restore runs require an asynchronous queue connection; QUEUE_CONNECTION=sync is not supported.');
        }

        if ($run instanceof BackupRun && ($run->belongsToGroupRun() || $run->trigger === BackupRun::TRIGGER_PRE_RESTORE)) {
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
