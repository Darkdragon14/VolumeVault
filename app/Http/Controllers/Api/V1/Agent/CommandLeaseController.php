<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommandLeaseController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request): JsonResponse
    {
        $host = $this->agentHost($request);
        $leaseMinutes = max(1, min(60, (int) $request->integer('lease_minutes', 5)));

        $leaseToken = Str::random(64);
        $leaseTokenHash = hash('sha256', $leaseToken);
        $leaseUntil = now()->addMinutes($leaseMinutes);

        $command = DB::transaction(function () use ($host, $leaseTokenHash, $leaseUntil): ?AgentCommand {
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
                ->lockForUpdate()
                ->first();

            if (! $command) {
                return null;
            }

            $claimed = AgentCommand::query()
                ->whereKey($command->id)
                ->where(function ($query) {
                    $query->where('status', AgentCommand::STATUS_PENDING)
                        ->orWhere(function ($query) {
                            $query->where('status', AgentCommand::STATUS_LEASED)
                                ->where('lease_until', '<', now());
                        });
                })
                ->update([
                    'status' => AgentCommand::STATUS_LEASED,
                    'lease_until' => $leaseUntil,
                    'lease_token_hash' => $leaseTokenHash,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return $claimed === 1 ? $command->refresh() : null;
        }, 3);

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
}
