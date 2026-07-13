<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Docker\SyncDockerVolumes;
use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\Logging\AppendRunLog;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandCompletionController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request, AgentCommand $agentCommand, AppendRunLog $appendRunLog, SyncDockerVolumes $syncDockerVolumes, BackupScheduleCalculator $scheduleCalculator): JsonResponse
    {
        $host = $this->agentHost($request);
        abort_unless($agentCommand->host_id === $host->id, 403);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([AgentCommand::STATUS_COMPLETED, AgentCommand::STATUS_FAILED])],
            'logs' => ['nullable', 'string', 'max:20000'],
            'error' => ['nullable', 'string', 'max:2000'],
            'volumes' => ['nullable', 'array'],
            'volumes.*.name' => ['required_with:volumes', 'string', 'max:255'],
            'volumes.*.driver' => ['nullable', 'string', 'max:255'],
            'volumes.*.mountpoint' => ['nullable', 'string'],
            'volumes.*.labels' => ['nullable', 'array'],
            'volumes.*.options' => ['nullable', 'array'],
        ]);

        $finishedAt = now();
        $runStatus = $data['status'] === AgentCommand::STATUS_COMPLETED
            ? BackupRun::STATUS_SUCCESS
            : BackupRun::STATUS_FAILED;

        if ($agentCommand->backupRun) {
            $backupRun = $agentCommand->backupRun;
            $job = $backupRun->job;
            $appendRunLog->handle($agentCommand->backupRun, $data['logs'] ?? null);
            $backupRun->forceFill([
                'status' => $runStatus,
                'finished_at' => $finishedAt,
                'duration_seconds' => $backupRun->started_at?->diffInSeconds($finishedAt),
                'error_message' => $data['error'] ?? null,
            ])->save();

            if ($job) {
                $job->forceFill([
                    'status' => $runStatus === BackupRun::STATUS_SUCCESS ? BackupJob::STATUS_ACTIVE : BackupJob::STATUS_ERROR,
                    'last_success_at' => $runStatus === BackupRun::STATUS_SUCCESS ? $finishedAt : $job->last_success_at,
                    'last_error' => $runStatus === BackupRun::STATUS_SUCCESS ? null : ($data['error'] ?? 'Agent backup failed.'),
                    'next_run_at' => $scheduleCalculator->nextRunAt($job->schedule_type, $job->schedule_config ?? [], $finishedAt),
                ])->save();
            }
        }

        if ($agentCommand->restoreRun) {
            $restoreRun = $agentCommand->restoreRun;
            $appendRunLog->handle($agentCommand->restoreRun, $data['logs'] ?? null);
            $restoreRun->forceFill([
                'status' => $runStatus === BackupRun::STATUS_SUCCESS ? RestoreRun::STATUS_SUCCESS : RestoreRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => $restoreRun->started_at?->diffInSeconds($finishedAt),
                'error_message' => $data['error'] ?? null,
            ])->save();
        }

        if ($agentCommand->type === AgentCommand::TYPE_SYNC_VOLUMES && $data['status'] === AgentCommand::STATUS_COMPLETED) {
            $syncDockerVolumes->applyVolumeList($host, $data['volumes'] ?? []);
        }

        $agentCommand->forceFill([
            'status' => $data['status'],
            'lease_until' => null,
            'last_error' => $data['error'] ?? null,
        ])->save();

        return response()->json(['data' => [
            'id' => $agentCommand->id,
            'status' => $agentCommand->status,
        ]]);
    }
}
