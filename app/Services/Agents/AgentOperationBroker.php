<?php

namespace App\Services\Agents;

use App\Actions\Backup\ApplyPendingDockerLabelReconciliation;
use App\Actions\Runs\CreateRunFinalizations;
use App\Actions\Runs\ProcessRunFinalization;
use App\Models\ActivityLog;
use App\Models\AgentOperation;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AgentOperationBroker
{
    public function __construct(private readonly AgentRegistry $registry, private readonly DispatchAgentOperation $dispatch) {}

    /** One non-expiring assignment per host: a lost connection never authorizes replay elsewhere. */
    public function pull(DockerHost $host): ?array
    {
        return DB::transaction(function () use ($host): ?array {
            $locked = DockerHost::query()->lockForUpdate()->find($host->id);
            $this->registry->assertCredential($locked, $host->agent_token_hash, $host->agent_instance_id);
            $active = AgentOperation::where('docker_host_id', $host->id)->where('status', 'running')->first();
            if ($active) {
                return $active->owner_instance_id === $locked->agent_instance_id ? $this->envelope($active) : null;
            }
            if ($locked->maintenance_requested_at !== null || ($locked->agent_active_operations ?? 0) > 0
                || app(AgentCompatibility::class)->status($locked) !== 'compatible') {
                return null;
            }
            $operation = AgentOperation::where('docker_host_id', $host->id)->where('status', 'pending')
                ->where(fn ($query) => $query->whereIn('kind', app(AgentExecution::class)->supportsHost($locked, 'destination-v1') ? ['restore', 'destination'] : ['restore'])
                    ->orWhere('kind', 'archive_export')
                    ->orWhereHas('backupRun.job', fn ($jobs) => $jobs->where('status', '!=', BackupJob::STATUS_PAUSED)))
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if (! $operation) {
                return null;
            }
            if (in_array($operation->kind, ['destination', 'archive_export'], true)) {
                $capability = match ($operation->destination_action) {
                    'host_key' => 'sftp-host-key-v1',
                    'metadata' => 'archive-metadata-v1',
                    default => $operation->kind === 'archive_export' ? 'archive-relay-v1' : 'destination-v1',
                };
                if (! app(AgentExecution::class)->supportsHost($locked, $capability)) {
                    $operation->update(['status' => 'cancelled', 'completed_at' => now(), 'payload' => null,
                        'result' => ['status' => 'failed', 'error_message' => 'Agent does not support '.$capability.'.']]);

                    return null;
                }
                if (! app(AgentExecution::class)->supportsHost($locked, $operation->kind === 'archive_export' ? 'archive-relay-v1' : 'destination-v1')) {
                    return null;
                }
                $operation->forceFill([
                    'status' => 'running', 'delivery_token' => bin2hex(random_bytes(32)),
                    'owner_instance_id' => $locked->agent_instance_id, 'claimed_at' => now(), 'last_progress_at' => now(),
                ])->save();
                if ($operation->kind === 'archive_export') {
                    \App\Models\ArchiveRelay::where('source_agent_operation_id', $operation->id)->where('status', 'pending')->update(['status' => 'exporting']);
                }

                return $this->envelope($operation);
            }
            app(AgentExecution::class)->validateHost($locked->id, $operation->kind === 'backup' ? 'backup-v1' : 'restore-v1');
            $run = $operation->kind === 'backup' ? $operation->backupRun : $operation->restoreRun;
            if (! $run || $run->status !== 'queued') {
                $operation->update(['status' => 'cancelled', 'payload' => null]);

                return null;
            }
            $job = BackupJob::query()->lockForUpdate()->findOrFail($run->backup_job_id);
            if ($run instanceof BackupRun && $job->status === BackupJob::STATUS_PAUSED) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            try {
                $payload = $this->dispatch->specification($run);
                app(AgentOperationSpecification::class)->validate(['id' => $operation->id, 'token' => $token, 'kind' => $operation->kind, 'spec' => $payload]);
            } catch (QueryException $exception) {
                throw $exception;
            } catch (ValidationException|RuntimeException) {
                $this->rejectPending($operation, $run, $job);

                return null;
            }
            if (app(HostWorkAdmission::class)->claim($run, ['started_at' => now(), 'last_heartbeat_at' => now()]) !== 1) {
                return null;
            }
            if ($run instanceof BackupRun) {
                $job->forceFill(['status' => BackupJob::STATUS_RUNNING, 'last_run_at' => now()])->save();
                $ids = app(CreateRunFinalizations::class)->createBackupStartNotifications($run, $job);
                DB::afterCommit(fn () => app(ProcessRunFinalization::class)->dispatch($ids));
            } elseif ($run instanceof RestoreRun) {
                $ids = app(CreateRunFinalizations::class)->createRestoreNotifications($run, $job, started: true);
                DB::afterCommit(fn () => app(ProcessRunFinalization::class)->dispatch($ids));
            }
            $operation->forceFill([
                'status' => 'running', 'payload' => $payload, 'delivery_token' => $token,
                'context' => $run instanceof BackupRun || ! $run->backup_before_overwrite ? [] : [
                    'safety_destination' => [
                        'id' => $job->destination->id, 'name' => $job->destination->name,
                        'provider' => $job->destination->provider, 'locator_fingerprint' => $job->destination->locatorFingerprint(),
                    ],
                ],
                'owner_instance_id' => $locked->agent_instance_id, 'claimed_at' => now(), 'last_progress_at' => now(),
            ])->save();
            if ($run instanceof RestoreRun && ($relay = $run->archiveRelay) !== null) {
                $operation->update(['context' => [...($operation->context ?? []), 'archive_relay_id' => $relay->id]]);
            }
            ActivityLog::record('agent_operation_assigned', 'Operation assigned to its Docker agent.', $run);

            return $this->envelope($operation);
        }, attempts: 3);
    }

    public function progress(DockerHost $host, string $id, string $token): void
    {
        DB::transaction(function () use ($host, $id, $token): void {
            $operation = $this->lockedOperation($host, $id, $token);
            if ($operation->status !== 'running') {
                return;
            }
            $operation->update(['last_progress_at' => now()]);
            if (in_array($operation->kind, ['destination', 'archive_export'], true)) {
                return;
            }
            $run = $operation->kind === 'backup' ? $operation->backupRun : $operation->restoreRun;
            $run?->newQuery()->whereKey($run->id)->where('status', 'running')->update(['last_heartbeat_at' => now()]);
        });
    }

    public function complete(DockerHost $host, string $id, string $token, array $result): void
    {
        unset($result['_verified_relay']);
        $export = DB::transaction(function () use ($host, $id, $token, $result): ?AgentOperation {
            $operation = $this->lockedOperation($host, $id, $token);
            if ($operation->kind !== 'archive_export' || $operation->status === 'completed') {
                return null;
            }
            abort_unless($operation->status === 'running' && $result['cleanup_complete'] === true, 409, 'Operation is not ready to finish.');

            return $operation;
        });
        if ($export !== null) {
            $result = app(ArchiveRelays::class)->verifyExport($export, $result);
        }
        $finalizations = DB::transaction(function () use ($host, $id, $token, $result): array {
            $operation = $this->lockedOperation($host, $id, $token);
            if ($operation->status === 'completed') {
                return [];
            }
            abort_unless($operation->status === 'running' && $result['cleanup_complete'] === true, 409, 'Operation is not ready to finish.');
            if ($operation->kind === 'archive_export') {
                abort_if(array_key_exists('data', $result), 422, 'Unexpected archive export result.');
                app(ArchiveRelays::class)->completeExport($operation, $result);

                return [];
            }
            if ($operation->kind === 'destination') {
                app(\App\Services\BackupDestinations\DestinationOperations::class)->complete($operation, $result);

                return [];
            }
            abort_if(array_key_exists('data', $result), 422, 'Unexpected destination result.');
            $run = $operation->kind === 'backup' ? $operation->backupRun : $operation->restoreRun;
            abort_unless($run && $run->status === 'running', 409, 'Operation run is not active.');
            $job = BackupJob::query()->lockForUpdate()->findOrFail($run->backup_job_id);
            $redactor = new AgentOperationRedactor($this->envelope($operation));
            $status = $result['status'];
            $error = $status === 'success' ? null : $redactor->clean($result['error_message'] ?? 'Agent operation failed.');
            if ($run instanceof RestoreRun && $run->backup_before_overwrite && $status === 'success') {
                abort_unless(($result['safety_backup']['status'] ?? null) === 'success', 422, 'A successful safety backup receipt is required.');
            }
            if (isset($result['safety_backup'])) {
                abort_unless($run instanceof RestoreRun && $run->backup_before_overwrite, 422, 'Unexpected safety backup.');
                $safety = $result['safety_backup'];
                // Resource identity comes from the assignment; a diagnostic name
                // in the worker receipt may have been redacted as a secret substring.
                $destination = $operation->context['safety_destination'];
                $safetyRun = BackupRun::create([
                    'backup_job_id' => $job->id, 'docker_host_id' => $run->target_docker_host_id,
                    'initiated_by_user_id' => $run->initiated_by_user_id,
                    'trigger' => BackupRun::TRIGGER_PRE_RESTORE, 'status' => $safety['status'],
                    'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME, 'source_volume_name' => $run->target_volume_name,
                    'backup_destination_id_snapshot' => $destination['id'], 'backup_destination_name' => $destination['name'],
                    'backup_destination_provider' => $destination['provider'], 'backup_destination_locator_fingerprint' => $destination['locator_fingerprint'],
                    'backup_filename' => $safety['backup_filename'],
                    'backup_key' => $safety['backup_key'] ?? null,
                    'backup_size_bytes' => $safety['backup_size_bytes'] ?? null,
                    'duration_seconds' => $safety['duration_seconds'], 'finished_at' => now(),
                    'error_message' => isset($safety['error_message']) ? $redactor->clean($safety['error_message']) : null,
                ]);
                $run->pre_restore_backup_run_id = $safetyRun->id;
            }
            $run->forceFill([
                'status' => $status, 'finished_at' => now(), 'last_heartbeat_at' => now(),
                'duration_seconds' => $result['duration_seconds'],
                'logs' => $redactor->clean(($run instanceof RestoreRun && $run->archiveRelay !== null ? $run->logs."\n" : '').($result['logs'] ?? '')), 'error_message' => $error,
                'stopped_container_ids' => null,
            ]);
            if ($run instanceof BackupRun) {
                $run->forceFill([
                    'backup_key' => $result['backup_key'] ?? null,
                    'backup_size_bytes' => $result['backup_size_bytes'] ?? null,
                    'docker_container_cleanup_pending' => false, 'archive_metadata_pending' => false,
                ]);
                $job->forceFill([
                    'status' => $job->status === BackupJob::STATUS_PAUSED ? $job->status : ($status === 'success' ? BackupJob::STATUS_ACTIVE : BackupJob::STATUS_ERROR),
                    'last_success_at' => $status === 'success' ? now() : $job->last_success_at,
                    'last_error' => $error, 'last_error_at' => $error === null ? null : now(),
                ])->save();
            } elseif ($status === 'success') {
                abort_if(isset($result['target_volume_name']) && $result['target_volume_name'] !== $run->target_volume_name, 422, 'Unexpected restore target.');
                DockerVolume::updateOrCreate(['docker_host_id' => $run->target_docker_host_id, 'name' => $run->target_volume_name], ['exists' => true, 'last_seen_at' => now()]);
            }
            $run->save();
            $creator = app(CreateRunFinalizations::class);
            $ids = $run instanceof BackupRun ? $creator->createBackupNotifications($run, $job) : $creator->createRestoreNotifications($run, $job);
            if ($run instanceof BackupRun && $status === 'success' && ($run->backup_key === null || $run->backup_size_bytes === null)) {
                $metadata = $creator->createMetadata($run);
                $metadata->update([
                    'remote_metadata_payload' => ['version' => 1, 'action' => 'metadata', 'limit' => 1,
                        'destination' => $operation->payload['destination'],
                        'archive' => ['filename' => $operation->payload['run']['backup_filename'], 'key' => $run->backup_key, 'size' => $run->backup_size_bytes]],
                    'context' => ['docker_host_id' => $operation->docker_host_id],
                ]);
                $run->update(['archive_metadata_pending' => true]);
                $ids[] = $metadata->id;
            }
            $operation->forceFill(['status' => 'completed', 'completed_at' => now(), 'payload' => null])->save();
            ActivityLog::record('agent_operation_finished', 'Agent operation finished: '.$status.'.', $run);

            return $ids;
        }, attempts: 3);
        app(ProcessRunFinalization::class)->dispatch($finalizations);
        $operation = AgentOperation::where('docker_host_id', $host->id)->findOrFail($id);
        if (in_array($operation->kind, ['destination', 'archive_export'], true)) {
            return;
        }
        $job = ($operation->kind === 'backup' ? $operation->backupRun : $operation->restoreRun)?->job;
        if ($job) {
            app(ApplyPendingDockerLabelReconciliation::class)->handle($job);
        }
    }

    private function lockedOperation(DockerHost $host, string $id, string $token): AgentOperation
    {
        $locked = DockerHost::query()->lockForUpdate()->find($host->id);
        $this->registry->assertCredential($locked, $host->agent_token_hash, $host->agent_instance_id);
        $operation = AgentOperation::where('docker_host_id', $locked->id)->whereKey($id)->lockForUpdate()->first();
        abort_unless($operation && is_string($operation->delivery_token) && hash_equals($operation->delivery_token, $token), 404);
        if ($operation->kind === 'archive_export' || isset($operation->context['archive_relay_id'])
            || ($operation->kind === 'restore' && $operation->restoreRun?->archiveRelay !== null)) {
            abort_unless($operation->owner_instance_id === $locked->agent_instance_id, 404);
        }

        return $operation;
    }

    private function rejectPending(AgentOperation $operation, BackupRun|RestoreRun $run, BackupJob $job): void
    {
        $message = 'Remote operation configuration is no longer executable. Review the source and destination settings.';
        $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error_message' => $message])->save();
        if ($run instanceof BackupRun) {
            $job->forceFill(['status' => BackupJob::STATUS_ERROR, 'last_error' => $message, 'last_error_at' => now()])->save();
        }
        $creator = app(CreateRunFinalizations::class);
        $ids = $run instanceof BackupRun ? $creator->createBackupNotifications($run, $job) : $creator->createRestoreNotifications($run, $job);
        $operation->update(['status' => 'cancelled', 'completed_at' => now(), 'payload' => null]);
        DB::afterCommit(fn () => app(ProcessRunFinalization::class)->dispatch($ids));
    }

    private function envelope(AgentOperation $operation): array
    {
        return ['id' => $operation->id, 'token' => $operation->delivery_token, 'kind' => $operation->kind, 'spec' => $operation->payload];
    }
}
