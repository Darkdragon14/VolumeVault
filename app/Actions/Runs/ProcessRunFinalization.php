<?php

namespace App\Actions\Runs;

use App\Actions\Backup\RunBackup;
use App\Jobs\ProcessRunFinalizationJob;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RunFinalization;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\DeploymentMode;
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
        if ($this->requiresDisabledLocalStorage($finalizationId)) {
            return;
        }

        $claimToken ??= (string) Str::uuid();
        $finalization = $this->claim($finalizationId, $claimToken);

        if ($finalization === null) {
            return;
        }

        try {
            if ($finalization->type === RunFinalization::TYPE_ARCHIVE_METADATA && $finalization->remote_metadata_payload !== null) {
                $metadata = app(\App\Services\BackupDestinations\RemoteArchiveMetadata::class)->detect($finalization);
                if ($metadata === null) {
                    RunFinalization::whereKey($finalization->id)->where('claim_token', $claimToken)->update([
                        'status' => RunFinalization::STATUS_PENDING, 'attempts' => $finalization->attempts - 1,
                        'available_at' => now()->addMinute(), 'claim_token' => null, 'claimed_at' => null,
                    ]);

                    return;
                }
                $this->complete($finalization->id, $claimToken, $metadata);

                return;
            }
            $metadata = match ($finalization->type) {
                RunFinalization::TYPE_ARCHIVE_METADATA => $this->runBackup->detectArchiveMetadata($finalization->backup_run_id),
                RunFinalization::TYPE_FINISHED_NOTIFICATION => $this->sendFinishedNotification($finalization),
                RunFinalization::TYPE_STARTED_NOTIFICATION => $this->sendStartedNotification($finalization),
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
            if ($this->requiresDisabledLocalStorage((int) $finalizationId)) {
                continue;
            }

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
            ->when(DeploymentMode::isOrchestrator(), fn (Builder $query) => $query
                ->with(['backupRun.snapshotDestination', 'backupRun.job.destination']))
            ->where(fn (Builder $query) => $query
                ->whereNull('enqueue_token')
                ->orWhere('enqueued_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES)))
            ->orderBy('id')
            ->lazyById()
            ->reject(fn (RunFinalization $finalization): bool => $this->requiresDisabledLocalStorage($finalization))
            ->reject(fn (RunFinalization $finalization): bool => $this->hasOutstandingNotificationDependencies($finalization))
            ->take($limit)
            ->pluck('id')
            ->all();

        return $this->dispatch($ids);
    }

    private function requiresDisabledLocalStorage(int|RunFinalization $finalization): bool
    {
        if (! DeploymentMode::isOrchestrator()) {
            return false;
        }

        if (is_int($finalization)) {
            $finalization = RunFinalization::find($finalization);
        }

        return $finalization?->type === RunFinalization::TYPE_ARCHIVE_METADATA
            && $finalization->remote_metadata_payload === null
            && $finalization->backupRun?->destinationForRun()?->isHostBound() === true;
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
                    ->orWhere('type', RunFinalization::TYPE_STARTED_NOTIFICATION)
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

            if ($finalization === null || $this->hasOutstandingNotificationDependencies($finalization) || ($finalization->enqueue_token !== null && $finalization->enqueued_at?->isAfter(now()->subMinutes(self::STALE_AFTER_MINUTES)))) {
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

            if ($finalization === null || $this->hasOutstandingNotificationDependencies($finalization)) {
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

    private function hasOutstandingNotificationDependencies(RunFinalization $finalization): bool
    {
        if ($finalization->type !== RunFinalization::TYPE_FINISHED_NOTIFICATION) {
            return false;
        }
        foreach (['backup_run_id', 'backup_group_run_id', 'restore_run_id'] as $owner) {
            if ($finalization->{$owner} !== null && RunFinalization::where($owner, $finalization->{$owner})
                ->where('type', RunFinalization::TYPE_STARTED_NOTIFICATION)->outstanding()->exists()) {
                return true;
            }
        }
        if ($finalization->backup_group_run_id === null) {
            return false;
        }
        $groupRun = $finalization->backupGroupRun;
        $memberIds = $finalization->context['member_run_ids'] ?? $groupRun?->member_run_ids
            ?? $groupRun?->memberRuns()->pluck('id')->all() ?? [];

        return RunFinalization::whereIn('backup_run_id', $memberIds)
            ->whereHas('backupRun', fn (Builder $query) => $query->where('backup_group_run_id', $finalization->backup_group_run_id))
            ->where('type', RunFinalization::TYPE_ARCHIVE_METADATA)->outstanding()->exists();
    }

    private function notificationRecipient(RunFinalization $finalization): ?NotificationChannel
    {
        $channel = $finalization->notificationChannel;
        if ($channel !== null && $finalization->notification_snapshot !== null) {
            $channel = clone $channel;
            $channel->forceFill(\Illuminate\Support\Arr::only($finalization->notification_snapshot, RunFinalization::NOTIFICATION_SNAPSHOT_FIELDS));
        }

        return $channel;
    }

    /** @return array<string, mixed> */
    private function sendStartedNotification(RunFinalization $finalization): array
    {
        if ($finalization->restore_run_id !== null) {
            $run = $finalization->restoreRun;
            $channel = $this->notificationRecipient($finalization);
            if ($run === null || $channel === null) {
                throw new RuntimeException('Finalization owner or recipient no longer exists.');
            }
            $this->sendShoutrrrNotification->sendRestoreRunStartedToChannel($run, $channel);

            return [];
        }
        if ($finalization->backup_run_id !== null) {
            $run = $finalization->backupRun;
            $channel = $this->notificationRecipient($finalization);
            if ($run === null || $channel === null) {
                throw new RuntimeException('Finalization owner or recipient no longer exists.');
            }
            $this->sendShoutrrrNotification->sendBackupRunStartedToChannel($run, $channel);

            return [];
        }
        $run = $finalization->backupGroupRun;
        $channel = $this->notificationRecipient($finalization);
        if ($run !== null && $channel !== null) {
            $run = clone $run;
            $run->status = 'running';
            $this->sendShoutrrrNotification->sendGroupRunStartedToChannel($run, $channel);
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function sendFinishedNotification(RunFinalization $finalization): array
    {
        $channel = $this->notificationRecipient($finalization);

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
                ...($finalization->remote_metadata_payload !== null ? ['remote_metadata_payload' => null] : []),
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
                if ($finalization->remote_metadata_payload !== null) {
                    $finalization->update(['remote_metadata_payload' => null]);
                }
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
            if ($finalization->remote_metadata_payload !== null) {
                $finalization->update(['remote_metadata_payload' => null]);
            }
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
