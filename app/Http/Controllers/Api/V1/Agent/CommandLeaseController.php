<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\Host;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommandLeaseController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request): JsonResponse
    {
        $host = $this->agentHost($request);
        $data = $request->validate([
            'lease_request_id' => ['required', 'uuid'],
            'lease_token' => ['required', 'string', 'size:64'],
            'lease_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $leaseMinutes = (int) ($data['lease_minutes'] ?? 60);
        $leaseToken = $data['lease_token'];
        $leaseTokenHash = hash('sha256', $leaseToken);
        $leaseUntil = now()->addMinutes($leaseMinutes);

        $command = Cache::lock('volumevault:agent-command-lease:'.$host->id, 10)->block(5, function () use ($host, $data, $leaseTokenHash, $leaseUntil): ?AgentCommand {
            return DB::transaction(function () use ($host, $data, $leaseTokenHash, $leaseUntil): ?AgentCommand {
                $lockedHost = $host->newQuery()->lockForUpdate()->findOrFail($host->id);
                $existingRequest = AgentCommand::query()
                    ->where('host_id', $lockedHost->id)
                    ->where('lease_request_id', $data['lease_request_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existingRequest) {
                    abort_unless(hash_equals((string) $existingRequest->lease_token_hash, $leaseTokenHash), 409, 'The lease request identity does not match.');

                    if (in_array($existingRequest->status, [AgentCommand::STATUS_COMPLETED, AgentCommand::STATUS_FAILED], true)) {
                        return $existingRequest;
                    }

                    abort_unless($lockedHost->active_agent_command_id === $existingRequest->id, 409, 'The command lease is no longer active.');

                    $existingRequest->forceFill(['lease_until' => $leaseUntil])->save();

                    return $existingRequest;
                }

                if ($lockedHost->active_agent_command_id !== null) {
                    $activeCommand = AgentCommand::query()->lockForUpdate()->find($lockedHost->active_agent_command_id);

                    if ($activeCommand?->status === AgentCommand::STATUS_LEASED && $activeCommand->lease_until?->isFuture()) {
                        return null;
                    }

                    if ($activeCommand?->status === AgentCommand::STATUS_LEASED) {
                        return $this->claim($activeCommand, $data['lease_request_id'], $leaseTokenHash, $leaseUntil);
                    }

                    $lockedHost->forceFill(['active_agent_command_id' => null])->save();
                }

                $command = AgentCommand::query()
                    ->where('host_id', $lockedHost->id)
                    ->where('status', AgentCommand::STATUS_PENDING)
                    ->oldest()
                    ->lockForUpdate()
                    ->first();

                if (! $command) {
                    return null;
                }

                $reserved = Host::query()
                    ->whereKey($lockedHost->id)
                    ->whereNull('active_agent_command_id')
                    ->update(['active_agent_command_id' => $command->id]);

                if ($reserved !== 1) {
                    return null;
                }

                return $this->claim($command, $data['lease_request_id'], $leaseTokenHash, $leaseUntil);
            }, 3);
        });

        if (! $command) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->serializeCommand($command, $leaseToken)]);
    }

    /**
     * @return array{id: int, type: string, status: string, payload: array<mixed>, secret_payload: array<mixed>|null, backup_run_id: int|null, restore_run_id: int|null, lease_until: mixed, lease_token: string, attempts: int}
     */
    private function serializeCommand(AgentCommand $command, string $leaseToken): array
    {
        return [
            'id' => $command->id,
            'type' => $command->type,
            'status' => $command->status,
            'payload' => $command->payload ?: [],
            'secret_payload' => $command->secret_payload,
            'backup_run_id' => $command->backup_run_id,
            'restore_run_id' => $command->restore_run_id,
            'lease_until' => $command->lease_until,
            'lease_token' => $leaseToken,
            'attempts' => $command->attempts,
        ];
    }

    private function claim(AgentCommand $command, string $leaseRequestId, string $leaseTokenHash, CarbonInterface $leaseUntil): AgentCommand
    {
        $command->forceFill([
            'status' => AgentCommand::STATUS_LEASED,
            'lease_until' => $leaseUntil,
            'lease_token_hash' => $leaseTokenHash,
            'lease_request_id' => $leaseRequestId,
            'attempts' => $command->attempts + 1,
            'last_error' => null,
        ])->save();

        return $command;
    }
}
