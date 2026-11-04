<?php

namespace App\Actions\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;
use RuntimeException;

class ExactFilenameRetention
{
    private const MAX_RECORDS = 10000;

    public function requiresCapability(BackupRun $run): bool
    {
        return (bool) ($run->execution_options_snapshot['use_exact_filename'] ?? false)
            || ((($run->execution_options_snapshot['retention_count'] ?? 0) > 0 || ($run->execution_options_snapshot['retention_days'] ?? 0) > 0)
                && BackupRun::query()
                    ->where('backup_destination_locator_fingerprint', $run->backup_destination_locator_fingerprint)
                    ->where('execution_options_snapshot->use_exact_filename', true)->exists());
    }

    /**
     * Agents use isolated operation databases, so the controller supplies their
     * historical manifest. Ownership must never be inferred from a template.
     *
     * @return array{complete: bool, archives: list<array>, protected_filenames: list<string>, exact_filenames: list<string>}
     */
    public function manifest(BackupRun $run): array
    {
        if (! BackupRun::query()
            ->where('backup_destination_locator_fingerprint', $run->backup_destination_locator_fingerprint)
            ->where('execution_options_snapshot->use_exact_filename', true)->exists()) {
            return ['complete' => true, 'archives' => [], 'protected_filenames' => [], 'exact_filenames' => []];
        }
        $rows = BackupRun::query()
            ->where('backup_destination_locator_fingerprint', $run->backup_destination_locator_fingerprint)
            ->whereKeyNot($run->id)
            ->limit(self::MAX_RECORDS + 1)
            ->get(['id', 'backup_job_id', 'status', 'trigger', 'backup_filename', 'backup_retention_identity', 'execution_options_snapshot']);
        if ($rows->count() > self::MAX_RECORDS) {
            return ['complete' => false, 'archives' => [], 'protected_filenames' => [], 'exact_filenames' => []];
        }
        $protected = [];
        $exact = [];
        $archives = [];
        foreach ($rows->groupBy('backup_filename') as $filename => $references) {
            if ($filename === '') {
                continue;
            }
            if ($references->contains(fn (BackupRun $reference): bool => (bool) ($reference->execution_options_snapshot['use_exact_filename'] ?? false))) {
                $exact[] = (string) $filename;
            }
            if ($references->count() !== 1 || $references->contains(fn (BackupRun $reference): bool => $reference->backup_job_id !== $run->backup_job_id || $reference->trigger === BackupRun::TRIGGER_PRE_RESTORE || $reference->status !== BackupRun::STATUS_SUCCESS)) {
                $protected[] = (string) $filename;

                continue;
            }
            $reference = $references->first();
            if (($reference->execution_options_snapshot['use_exact_filename'] ?? false) && $reference->backup_retention_identity !== null) {
                $archives[] = $reference->backup_retention_identity;
            }
        }

        return ['complete' => $rows->count() <= self::MAX_RECORDS, 'archives' => $archives, 'protected_filenames' => $protected, 'exact_filenames' => $exact];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{key: string, filename: string, size: int, modified: int}
     */
    public function identity(array $object): array
    {
        $key = $object['key'] ?? null;
        $size = $object['size'] ?? null;
        $modified = $object['last_modified'] ?? null;
        if (! is_string($key) || $key === '' || ! is_int($size) || $size < 0 || ! is_string($modified) || $modified === '') {
            throw new RuntimeException('Archive identity is incomplete.');
        }

        return ['key' => $key, 'filename' => basename((string) ($object['display_name'] ?? $key)), 'size' => $size, 'modified' => Carbon::parse($modified)->getTimestamp()];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $identity
     */
    public function matches(array $object, array $identity): bool
    {
        try {
            return $this->identity($object) === $identity;
        } catch (\Throwable) {
            return false;
        }
    }
}
