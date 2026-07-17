<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Services\Logging\AppendRunLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandLogController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request, AgentCommand $agentCommand, AppendRunLog $appendRunLog): JsonResponse
    {
        $host = $this->agentHost($request);
        abort_unless($agentCommand->host_id === $host->id, 403);

        $data = $request->validate([
            'logs' => ['required', 'string', 'max:20000'],
            'lease_request_id' => ['required', 'uuid'],
            'lease_token' => ['required', 'string', 'size:64'],
        ]);

        DB::transaction(function () use ($agentCommand, $host, $data, $appendRunLog): void {
            $command = AgentCommand::query()
                ->whereKey($agentCommand->id)
                ->where('host_id', $host->id)
                ->where('status', AgentCommand::STATUS_LEASED)
                ->where('lease_until', '>=', now())
                ->where('lease_request_id', $data['lease_request_id'])
                ->where('lease_token_hash', hash('sha256', $data['lease_token']))
                ->lockForUpdate()
                ->first();

            abort_unless($command, 409, 'The command lease is no longer active.');

            if ($command->backupRun) {
                $appendRunLog->handle($command->backupRun, $data['logs']);
            }

            if ($command->restoreRun) {
                $appendRunLog->handle($command->restoreRun, $data['logs']);
            }
        }, 3);

        return response()->json(status: 204);
    }
}
