<?php

namespace App\Services\Agents;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\RunBackup;
use App\Actions\Docker\CleanupBackupRunSecretFiles;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Restore\RunRestore;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\Logging\AppendRunLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

class AgentOperationRuntime
{
    private bool $errorHooksRegistered = false;

    private AgentOperationRedactor $redactor;

    public function __construct(private readonly AgentOperationStore $store) {}

    /** One execution/recovery pass. False means cleanup or a live helper needs more time. */
    public function handle(string $id): bool
    {
        $lock = $this->store->workerLock($id);
        if ($lock === null) {
            return true;
        }
        $mask = umask(0077);
        try {
            $operation = $this->store->read($id) ?? throw new RuntimeException('Unknown operation.');
            if (in_array($operation['phase'], ['finished', 'acknowledged'], true)) {
                return true;
            }
            $this->isolate($id, $operation['phase'] === 'accepted');
            $redactor = $this->redactor = new AgentOperationRedactor($operation);
            app()->instance(AppendRunLog::class, new AgentOperationRunLog($redactor));
            $this->protectPersistedErrors();
            if ($operation['kind'] === 'archive_export') {
                $helper = $operation['spec']['destination']['provider'] === 'docker_volume'
                    ? \App\Actions\Docker\CleanupDestinationOperationHelper::name($id) : null;
                if ($operation['phase'] !== 'accepted' && $helper !== null && ($operation['helper_name'] ?? null) !== $helper) {
                    return false;
                }
                $this->store->markExecuting($id, $helper);
                try {
                    $result = app(ArchiveRelayRuntime::class)->export($operation, $this->store->directory($id), $operation['phase'] === 'accepted',
                        fn (array $data): array => app(AgentClient::class)->relayTransfer($id, $operation['token'], $data));
                } catch (\App\Exceptions\ArchiveRelayConnectionException) {
                    return false;
                } catch (\Throwable) {
                    if ($helper !== null && ! app(\App\Actions\Docker\CleanupDestinationOperationHelper::class)->handle($id)) {
                        return false;
                    }
                    $result = $this->failure('Archive relay export failed; original archive retained.');
                }
                if ($result === null) {
                    return false;
                }
                $this->store->finish($id, $result);

                return true;
            }
            if ($operation['kind'] === 'destination') {
                $execute = app(\App\Services\BackupDestinations\ExecuteDestinationOperation::class);
                try {
                    if ($operation['phase'] === 'accepted') {
                        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
                        $helper = $operation['spec']['destination']['provider'] === 'docker_volume'
                            ? \App\Actions\Docker\CleanupDestinationOperationHelper::name($id) : null;
                        $this->store->markExecuting($id, $helper);
                        $result = $execute->handle($operation['spec'], $id);
                    } else {
                        if ($operation['spec']['destination']['provider'] === 'docker_volume'
                            && ($operation['helper_name'] ?? null) !== \App\Actions\Docker\CleanupDestinationOperationHelper::name($id)) {
                            return false;
                        }
                        $result = $execute->recover($operation['spec'], $id);
                    }
                } catch (\Throwable) {
                    $result = $execute->recover($operation['spec'], $id);
                }
                if (! $result['cleanup_complete']) {
                    return false;
                }
                $this->store->finish($id, $result);

                return true;
            }
            $relayArchive = null;
            if ($operation['kind'] === 'restore' && isset($operation['spec']['relay']) && ! RestoreRun::where('status', '!=', 'queued')->exists()) {
                try {
                    app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
                    $relayArchive = app(ArchiveRelayRuntime::class)->download($operation['spec']['relay'], $this->store->directory($id),
                        fn (array $data): array => app(AgentClient::class)->relayTransfer($id, $operation['token'], $data));
                } catch (\App\Exceptions\ArchiveRelayConnectionException) {
                    return false;
                } catch (\Throwable) {
                    $this->store->finish($id, $this->failure('Archive relay download failed integrity, storage or local policy checks.'));

                    return true;
                }
            }
            $runClass = $operation['kind'] === 'backup' ? BackupRun::class : RestoreRun::class;
            if ($operation['phase'] === 'accepted') {
                try {
                    app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
                } catch (\Throwable) {
                    $this->store->finish($id, $this->failure());

                    return true;
                }
                $run = $runClass::query()->first() ?? $this->seed($operation);
                $this->store->markExecuting($id);
            } else {
                $run = $runClass::query()->first() ?? throw new RuntimeException('Operation run is missing; recovery required.');
            }
            if ($this->hasNeverStarted($run)) {
                try {
                    app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
                } catch (\Throwable) {
                    $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_message' => 'Operation rejected by agent-local policy.'])->save();
                    $this->store->finish($id, $this->failure());

                    return true;
                }
                try {
                    $operation['kind'] === 'backup'
                        ? app(RunBackup::class)->handle($run, acceptedOperation: true)
                        : app(RunRestore::class)->handle($run, $relayArchive);
                } catch (\Throwable) {
                    // Keep the durable run; recovery owns all subsequent work.
                }
            }
            $run = $runClass::query()->first() ?? throw new RuntimeException('Operation run is missing; recovery required.');
            if (! $this->settle()) {
                return false;
            }
            $run->refresh();
            if ($run instanceof BackupRun && $run->status === 'success') {
                app(RunBackup::class)->recordArchiveMetadata($run->id);
                $run->refresh();
            }
            // Scrub diagnostics, not archive identifiers: a public prefix such as
            // "backups/" may also contain an access-key name and must stay restorable.
            $result = [
                'status' => $run->status === 'success' ? 'success' : 'failed',
                'logs' => $redactor->clean($run->logs),
                'error_message' => $run->status === 'success' ? null : 'Agent operation failed. Review the sanitized operation log.',
                'backup_key' => $run instanceof BackupRun ? $run->backup_key : null,
                'backup_size_bytes' => $run instanceof BackupRun ? $run->backup_size_bytes : null,
                'cleanup_complete' => true,
                'finished_at' => ($run->finished_at ?? now())->toIso8601String(),
                'duration_seconds' => (int) ($run->duration_seconds ?? 0),
            ];
            if ($run instanceof RestoreRun && $redactor->clean($run->target_volume_name) === $run->target_volume_name) {
                $result['target_volume_name'] = $run->target_volume_name;
            }
            if ($run instanceof RestoreRun && ($safetyBackup = $run->preRestoreBackup) !== null) {
                $result['safety_backup'] = [
                    'status' => $safetyBackup->status === 'success' ? 'success' : 'failed',
                    'backup_filename' => $safetyBackup->backup_filename,
                    'backup_key' => $safetyBackup->backup_key,
                    'backup_size_bytes' => $safetyBackup->backup_size_bytes,
                    'duration_seconds' => (int) ($safetyBackup->duration_seconds ?? 0),
                    'error_message' => $safetyBackup->error_message === null ? null : $redactor->clean($safetyBackup->error_message, 1000),
                ];
            }
            $this->store->finish($id, $result);

            return true;
        } finally {
            umask($mask);
            fclose($lock);
        }
    }

