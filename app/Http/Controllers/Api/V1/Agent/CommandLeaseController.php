<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandLeaseController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request): JsonResponse
    {
        $host = $this->agentHost($request);
        $leaseMinutes = max(1, min(60, (int) $request->integer('lease_minutes', 5)));

        $command = AgentCommand::query()
            ->where('host_id', $host->id)
            ->where(function ($query) {
                $query->where('status', AgentCommand::STATUS_PENDING)
                    ->orWhere(function ($query) {
                        $query->where('status', AgentCommand::STATUS_LEASED)
                            ->where('lease_until', '<', now());
                    });
            })
            ->oldest()
            ->first();

        if (! $command) {
            return response()->json(['data' => null]);
        }

        $command->forceFill([
            'status' => AgentCommand::STATUS_LEASED,
            'lease_until' => now()->addMinutes($leaseMinutes),
            'attempts' => $command->attempts + 1,
            'last_error' => null,
        ])->save();

        return response()->json(['data' => $this->serializeCommand($command->refresh())]);
    }

    /**
     * @return array{id: int, type: string, status: string, payload: array<mixed>, secret_payload: array<mixed>|null, backup_run_id: int|null, restore_run_id: int|null, lease_until: mixed, attempts: int}
     */
    private function serializeCommand(AgentCommand $command): array
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
            'attempts' => $command->attempts,
        ];
    }
}
