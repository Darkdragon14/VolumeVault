<?php

namespace App\Services\Agents;

use App\Actions\Docker\CollectAgentInventory;
use App\Services\BackupSources\HostPathPolicy;
use Closure;
use RuntimeException;

class AgentLoop
{
    private bool $stopping = false;

    private float $nextInventory = 0;

    private float $nextHeartbeat = 0;

    private bool $singleCycle = false;

    private bool $singleOperationAccepted = false;

    private const HEARTBEAT_INTERVAL_SECONDS = 30;

    private const INVENTORY_INTERVAL_SECONDS = 300;

    private const INVENTORY_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly AgentClient $client,
        private readonly CollectAgentInventory $inventory,
        private readonly HostPathPolicy $hostPaths,
        private readonly ?AgentOperationSupervisor $operations = null,
    ) {}

    public function stop(): void
    {
        $this->stopping = true;
        $this->operations?->stop();
    }

    public function cycle(): bool
    {
        // Recovery/execution continues even when the control plane cannot be reached.
        $this->operations?->tick();
        $this->client->enroll();
        $available = $this->inventory->available();
        $this->heartbeat($available);
        $this->exchangeOperations($available);

        if (! $available || $this->stopping) {
            return false;
        }

        if ($this->monotonicTime() < $this->nextInventory) {
            return true;
        }

        $deadline = $this->monotonicTime() + self::INVENTORY_TIMEOUT_SECONDS;
        $heartbeatFailure = null;
        try {
            $data = $this->inventory->handle(function () use ($deadline, &$heartbeatFailure): void {
                if ($this->stopping || $this->monotonicTime() >= $deadline) {
                    throw new RuntimeException('Agent inventory collection interrupted.');
                }

                if ($this->monotonicTime() >= $this->nextHeartbeat) {
                    try {
                        $this->heartbeat(true);
                    } catch (\Throwable $exception) {
                        $heartbeatFailure = $exception;
                        throw $exception;
                    }
                }
            });
        } catch (\Throwable $exception) {
            if ($heartbeatFailure !== null || $exception instanceof AgentStateException) {
                throw $exception;
            }
            if (! $this->stopping) {
                // Never log Docker output or publish a partial inventory.
                $this->heartbeat(false);
            }

            return false;
        } finally {
            $this->nextInventory = $this->monotonicTime() + self::INVENTORY_INTERVAL_SECONDS;
        }

        if ($this->stopping) {
            return false;
        }
        $this->client->inventory($data['volumes'], $data['containers'], $this->hostPaths->allowedPrefixes(), $data['docker_version'] ?? null);

        return true;
    }

    private function heartbeat(bool $available): void
    {
        $this->client->heartbeat($available, $this->operations?->activeCount() ?? 0);
        if ($operation = $this->operations?->current()) {
            $this->client->operationProgress($operation['id'], $operation['token']);
        }
        $this->nextHeartbeat = $this->monotonicTime() + self::HEARTBEAT_INTERVAL_SECONDS;
    }

    private function exchangeOperations(bool $available): void
    {
        if (! $this->operations) {
            return;
        }
        if ($receipt = $this->operations->pendingResult()) {
            $this->client->completeOperation($receipt);
            $this->operations->acknowledge($receipt['id']);
        }
        if ($available && ! $this->stopping && $this->operations->activeCount() === 0
            && (! $this->singleCycle || ! $this->singleOperationAccepted)) {
            if ($operation = $this->client->pullOperation()) {
                $this->operations->accept($operation);
                $this->singleOperationAccepted = true;
                $this->operations->tick();
            }
        }
    }

    protected function monotonicTime(): float
    {
        return hrtime(true) / 1e9;
    }

    public function run(bool $once, Closure $onError): int
    {
        $this->singleCycle = $once;
        $this->singleOperationAccepted = ($this->operations?->activeCount() ?? 0) > 0;
        $backoff = 1;
        while (! $this->stopping) {
            try {
                $available = $this->cycle();
                if ($this->stopping) {
                    return 0;
                }
                if (! $available) {
                    $onError('Docker inventory unavailable.');
                    if ($once) {
                        return 1;
                    }
                }
                $delay = max(0, $this->nextHeartbeat - $this->monotonicTime());
                $backoff = 1;
            } catch (AgentStateException) {
                $onError('Agent state is unavailable; exiting to reload the persisted identity.');

                return 1;
            } catch (AgentProtocolException) {
                $onError('Agent protocol is incompatible with the orchestrator; install a compatible agent image.');
                if ($once) {
                    return 1;
                }
                $delay = 60;
            } catch (\Throwable) {
                $onError('Agent cycle failed; check connectivity, enrollment, and Docker availability.');
                if ($once) {
                    return 1;
                }
                $delay = $backoff;
                $backoff = min(60, $backoff * 2);
            }
            if ($once && ($this->operations?->activeCount() ?? 0) === 0) {
                return 0;
            }
            $until = hrtime(true) / 1e9 + $delay;
            while (! $this->stopping && hrtime(true) / 1e9 < $until) {
                usleep(100000);
            }
        }

        return 0;
    }
}
