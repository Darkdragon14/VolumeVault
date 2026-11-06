<?php

namespace App\Actions\Backup;

use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class ExactFilenameRetention
{
    private const MAX_MANIFEST_FILENAMES = 10000;

    private const QUERY_BATCH_SIZE = 500;

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
     * @param  list<string>|null  $filenames  Actual listed names when storage is accessible locally.
     * @return array{complete: bool, archives: list<array>, protected_filenames: list<string>, exact_filenames: list<string>}
     */
    public function manifest(BackupRun $run, ?array $filenames = null): array
    {
        $history = BackupRun::query()
            ->where('backup_destination_locator_fingerprint', $run->backup_destination_locator_fingerprint)
            ->whereKeyNot($run->id);
        $namespace = $run->execution_options_snapshot['archive_namespace'] ?? null;
        $candidates = (clone $history)
            ->where('execution_options_snapshot->use_exact_filename', true)
            ->where(function (Builder $query) use ($run, $namespace): void {
                $query->where(function (Builder $query) use ($run): void {
                    $query->where('backup_job_id', $run->backup_job_id)
                        ->where('trigger', '!=', BackupRun::TRIGGER_PRE_RESTORE)
                        ->whereNotNull('backup_retention_identity');
                });
                if ($namespace !== null) {
                    $query->orWhere('backup_filename', 'like', RenderBackupFilename::archivePrefix($namespace).'run-%');
                }
            })
            ->whereNotNull('backup_filename')
            ->where('backup_filename', '!=', '')
            ->select('backup_filename')->distinct()->orderBy('backup_filename');
        $protected = [];
        $exact = [];
        $archives = [];
        $complete = true;
        $present = $filenames === null ? null : array_fill_keys($filenames, true);
        $collect = function (Collection $names) use ($run, $history, $present, &$protected, &$exact, &$archives, &$complete): bool {
            $names = $names->pluck('backup_filename')->all();
            /**
             * Preserve raw filename distinctions even when the database collation
             * treats differently cased or accented archive names as equal.
             *
             * @var array<string, BackupRun|null> $references
             */
            $references = [];
            foreach ((clone $history)->whereIn('backup_filename', $names)
                ->select(['id', 'backup_job_id', 'status', 'trigger', 'backup_filename', 'backup_retention_identity'])
                ->lazyById(self::QUERY_BATCH_SIZE) as $reference) {
                $filename = $reference->backup_filename;
                if (isset($exact[$filename]) || ($present !== null && ! isset($present[$filename]))) {
                    continue;
                }
                if (array_key_exists($filename, $references)) {
                    $references[$filename] = null;
                } else {
                    if ($present === null && count($exact) + count($references) >= self::MAX_MANIFEST_FILENAMES) {
                        $complete = false;

                        return false;
                    }
                    $references[$filename] = $reference;
                }
            }
            foreach ($references as $filename => $reference) {
                $exact[$filename] = true;
                if ($reference === null || $reference->backup_job_id !== $run->backup_job_id || $reference->trigger === BackupRun::TRIGGER_PRE_RESTORE || $reference->status !== BackupRun::STATUS_SUCCESS) {
                    $protected[] = $filename;

                    continue;
                }
                if ($reference->backup_retention_identity !== null) {
                    $archives[] = $reference->backup_retention_identity;
                }
            }

            return true;
        };
        if ($filenames === null) {
            $candidates->chunk(self::QUERY_BATCH_SIZE, $collect);
        } else {
            foreach (array_chunk(array_values(array_unique($filenames)), self::QUERY_BATCH_SIZE) as $batch) {
                $collect((clone $candidates)->whereIn('backup_filename', $batch)->get());
            }
        }

        return $complete
            ? ['complete' => true, 'archives' => $archives, 'protected_filenames' => $protected, 'exact_filenames' => array_keys($exact)]
            : ['complete' => false, 'archives' => [], 'protected_filenames' => [], 'exact_filenames' => []];
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
            return $this->identitiesMatch($this->identity($object), $identity);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public function identitiesMatch(array $left, array $right): bool
    {
        foreach (['key', 'filename', 'size', 'modified'] as $field) {
            if (! array_key_exists($field, $left) || ! array_key_exists($field, $right) || $left[$field] !== $right[$field]) {
                return false;
            }
        }

        return true;
    }
}
