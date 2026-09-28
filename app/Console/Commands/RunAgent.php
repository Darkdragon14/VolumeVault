<?php

namespace App\Console\Commands;

use App\Services\Agents\AgentClient;
use App\Services\Agents\AgentLoop;
use App\Services\Agents\AgentOperationSupervisor;
use App\Services\Agents\AgentState;
use Illuminate\Console\Command;

class RunAgent extends Command
{
    protected $signature = 'volumevault:agent {--once : Run one enrollment/heartbeat/inventory cycle}';

    protected $description = 'Run the outbound Docker inventory agent';

    public function handle(): int
    {
        $state = new AgentState;
        $operations = app(AgentOperationSupervisor::class);
        try {
            $state->open();
            $loop = app()->makeWith(AgentLoop::class, ['client' => new AgentClient($state), 'operations' => $operations]);
            if (extension_loaded('pcntl')) {
                $this->trap([SIGTERM, SIGINT], fn () => $loop->stop());
            }

            return $loop->run((bool) $this->option('once'), fn (string $message) => $this->error($message));
        } catch (\Throwable) {
            $this->error('Unable to start agent: check configuration, persisted trust, and exclusive state access.');

            return self::FAILURE;
        } finally {
            $operations->stop();
            $state->close();
        }
    }
}
