<?php

namespace App\Console\Commands;

use App\Services\Agents\AgentTlsIdentity;
use Illuminate\Console\Command;
use Throwable;

class PrepareAgentTls extends Command
{
    protected $signature = 'volumevault:agent-tls:prepare';

    protected $description = 'Prepare the private CA and HTTPS certificate for agents';

    /**
     * Execute the console command.
     */
    public function handle(AgentTlsIdentity $identity): int
    {
        if (! config('volumevault.agents.enabled', false)) {
            $this->info('Agent TLS is disabled.');

            return self::SUCCESS;
        }

        try {
            $identity->ensure();
        } catch (Throwable) {
            $this->error('Agent TLS preparation failed. Check the HTTPS origin and private TLS storage.');

            return self::FAILURE;
        }

        $this->info('Agent TLS is ready.');

        return self::SUCCESS;
    }
}
