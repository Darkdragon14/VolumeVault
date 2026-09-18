<?php

namespace App\Services\Agents;

use App\Actions\Backup\AdvanceBackupGroupRun;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispatchAgentOperation
{
    public function handle(BackupRun|RestoreRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $run = $run->fresh(['job']);
            if ($run instanceof BackupRun && $run->belongsToGroupRun() && ! AdvanceBackupGroupRun::authorizes($run)) {
                return false;
            }
            $hostId = $run instanceof BackupRun ? $run->docker_host_id : $run->target_docker_host_id;
            if ($hostId === DockerHost::LOCAL_ID || $run->status !== 'queued' || app(HostWorkAdmission::class)->isWaiting($run)) {
                return false;
            }
            app(AgentExecution::class)->validateHost($hostId, $run instanceof BackupRun ? 'backup-v1' : 'restore-v1');
            $column = $run instanceof BackupRun ? 'backup_run_id' : 'restore_run_id';
            AgentOperation::firstOrCreate([$column => $run->id], [
                'id' => (string) Str::uuid(), 'docker_host_id' => $hostId,
                'kind' => $run instanceof BackupRun ? 'backup' : 'restore', 'status' => 'pending',
            ]);

            return true;
        });
    }

    /** Resolve credentials only for an authenticated agent claiming the immutable run. */
    public function specification(BackupRun|RestoreRun $run): array
    {
        $job = $run instanceof BackupRun ? $run->executionJob() : $run->job;
        $destination = $run instanceof BackupRun ? $run->destinationForRun() : $run->destination;
        $hostId = $run instanceof BackupRun ? $run->docker_host_id : $run->target_docker_host_id;
        if (! $destination?->is_active || ($destination->isHostBound() && (int) $destination->docker_host_id !== $hostId)) {
            throw ValidationException::withMessages(['destination' => 'This destination is not available to the selected agent.']);
        }
        $spec = [
            'version' => 1,
            'job' => $job->only([
                'name', 'source_type', 'volume_name', 'host_path', 'retention_days', 'retention_count',
                'backup_filter_mode', 'backup_exclude_regexp', 'backup_include_paths',
                'stop_containers_before_backup', 'stop_container_names', 'timezone',
            ]),
            'destination' => $this->destination($destination),
            'run' => $run instanceof BackupRun ? ['backup_filename' => $run->backup_filename] : $run->only([
                'selected_backup_key', 'source_volume_name', 'target_volume_name', 'mode',
                'backup_before_overwrite', 'confirmation_text',
            ]),
        ];
        $spec['job']['source_type'] = $job->sourceType();
        if ($run instanceof RestoreRun) {
            // A restore acts on its admitted target, not the job's current source.
            // The archive's historical source remains in run.source_volume_name.
            $spec['job']['source_type'] = BackupJob::SOURCE_TYPE_DOCKER_VOLUME;
            $spec['job']['volume_name'] = $run->target_volume_name;
            $spec['job']['host_path'] = null;
        }
        if ($run instanceof RestoreRun && $run->backup_before_overwrite) {
            $safety = $job->destination;
            if (! $safety?->is_active || ($safety->isHostBound() && (int) $safety->docker_host_id !== $hostId)) {
                throw ValidationException::withMessages(['destination' => 'The safety backup destination is unavailable on the target host.']);
            }
            $spec['safety_destination'] = $this->destination($safety);
        }

        return $spec;
    }

    private function destination(BackupDestination $destination): array
    {
        $data = [];
        foreach (['name', 'provider', 'endpoint', 'region', 'bucket', 'path_prefix', 'access_key_id', 'secret_access_key', 'use_path_style_endpoint', 'settings', 'secrets'] as $field) {
            $data[$field] = $destination->getAttribute($field);
        }

        return $data;
    }
}
