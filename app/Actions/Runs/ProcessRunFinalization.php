<?php

namespace App\Actions\Runs;

use App\Actions\Backup\RunBackup;
use App\Jobs\ProcessRunFinalizationJob;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RunFinalization;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessRunFinalization
{
    public const STALE_AFTER_MINUTES = 10;

    public const DISPATCH_BACKOFF_SECONDS = 60;

    public function __construct(
        private readonly RunBackup $runBackup,
        private readonly SendShoutrrrNotification $sendShoutrrrNotification,
    ) {}

    public function handle(int $finalizationId, ?string $enqueueToken = null, ?string $claimToken = null): void
    {
        $claimToken ??= (string) Str::uuid();
        $finalization = $this->claim($finalizationId, $claimToken);

        if ($finalization === null) {
            return;
        }

        try {
            $metadata = match ($finalization->type) {
                RunFinalization::TYPE_ARCHIVE_METADATA => $this->runBackup->detectArchiveMetadata($finalization->backup_run_id),
                RunFinalization::TYPE_FINISHED_NOTIFICATION => $this->sendFinishedNotification($finalization),
                default => throw new RuntimeException('Unknown run finalization type.'),
            };
            $this->complete($finalization->id, $claimToken, $metadata);
        } catch (Throwable $exception) {
            $this->failClaim($finalization->id, $claimToken, $exception);
            report($exception);
        }
    }

    /** @param list<int> $finalizationIds */
    public function dispatch(array $finalizationIds): int
    {
        $dispatched = 0;

        foreach (array_unique($finalizationIds) as $finalizationId) {
            $enqueueToken = $this->admit((int) $finalizationId);

            if ($enqueueToken === null) {
                continue;
            }

            try {
                Bus::dispatch(new ProcessRunFinalizationJob(
                    (int) $finalizationId,
                    $enqueueToken,
                    (string) Str::uuid(),
                ));
                $dispatched++;
            } catch (Throwable $exception) {
                $this->clearEnqueueLease((int) $finalizationId, $enqueueToken, $exception);
                report($exception);
            }
        }

        return $dispatched;
    }

    public function dispatchDue(int $limit = 100): int
    {
        $ids = $this->dueQuery()
            ->where(fn (Builder $query) => $query
                ->whereNull('enqueue_token')
                ->orWhere('enqueued_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES)))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return $this->dispatch($ids);
    }

    public function failExecution(int $finalizationId, string $enqueueToken, string $claimToken, Throwable $exception): void
    {
        if ($this->failClaim($finalizationId, $claimToken, $exception)) {
            return;
        }

        DB::transaction(function () use ($finalizationId, $enqueueToken, $claimToken, $exception): void {
            $finalization = RunFinalization::query()->lockForUpdate()->find($finalizationId);

            if ($finalization?->enqueue_token !== $enqueueToken) {
                return;
            }

            if ($finalization->status === RunFinalization::STATUS_PROCESSING
                && $finalization->claim_token !== null
                && $finalization->claim_token !== $claimToken) {
                $finalization->forceFill([
                    'available_at' => now()->addSeconds(self::DISPATCH_BACKOFF_SECONDS),
                    'enqueue_token' => null,
                    'enqueued_at' => null,
                    'last_error' => $this->errorMessage($exception),
                ])->save();

                return;
            }

            if ($finalization->status === RunFinalization::STATUS_COMPLETED
                || ($finalization->status === RunFinalization::STATUS_FAILED && $finalization->available_at === null)) {
                $finalization->forceFill(['enqueue_token' => null, 'enqueued_at' => null])->save();

                return;
            }

            if ($finalization->attempts >= RunFinalization::MAX_ATTEMPTS) {
                $this->terminalizeExhausted($finalization, $this->errorMessage($exception));

                return;
            }

            $finalization->attempts++;

            if ($finalization->attempts >= RunFinalization::MAX_ATTEMPTS) {
                $this->terminalizeExhausted($finalization, $this->errorMessage($exception));

                return;
            }

            $finalization->forceFill([
                'status' => RunFinalization::STATUS_FAILED,
                'available_at' => now()->addSeconds($this->retryDelay($finalization->attempts)),
                'enqueue_token' => null,
                'enqueued_at' => null,
                'last_error' => $this->errorMessage($exception),
            ])->save();
        });
    }

    private function dueQuery(): Builder
    {
        return RunFinalization::query()
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('status', RunFinalization::STATUS_PENDING)
                        ->where(fn (Builder $query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));
                })->orWhere(function (Builder $query): void {
                    $query->where('status', RunFinalization::STATUS_FAILED)
                        ->whereNotNull('available_at')
                        ->where('available_at', '<=', now());
                })->orWhere(function (Builder $query): void {
                    $query->where('status', RunFinalization::STATUS_PROCESSING)
                        ->where('claimed_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
                        ->where(fn (Builder $query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()));
                });
            })
            ->where(function (Builder $query): void {
                $query->where('type', RunFinalization::TYPE_ARCHIVE_METADATA)
                    ->orWhereNull('backup_run_id')
                    ->orWhereHas('backupRun', fn (Builder $query) => $query->where('status', BackupRun::STATUS_FAILED))
                    ->orWhereDoesntHave('backupRun.finalizations', fn (Builder $query) => $query
                        ->where('type', RunFinalization::TYPE_ARCHIVE_METADATA)
                        ->outstanding());
            });
    }

    private function admit(int $finalizationId): ?string
    {
        return DB::transaction(function () use ($finalizationId): ?string {
            $finalization = $this->dueQuery()->lockForUpdate()->find($finalizationId);

            if ($finalization === null || ($finalization->enqueue_token !== null && $finalization->enqueued_at?->isAfter(now()->subMinutes(self::STALE_AFTER_MINUTES)))) {
                return null;
            }

            $enqueueToken = (string) Str::uuid();
            $finalization->forceFill(['enqueue_token' => $enqueueToken, 'enqueued_at' => now()])->save();

            return $enqueueToken;
        });
    }

    private function claim(int $finalizationId, string $claimToken): ?RunFinalization
    {
        return DB::transaction(function () use ($finalizationId, $claimToken): ?RunFinalization {
            $finalization = $this->dueQuery()->lockForUpdate()->find($finalizationId);

            if ($finalization === null) {
                return null;
            }

            if ($finalization->attempts >= RunFinalization::MAX_ATTEMPTS) {
                $this->terminalizeExhausted($finalization, 'Maximum finalization attempts exhausted before execution.');

                return null;
            }

            $finalization->forceFill([
                'status' => RunFinalization::STATUS_PROCESSING,
                'attempts' => $finalization->attempts + 1,
                'claimed_at' => now(),
                'claim_token' => $claimToken,
                'enqueue_token' => null,
                'enqueued_at' => null,
                'finished_at' => null,
                'last_error' => null,
            ])->save();

            return $finalization;
        });
    }

    /** @return array<string, mixed> */
    private function sendFinishedNotification(RunFinalization $finalization): array
    {
        $channel = NotificationChannel::query()->find($finalization->notification_channel_id);

        if ($channel === null) {
            throw new RuntimeException('Finalization recipient no longer exists.');
        }

        if ($finalization->backup_run_id !== null) {
            $run = $finalization->backupRun()->with('job.destination', 'snapshotDestination', 'initiatedBy')->first();

            if ($run === null) {
                throw new RuntimeException('Finalization owner no longer exists.');
            }

            $this->sendShoutrrrNotification->sendBackupRunFinishedToChannel($run, $channel);
        } elseif ($finalization->restore_run_id !== null) {
            $run = $finalization->restoreRun()->with('job.destination', 'initiatedBy')->first();

            if ($run === null) {
                throw new RuntimeException('Finalization owner no longer exists.');
            }

            $this->sendShoutrrrNotification->sendRestoreRunFinishedToChannel($run, $channel);
        } else {
            $run = $finalization->backupGroupRun()->with('group', 'initiatedBy')->first();

            if ($run === null) {
                throw new RuntimeException('Finalization owner no longer exists.');
            }

            $this->sendShoutrrrNotification->sendGroupRunFinishedToChannel($run, $channel);
        }

        return [];
    }

    /** @param array<string, mixed> $metadata */
    private function complete(int $finalizationId, string $claimToken, array $metadata): void
    {
        DB::transaction(function () use ($finalizationId, $claimToken, $metadata): void {
            $finalization = RunFinalization::query()->lockForUpdate()->find($finalizationId);

            if ($finalization?->status !== RunFinalization::STATUS_PROCESSING || $finalization->claim_token !== $claimToken) {
                return;
            }

            if ($finalization->type === RunFinalization::TYPE_ARCHIVE_METADATA) {
                BackupRun::query()->whereKey($finalization->backup_run_id)->update([...$metadata, 'archive_metadata_pending' => false]);
            }

            $finalization->forceFill([
                'status' => RunFinalization::STATUS_COMPLETED,
                'finished_at' => now(),
                'claimed_at' => null,
                'claim_token' => null,
                'available_at' => null,
                'last_error' => null,
                'enqueue_token' => null,
                'enqueued_at' => null,
            ])->save();
        });
    }

    private function failClaim(int $finalizationId, string $claimToken, Throwable $exception): bool
    {
        return DB::transaction(function () use ($finalizationId, $claimToken, $exception): bool {
            $finalization = RunFinalization::query()->lockForUpdate()->find($finalizationId);

            if ($finalization?->status !== RunFinalization::STATUS_PROCESSING || $finalization->claim_token !== $claimToken) {
                return false;
            }

            $exhausted = $finalization->attempts >= RunFinalization::MAX_ATTEMPTS;
            $finalization->forceFill([
                'status' => RunFinalization::STATUS_FAILED,
                'available_at' => $exhausted ? null : now()->addSeconds($this->retryDelay($finalization->attempts)),
                'claimed_at' => null,
                'claim_token' => null,
                'finished_at' => $exhausted ? now() : null,
                'last_error' => $this->errorMessage($exception),
                'enqueue_token' => null,
                'enqueued_at' => null,
            ])->save();

            if ($exhausted && $finalization->type === RunFinalization::TYPE_ARCHIVE_METADATA) {
                BackupRun::query()->whereKey($finalization->backup_run_id)->update(['archive_metadata_pending' => false]);
            }

            return true;
        });
    }

    private function terminalizeExhausted(RunFinalization $finalization, string $error): void
    {
        $finalization->forceFill([
            'status' => RunFinalization::STATUS_FAILED,
            'available_at' => null,
            'claimed_at' => null,
            'claim_token' => null,
            'enqueue_token' => null,
            'enqueued_at' => null,
            'finished_at' => now(),
            'last_error' => $error,
        ])->save();

        if ($finalization->type === RunFinalization::TYPE_ARCHIVE_METADATA) {
            BackupRun::query()->whereKey($finalization->backup_run_id)->update(['archive_metadata_pending' => false]);
        }
    }

    private function clearEnqueueLease(int $finalizationId, string $enqueueToken, Throwable $exception): void
    {
        DB::transaction(function () use ($finalizationId, $enqueueToken, $exception): void {
            RunFinalization::query()
                ->whereKey($finalizationId)
                ->where('enqueue_token', $enqueueToken)
                ->update([
                    'available_at' => now()->addSeconds(self::DISPATCH_BACKOFF_SECONDS),
                    'enqueue_token' => null,
                    'enqueued_at' => null,
                    'last_error' => $this->errorMessage($exception),
                ]);
        });
    }

    private function retryDelay(int $attempts): int
    {
        return match ($attempts) {
            1 => 60,
            2 => 300,
            3 => 900,
            default => 3600,
        };
    }

    private function errorMessage(Throwable $exception): string
    {
        return str($exception->getMessage() ?: 'Run finalization failed.')->limit(4000)->toString();
    }
}
