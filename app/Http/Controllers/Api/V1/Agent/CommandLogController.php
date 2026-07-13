<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Api\V1\Agent\Concerns\AuthenticatesAgent;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Services\Logging\AppendRunLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandLogController extends Controller
{
    use AuthenticatesAgent;

    public function __invoke(Request $request, AgentCommand $agentCommand, AppendRunLog $appendRunLog): JsonResponse
    {
        $host = $this->agentHost($request);
        abort_unless($agentCommand->host_id === $host->id, 403);

        $data = $request->validate([
            'logs' => ['required', 'string', 'max:20000'],
        ]);

        if ($agentCommand->backupRun) {
            $appendRunLog->handle($agentCommand->backupRun, $data['logs']);
        }

        if ($agentCommand->restoreRun) {
            $appendRunLog->handle($agentCommand->restoreRun, $data['logs']);
        }

        return response()->json(status: 204);
    }
}
