<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AgentOperation;
use App\Models\DockerHost;
use App\Services\Agents\ReconcileAgentOperations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveAgentCleanup extends Command
{
    protected $signature = 'volumevault:resolve-agent-cleanup {host : Docker host ID} {--force : Confirm that all old workers/helpers are stopped and application containers recovered on this host}';

    protected $description = 'Clear cancelled remote operation cleanup fences after manual Docker recovery';

    public function handle(ReconcileAgentOperations $recovery): int
    {
        $this->warn('This does not stop remote Docker containers. Verify all old workers/helpers are stopped and application containers recovered before clearing the fence.');
        $host = DockerHost::find($this->argument('host'));
        if (! $this->option('force') || $host === null || $host->isLocal()) {
            $this->error('A remote host and explicit --force cleanup confirmation are required.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($host, $recovery): int {
            $host = DockerHost::query()->lockForUpdate()->findOrFail($host->id);
            if ($host->maintenance_requested_at === null
                || AgentOperation::where('docker_host_id', $host->id)->where('status', 'running')->exists()) {
                $this->error('Request host maintenance and wait for all current assignments to finish before confirming old cleanup.');

                return self::FAILURE;
            }
            foreach (AgentOperation::where('docker_host_id', $host->id)->where('status', 'cancelled')->lockForUpdate()->get() as $operation) {
                $recovery->acknowledgeCleanup($operation);
            }
            ActivityLog::record('agent_cleanup_confirmed', 'Administrator confirmed manual cleanup of cancelled agent operations.', $host);
            $this->info('Cancelled operation cleanup confirmed. Resume host maintenance when the agent is connected and compatible.');

            return self::SUCCESS;
        }, attempts: 3);
    }
}
