<?php

namespace App\Jobs;

use App\Actions\Docker\CleanupDestinationOperationHelper;
use App\Models\AgentOperation;
use App\Models\DockerHost;
use App\Services\BackupDestinations\DestinationOperations;
use App\Services\BackupDestinations\ExecuteDestinationOperation;
use App\Support\DeploymentMode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RunDestinationOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public const RECOVERY_MINUTES = 15;

    public function __construct(public string $operationId) {}

    public function handle(DestinationOperations $operations, ExecuteDestinationOperation $execute): void
    {
        $claim = DB::transaction(function (): ?array {
            $host = DockerHost::query()->lockForUpdate()->findOrFail(DockerHost::LOCAL_ID);
            $operation = AgentOperation::whereKey($this->operationId)->where('kind', 'destination')->where('docker_host_id', $host->id)->lockForUpdate()->first();
            if (! $operation || ! in_array($operation->status, ['pending', 'running'], true)) {
                return null;
            }
            $recovery = $operation->status === 'running';
            if ($recovery && $operation->last_progress_at?->gt(now()->subMinutes(self::RECOVERY_MINUTES))) {
                return null;
            }
            $hostBound = in_array($operation->payload['destination']['provider'], ['local', 'docker_volume'], true);
            if (! $recovery && $hostBound && ($host->maintenance_requested_at !== null || ! DeploymentMode::localExecutionEnabled())) {
                return null;
            }
            $context = $operation->context;
            if ($operation->payload['destination']['provider'] === 'docker_volume') {
                if ($recovery && ($context['helper_name'] ?? null) !== CleanupDestinationOperationHelper::name($operation->id)) {
                    return null;
                }
                $context['helper_name'] = CleanupDestinationOperationHelper::name($operation->id);
            }
            $operation->forceFill(['status' => 'running', 'claimed_at' => $operation->claimed_at ?? now(), 'last_progress_at' => now(), 'context' => $context])->save();

            return [$operation, $recovery];
        }, attempts: 3);
        if ($claim === null) {
            return;
        }
        [$operation, $recovery] = $claim;
        $result = $recovery ? $execute->recover($operation->payload, $operation->id) : $execute->handle($operation->payload, $operation->id);
        if (! $result['cleanup_complete']) {
            $operation->forceFill(['last_progress_at' => now()->subMinutes(self::RECOVERY_MINUTES)])->save();

            return;
        }
        DB::transaction(fn () => $operations->complete($operation, $result));
    }

    public function failed(?\Throwable $exception): void
    {
        // The durable dispatch sweep recovers the expired claim and confirms
        // helper absence. A timeout alone is never a cleanup receipt.
    }
}
