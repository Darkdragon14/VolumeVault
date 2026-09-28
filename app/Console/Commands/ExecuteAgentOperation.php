<?php

namespace App\Console\Commands;

use App\Services\Agents\AgentOperationRuntime;
use Illuminate\Console\Command;

class ExecuteAgentOperation extends Command
{
    protected $signature = 'volumevault:agent-execute {operationUUID} {--recover : Reconcile an interrupted operation without replaying it}';

    protected $description = 'Execute or recover an encrypted agent-local backup/restore operation.';

    public function handle(AgentOperationRuntime $runtime): int
    {
        // The supervisor passes only this local path, never a server APP_KEY/spec.
        if ($root = getenv('VOLUMEVAULT_AGENT_STATE_DIRECTORY')) {
            config(['volumevault.agents.client.state_directory' => $root]);
        }
        try {
            while (! $runtime->handle((string) $this->argument('operationUUID'))) {
                sleep(2);
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Agent operation requires recovery; no operation was replayed.');

            return self::FAILURE;
        }
    }
}