    private function hasNeverStarted(BackupRun|RestoreRun $run): bool
    {
        return $run->status === 'queued'
            && $run->started_at === null
            && $run->last_heartbeat_at === null
            && $run->finished_at === null
            && ! $run->docker_container_id
            && ! $run->stopped_container_ids
            && ($run instanceof BackupRun ? ! $run->docker_container_cleanup_pending : (! $run->pre_restore_backup_run_id && ! $run->affected_containers))
            && BackupRun::count() + RestoreRun::count() === 1;
    }

    private function isolate(string $id, bool $initialize): void
    {
        $directory = $this->store->directory($id);
        $database = $directory.'/runtime.sqlite';
        if (! $initialize && (! is_file($database) || filesize($database) === 0)) {
            throw new RuntimeException('Operation database is missing; refusing to replay.');
        }
        $this->store->secureDirectory($directory);
        if (is_link($database)) {
            throw new RuntimeException('Unsafe runtime database.');
        }
        foreach (['storage/app', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $path) {
            $this->store->secureDirectory($directory.'/'.$path);
        }
        if (! is_file($database)) {
            touch($database);
        }
        chmod($database, 0600);
        // Pin global state before changing storage_path; inventory needs no APP_KEY.
        config(['volumevault.agents.client.state_directory' => $this->store->root()]);
        app()->useStoragePath($directory.'/storage');
        foreach (array_keys(config('database.connections', [])) as $name) {
            DB::purge($name);
        }
        config([
            'app.key' => $this->store->localKey(), 'app.previous_keys' => [],
            'volumevault.mode' => 'hybrid',
            'database.default' => 'sqlite',
            'database.connections' => ['sqlite' => [
                'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
                'foreign_key_constraints' => true, 'busy_timeout' => 10000,
                'journal_mode' => 'DELETE', 'synchronous' => 'FULL',
            ]],
            'queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite',
            'cache.default' => 'array', 'session.driver' => 'array',
            'logging.default' => 'null',
            'filesystems.disks.local.root' => storage_path('app/private'),
        ]);
        $encrypter = new Encrypter(base64_decode(substr(config('app.key'), 7)), 'AES-256-CBC');
        app()->instance('encrypter', $encrypter);
        Model::encryptUsing($encrypter);
        Facade::clearResolvedInstance('encrypter');
        app('cache')->forgetDriver();
        app('log')->forgetChannel();
        if ($initialize && Artisan::call('migrate', ['--force' => true, '--no-interaction' => true], new NullOutput) !== 0) {
            throw new RuntimeException('Unable to initialize operation database.');
        }
    }

    private function seed(array $operation): BackupRun|RestoreRun
    {
        return DB::transaction(function () use ($operation): BackupRun|RestoreRun {
            $spec = $operation['spec'];
            $destination = BackupDestination::create([...$spec['destination'], 'docker_host_id' => 1, 'is_active' => true]);
            $jobDestination = $destination;
            if ($operation['kind'] === 'restore' && ($spec['run']['backup_before_overwrite'] ?? false) && isset($spec['safety_destination'])) {
                $jobDestination = BackupDestination::create([...$spec['safety_destination'], 'docker_host_id' => 1, 'is_active' => true]);
            }
            $jobData = $spec['job'];
            if ($operation['kind'] === 'restore' && ($spec['run']['mode'] ?? 'new_volume') !== 'new_volume') {
                // Safety backups protect the target on this host, not A's source.
                $jobData['source_type'] = 'docker_volume';
                $jobData['volume_name'] = $spec['run']['target_volume_name'];
                $jobData['host_path'] = null;
            }
            $job = BackupJob::create([
                ...$jobData, 'docker_host_id' => 1, 'backup_destination_id' => $jobDestination->id,
                'status' => 'active', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'],
                'timezone' => $jobData['timezone'] ?? 'UTC',
                'notifications_enabled' => false, 'alert_notifications_enabled' => false,
                'backup_filename_template' => 'agent-'.$operation['id'].'-run-{id}',
            ]);
            if ($operation['kind'] === 'backup') {
                $run = app(CreateBackupRunRecord::class)->handle($job, ['status' => 'queued', 'trigger' => 'manual']);
                $run->forceFill(['backup_filename' => $spec['run']['backup_filename']])->save();

                return $run;
            }

            return RestoreRun::create([
                ...array_intersect_key($spec['run'], array_flip(['selected_backup_key', 'source_volume_name', 'target_volume_name', 'mode', 'backup_before_overwrite', 'confirmation_text'])),
                'mode' => $spec['run']['mode'] ?? 'new_volume',
                'backup_job_id' => $job->id, 'backup_destination_id' => $destination->id,
                'source_docker_host_id' => 1, 'target_docker_host_id' => 1, 'status' => 'queued',
            ]);
        });
    }

    private function settle(): bool
    {
        $runs = BackupRun::all()->concat(RestoreRun::all());
        foreach ($runs as $run) {
            if ($run->docker_container_id && app(ContainerIsAlive::class)->handle($run->docker_container_id) !== false) {
                return false;
            }
        }
        // No worker or helper is active. Removal is idempotent and must succeed
        // before reconciliation may restart application containers.
        foreach ($runs as $run) {
            if ($run->docker_container_id) {
                app(RemoveDockerContainer::class)->handle($run->docker_container_id);
            }
            if ($run instanceof BackupRun) {
                app(CleanupBackupRunSecretFiles::class)->handle($run);
                $run->forceFill(['docker_container_cleanup_pending' => false])->save();
            }
        }
        Artisan::call('volumevault:reconcile-stale-runs', ['--minutes' => 1], new NullOutput);
        foreach (BackupRun::all()->concat(RestoreRun::all()) as $run) {
            if (in_array($run->status, ['queued', 'running'], true) || $run->stopped_container_ids || ($run instanceof BackupRun && $run->docker_container_cleanup_pending)) {
                return false;
            }
        }

        return true;
    }

    private function protectPersistedErrors(): void
    {
        if ($this->errorHooksRegistered) {
            return;
        }
        $this->errorHooksRegistered = true;
        foreach ([BackupRun::class, RestoreRun::class, BackupJob::class, ActivityLog::class] as $class) {
            $class::saving(function (Model $model): void {
                foreach (['logs', 'error_message', 'last_error', 'message'] as $field) {
                    $value = $model->getAttributes()[$field] ?? null;
                    if (is_string($value)) {
                        $model->setAttribute($field, $this->redactor->clean($value));
                    }
                }
                if ($model instanceof ActivityLog && $model->context) {
                    $context = $model->context;
                    array_walk_recursive($context, function (&$value): void {
                        if (is_string($value)) {
                            $value = $this->redactor->clean($value);
                        }
                    });
                    $model->context = $context;
                }
            });
        }
    }

    private function failure(string $message = 'Operation rejected by agent-local policy.'): array
    {
        return ['status' => 'failed', 'logs' => '', 'error_message' => $message, 'backup_key' => null, 'backup_size_bytes' => null, 'cleanup_complete' => true, 'finished_at' => now()->toIso8601String(), 'duration_seconds' => 0];
    }
}
