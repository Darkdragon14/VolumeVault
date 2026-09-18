<?php

namespace App\Services\Agents;

use Symfony\Component\Process\Process;

class AgentOperationSupervisor
{
    private ?Process $process = null;

    private bool $stopping = false;

    public function __construct(private readonly AgentOperationStore $store) {}

    /** @param array<string, mixed> $operation */
    public function accept(array $operation): void
    {
        $this->store->accept($operation);
    }

    public function tick(): void
    {
        if ($this->stopping || $this->process?->isRunning()) {
            return;
        }
        if ($this->process !== null && $this->process->getExitCode() !== 0) {
            $this->process = null;
            throw new AgentStateException('Agent operation recovery is blocked; inspect the persistent operation journal.');
        }
        $this->process = null;
        foreach ($this->store->active() as $operation) {
            if ($operation['phase'] === 'finished') {
                continue;
            }
            $lock = $this->store->workerLock($operation['id']);
            if ($lock === null) {
                return;
            }
            fclose($lock);
            $this->process = $this->makeProcess($operation['id']);
            $this->process->disableOutput();
            $this->process->start();

            return;
        }
    }

    protected function makeProcess(string $id): Process
    {
        // Only the child receives these overrides. Specs never become CLI arguments.
        return new Process([PHP_BINARY, base_path('artisan'), 'volumevault:agent-execute', $id, '--recover', '--no-interaction'], base_path(), [
            'APP_KEY' => $this->store->localKey(),
            'APP_PREVIOUS_KEYS' => '',
            'APP_CONFIG_CACHE' => $this->store->root().'/operations/no-config-cache.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => false,
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'null',
            'VOLUMEVAULT_MODE' => 'hybrid',
            'VOLUMEVAULT_AGENT_STATE_DIRECTORY' => $this->store->root(),
        ], timeout: null);
    }

    /** @return array{id: string, token: string, result: array<string, mixed>}|null */
    public function pendingResult(): ?array
    {
        foreach ($this->store->active() as $operation) {
            if ($operation['phase'] === 'finished') {
                return array_intersect_key($operation, array_flip(['id', 'token', 'result']));
            }
        }

        return null;
    }

    public function acknowledge(string $id): void
    {
        $this->store->acknowledge($id);
    }

    public function activeCount(): int
    {
        return count($this->store->active());
    }

    /** @return array{id: string, token: string}|null */
    public function current(): ?array
    {
        foreach ($this->store->active() as $operation) {
            return ['id' => $operation['id'], 'token' => $operation['token']];
        }

        return null;
    }

    public function stop(): void
    {
        $this->stopping = true;
        // Stopping PHP never authorizes replay; the next worker reconciles Docker.
        $this->process?->stop(1);
        $this->process = null;
    }
}
