<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Actions\Docker\SyncDockerVolumes;
use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\Host;
use App\Models\RestoreRun;
use App\Rules\BoundedJsonPayload;
use App\Services\Logging\AppendRunLog;
use App\Services\Scheduling\BackupScheduleCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'lease_request_id' => ['required', 'uuid'],
            'lease_token' => ['required', 'string', 'size:64'],
            'logs' => ['nullable', 'string', 'max:20000'],
            'error' => ['nullable', 'string', 'max:2000'],
            'volumes' => [Rule::requiredIf(fn (): bool => $agentCommand->type === AgentCommand::TYPE_SYNC_VOLUMES && $request->input('status') === AgentCommand::STATUS_COMPLETED), 'array', 'max:5000'],
            'volumes.*' => ['array:name,driver,mountpoint,labels,options'],
            'volumes.*.name' => ['required_with:volumes', 'string', 'max:255', 'distinct'],
            'volumes.*.driver' => ['nullable', 'string', 'max:255'],
            'volumes.*.mountpoint' => ['nullable', 'string', 'max:4096'],
            'volumes.*.labels' => ['nullable', 'array', 'max:100', new BoundedJsonPayload(maxDepth: 2, maxNodes: 101, maxKeyLength: 255, maxStringLength: 4096, maxBytes: 65536)],
            'volumes.*.labels.*' => ['nullable', 'string', 'max:4096'],
            'volumes.*.options' => ['nullable', 'array', 'max:100', new BoundedJsonPayload(maxDepth: 2, maxNodes: 101, maxKeyLength: 255, maxStringLength: 4096, maxBytes: 65536)],
            'volumes.*.options.*' => ['nullable', 'string', 'max:4096'],
        ]);

        $agentCommand = DB::transaction(function () use ($agentCommand, $host, $data, $appendRunLog, $syncDockerVolumes, $scheduleCalculator): AgentCommand {
            $command = AgentCommand::query()
                ->whereKey($agentCommand->id)
                ->where('host_id', $host->id)
                ->where('lease_request_id', $data['lease_request_id'])
                ->where('lease_token_hash', hash('sha256', $data['lease_token']))
                ->lockForUpdate()
                ->first();

            abort_unless($command, 409, 'The command lease is no longer active.');

            if (in_array($command->status, [AgentCommand::STATUS_COMPLETED, AgentCommand::STATUS_FAILED], true)) {
                abort_unless($command->status === $data['status'], 409, 'The command was already completed with a different status.');

                return $command;
            }

            abort_unless(
                $command->status === AgentCommand::STATUS_LEASED
                && $command->lease_until?->isFuture()
                && $host->fresh()->active_agent_command_id === $command->id,
                409,
                'The command lease is no longer active.',
            );

            $finishedAt = now();
            $runStatus = $data['status'] === AgentCommand::STATUS_COMPLETED
                ? BackupRun::STATUS_SUCCESS
                : BackupRun::STATUS_FAILED;

            if ($command->backupRun) {
                $backupRun = $command->backupRun;
                $job = $backupRun->job;
                $appendRunLog->handle($backupRun, $data['logs'] ?? null);
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

            if ($command->restoreRun) {
                $restoreRun = $command->restoreRun;
                $appendRunLog->handle($restoreRun, $data['logs'] ?? null);
                $restoreRun->forceFill([
                    'status' => $runStatus === BackupRun::STATUS_SUCCESS ? RestoreRun::STATUS_SUCCESS : RestoreRun::STATUS_FAILED,
                    'finished_at' => $finishedAt,
                    'duration_seconds' => $restoreRun->started_at?->diffInSeconds($finishedAt),
                    'error_message' => $data['error'] ?? null,
                ])->save();
            }

            if ($command->type === AgentCommand::TYPE_SYNC_VOLUMES && $data['status'] === AgentCommand::STATUS_COMPLETED) {
                $syncDockerVolumes->applyVolumeList($host, $data['volumes']);
                $host->forceFill([
                    'status' => Host::STATUS_ONLINE,
                    'last_error' => null,
                ])->save();
            } elseif ($command->type === AgentCommand::TYPE_SYNC_VOLUMES) {
                $host->forceFill([
                    'status' => Host::STATUS_ERROR,
                    'last_error' => $data['error'] ?? 'Agent volume sync failed.',
                ])->save();
            }

            $command->forceFill([
                'status' => $data['status'],
                'lease_until' => null,
                'last_error' => $data['error'] ?? null,
            ])->save();
            $host->forceFill(['active_agent_command_id' => null])->save();

            return $command;
        }, 3);

        return response()->json(['data' => [
            'id' => $agentCommand->id,
            'status' => $agentCommand->status,
        ]]);
    }
}
