<?php

namespace App\Jobs;

use App\Actions\Runs\CreateRunFinalizations;
use App\Actions\Runs\ProcessRunFinalization;
use App\Models\BackupJob;
use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Compatibility handler for jobs serialized before finalizations used separate
 * durable outbox rows. Keep its constructor properties stable across upgrades.
 */
class RecordArchiveMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $backupRunId,
        public readonly bool $sendFinishedNotification = false,
    ) {
        $this->onQueue('metadata');
    }

    public function handle(ProcessRunFinalization $processFinalization, CreateRunFinalizations $createFinalizations): void
    {
        $finalizationIds = DB::transaction(function () use ($createFinalizations): array {
            $job = BackupJob::query()
                ->whereKey(BackupRun::query()->select('backup_job_id')->whereKey($this->backupRunId))
                ->lockForUpdate()
                ->first();
            $run = BackupRun::query()->lockForUpdate()->find($this->backupRunId);

            if ($job === null || $run === null) {
                return [];
            }

            $ids = [$createFinalizations->createMetadata($run)->id];

            if ($this->sendFinishedNotification) {
                array_push($ids, ...$createFinalizations->createBackupNotifications($run, $job));
            }

            return $ids;
        });

        foreach ($finalizationIds as $finalizationId) {
            $processFinalization->handle($finalizationId);
        }
    }
}
