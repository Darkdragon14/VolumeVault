<?php

namespace App\Actions\Restore;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use Illuminate\Validation\ValidationException;

class ResolveRestoreDestination
{
    /** @return array<int, array<string, mixed>> */
    public function knownRemoteBackups(BackupJob $job, BackupDestination $destination, ?BackupRun $run = null): array
    {
        return BackupRun::query()->where('backup_job_id', $job->id)
            ->when($run !== null, fn ($query) => $query->whereKey($run->id))
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->whereNotNull('backup_key')
            ->where('backup_destination_id_snapshot', $destination->id)
            ->where('backup_destination_locator_fingerprint', $destination->locatorFingerprint())
            ->latest()->get()->map(fn (BackupRun $backup): array => [
                'key' => $backup->backup_key,
                'backup_run_id' => $backup->id,
                'size' => $backup->backup_size_bytes,
                'last_modified' => $backup->finished_at?->toIso8601String(),
                'belongs_to_job' => true,
                'verification_deferred' => true,
            ])->all();
    }

    public function handle(BackupJob $job, ?int $backupRunId = null, bool $lockForUpdate = false): BackupDestination
    {
        $run = $this->backupRun($job, $backupRunId, $lockForUpdate);

        if ($run === null) {
            $job->loadMissing('destination');
            $destination = $job->destination;
        } else {
            $destination = $run->destinationForRun();
        }

        if (! $destination) {
            throw ValidationException::withMessages([
                'destination' => 'The backup destination used by this run is no longer available.',
            ]);
        }

        if (! $destination->is_active) {
            throw ValidationException::withMessages([
                'destination' => 'The backup destination is inactive.',
            ]);
        }

        if ($run !== null && blank($run->backup_destination_locator_fingerprint)) {
            throw ValidationException::withMessages([
                'destination' => 'The selected backup run has no verifiable destination location.',
            ]);
        }

        if ($run !== null && ! hash_equals($run->backup_destination_locator_fingerprint, $destination->locatorFingerprint())) {
            throw ValidationException::withMessages([
                'destination' => 'The backup destination location has changed since this backup was created.',
            ]);
        }

        return $destination;
    }

    public function backupRun(BackupJob $job, ?int $backupRunId = null, bool $lockForUpdate = false): ?BackupRun
    {
        if ($backupRunId === null) {
            return null;
        }

        $query = BackupRun::query()
            ->whereKey($backupRunId)
            ->where('backup_job_id', $job->id)
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->whereNotNull('backup_key');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $run = $query->first();

        if (! $run || blank($run->backup_key)) {
            throw ValidationException::withMessages([
                'backup_run_id' => 'The selected backup run is not available for this job.',
            ]);
        }

        $run->setRelation('job', $job);
        $run->load('snapshotDestination');

        return $run;
    }
}
