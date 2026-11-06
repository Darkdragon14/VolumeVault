<?php

namespace App\Actions\Backup;

use App\Actions\Docker\CleanupBackupRetentionHelper;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\LocalDockerExecution;
use App\Services\Logging\AppendRunLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PruneBackupArchives
{
    public function __construct(
        private readonly DestinationStorage $storage,
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function handle(BackupRun $run, ?callable $heartbeat = null): void
    {
        if ($run->status !== BackupRun::STATUS_SUCCESS || $run->trigger === BackupRun::TRIGGER_PRE_RESTORE) {
            return;
        }

        $options = $run->execution_options_snapshot ?? [];
        $count = (int) ($options['retention_count'] ?? 0);
        $days = (int) ($options['retention_days'] ?? 0);
        $exact = (bool) ($options['use_exact_filename'] ?? false);
        if ($count < 1 && $days < 1 && ! $exact) {
            return;
        }

        $helperOwned = false;
        try {
            $namespace = $options['archive_namespace'] ?? null;
            $destination = $run->destinationForRun();
            if (! is_string($namespace) || ! Str::isUuid($namespace) || $destination === null
                || $run->backup_destination_locator_fingerprint !== $destination->locatorFingerprint()) {
                throw new RuntimeException;
            }
            LocalDockerExecution::assertHost((int) $run->docker_host_id);
            LocalDockerExecution::assertDestination($destination);

            if ($run->docker_container_cleanup_pending) {
                throw new RuntimeException;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
            if ($destination->provider === BackupDestination::PROVIDER_DOCKER_VOLUME) {
                $name = CleanupBackupRetentionHelper::name($run);
                $run->forceFill(['docker_container_id' => $name, 'docker_container_cleanup_pending' => true])->save();
                $helperOwned = true;
                $this->storage->useOperationHelper($name);
            }
            if ($heartbeat !== null) {
                $this->storage->useOperationProgress($heartbeat);
                $heartbeat();
            }

            $prefix = RenderBackupFilename::archivePrefix($namespace);
            $pattern = '/\A'.preg_quote($prefix, '/').'run-[1-9][0-9]*-.+\.(?:tar\.gz|tar\.zst|tgz|tar)(?:\.(?:gpg|age))?\z/D';
            $listed = collect($this->listAllObjects($destination));
            $retention = app(ExactFilenameRetention::class);
            if ($exact) {
                $current = $listed->filter(fn (array $object): bool => basename((string) ($object['display_name'] ?? $object['key'] ?? '')) === $run->backup_filename);
                if ($current->count() !== 1) {
                    throw new RuntimeException;
                }
                $identity = $retention->identity($current->first());
                if ($run->backup_retention_identity !== null && ! $retention->identitiesMatch($run->backup_retention_identity, $identity)) {
                    throw new RuntimeException;
                }
                $run->forceFill(['backup_retention_identity' => $identity])->save();
            }
            if ($count < 1 && $days < 1) {
                return;
            }
            $manifest = $options['retention_manifest'] ?? $retention->manifest($run, $listed
                ->map(fn (array $object): string => basename((string) ($object['display_name'] ?? $object['key'] ?? '')))->all());
            if (! $manifest['complete']) {
                throw new RuntimeException;
            }
            $identities = collect($manifest['archives'])->keyBy('key');
            $unverified = false;
            $objects = $listed
                ->filter(function (array $object) use ($pattern, $manifest, $identities, $retention, $run, &$unverified): bool {
                    $name = basename((string) ($object['display_name'] ?? $object['key'] ?? ''));
                    if ($name === $run->backup_filename) {
                        return true;
                    }
                    if (in_array($name, $manifest['protected_filenames'], true)) {
                        $unverified = true;

                        return false;
                    }
                    $identity = $identities->get($object['key'] ?? '');
                    if ($identity !== null) {
                        $matches = $retention->matches($object, $identity);
                        $unverified = $unverified || ! $matches;

                        return $matches;
                    }
                    if (in_array($name, $manifest['exact_filenames'], true)) {
                        $unverified = true;

                        return false;
                    }

                    return preg_match($pattern, $name) === 1;
                })
                ->unique('key')
                ->map(function (array $object) use ($run): array {
                    if (empty($object['key']) || empty($object['last_modified'])) {
                        throw new RuntimeException;
                    }

                    return [...$object,
                        'timestamp' => Carbon::parse($object['last_modified'])->getTimestamp(),
                        'current' => basename((string) ($object['display_name'] ?? $object['key'])) === $run->backup_filename,
                    ];
                });

            if ($unverified) {
                $this->appendRunLog->handle($run, 'Retention preserved archives with ambiguous ownership or changed metadata. Exact-name cleanup requires the original run history and unchanged archive metadata.');
            }

            if ($objects->where('current', true)->count() !== 1) {
                throw new RuntimeException;
            }

            $sorted = $objects->sort(function (array $left, array $right): int {
                return ($right['current'] <=> $left['current'])
                    ?: ($right['timestamp'] <=> $left['timestamp'])
                    ?: strcmp($right['key'], $left['key']);
            })->values();
            $cutoff = now()->subDays($days)->getTimestamp();
            $keys = $sorted->filter(fn (array $object, int $index): bool => ! $object['current']
                && (($count > 0 && $index >= $count) || ($days > 0 && $object['timestamp'] < $cutoff)))
                ->pluck('key')->all();

            if ($keys !== []) {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                $this->storage->deleteBackupObjects($destination, $keys);
                $this->appendRunLog->handle($run, 'Retention cleanup removed '.count($keys).' older archive(s) owned by this backup job.');
            }
        } catch (Throwable) {
            $message = 'Retention cleanup could not be completed safely. The uploaded backup remains successful; older archives may remain. Check destination access and archive listing, then run another backup to retry.';
            $this->appendRunLog->handle($run, $message);
            ActivityLog::record('backup_retention_failed', $message, $run, ['backup_job_id' => $run->backup_job_id]);
        } finally {
            if ($heartbeat !== null) {
                $this->storage->useOperationProgress(null);
                $heartbeat();
            }
            if ($helperOwned) {
                $this->storage->useOperationHelper(null);
                $cleanup = fn (): bool => app(CleanupBackupRetentionHelper::class)->handle($run);
                $cleaned = $heartbeat === null ? $cleanup() : app(DockerProcess::class)->whileMonitoring($heartbeat, $cleanup);
                if (! $cleaned) {
                    $this->appendRunLog->handle($run, 'Retention helper cleanup is still pending; operation ownership is retained until removal is confirmed.');
                }
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function listAllObjects(BackupDestination $destination): array
    {
        $objects = [];
        $cursor = null;
        $seen = [];
        do {
            $page = $this->storage->listBackupObjectsPage($destination, $cursor);
            array_push($objects, ...$page['objects']);
            $cursor = $page['next_cursor'];
            if ($cursor !== null) {
                if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                    throw new RuntimeException;
                }
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);

        return $objects;
    }
}
