<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExportLocalArchiveRelay implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(public readonly string $relayId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \App\Services\Docker\LocalDockerExecution::assertHost(1);
        $relay = \App\Models\ArchiveRelay::findOrFail($this->relayId);
        if ($relay->source_docker_host_id !== 1 || $relay->cleaned_at !== null) {
            return;
        }
        $directory = app(\App\Services\Agents\ArchiveRelayStorage::class)->directory($relay->id).'/source';
        app(\App\Services\Agents\ArchiveRelayStorage::class)->secureDirectory($directory);
        if (is_link($directory.'/worker.lock')) {
            throw new \RuntimeException('Unsafe relay worker lock.');
        }
        $lock = fopen($directory.'/worker.lock', 'c');
        chmod($directory.'/worker.lock', 0600);
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            $operation = \Illuminate\Support\Facades\DB::transaction(function () use ($relay): ?\App\Models\AgentOperation {
                $host = \App\Models\DockerHost::query()->lockForUpdate()->findOrFail(1);
                $operation = $relay->sourceAgentOperation()->lockForUpdate()->firstOrFail();
                if (! in_array($operation->status, ['pending', 'running'], true)) {
                    return null;
                }
                if ($operation->status === 'running' && ($operation->context['helper_name'] ?? null) !== \App\Actions\Docker\CleanupDestinationOperationHelper::name($operation->id)) {
                    return null;
                }
                if ($operation->status === 'pending') {
                    if ($host->maintenance_requested_at !== null
                        || \App\Models\AgentOperation::where('docker_host_id', 1)->where('status', 'running')->exists()) {
                        return null;
                    }
                    $operation->update(['status' => 'running', 'claimed_at' => now(),
                        'context' => ['helper_name' => \App\Actions\Docker\CleanupDestinationOperationHelper::name($operation->id)]]);
                    $relay->update(['status' => 'exporting']);
                    $operation->setAttribute('initialize_export', true);
                }

                return $operation;
            });
            if ($operation === null) {
                return;
            }
            $mask = umask(0077);
            try {
                $result = app(\App\Services\Agents\ArchiveRelayRuntime::class)->export(
                    ['id' => $operation->id, 'kind' => 'archive_export', 'spec' => $operation->payload],
                    $directory, (bool) $operation->getAttribute('initialize_export'),
                    function (array $data) use ($relay): array {
                        return ['offset' => app(\App\Services\Agents\ArchiveRelayStorage::class)->upload($relay, $data['offset'], base64_decode($data['chunk'], true), $data['size_bytes'], $data['sha256'])];
                    },
                );
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $cleaned = $operation->payload['destination']['provider'] !== 'docker_volume'
                    || app(\App\Actions\Docker\CleanupDestinationOperationHelper::class)->handle($operation->id);
                $result = $cleaned ? ['status' => 'failed', 'cleanup_complete' => true, 'logs' => 'Archive relay expired or rejected a chunk.',
                    'duration_seconds' => 0, 'finished_at' => now()->toIso8601String()] : null;
            } finally {
                umask($mask);
            }
            if ($result !== null) {
                $result = app(\App\Services\Agents\ArchiveRelays::class)->verifyExport($operation, $result);
                \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\Agents\ArchiveRelays::class)->completeExport($operation->fresh(), $result));
            }
        } finally {
            fclose($lock);
        }
    }

    public function failed(\Throwable $exception): void
    {
        \App\Models\ArchiveRelay::whereKey($this->relayId)->whereNull('cleaned_at')->whereIn('status', ['pending', 'exporting'])
            ->update(['error_message' => 'Local archive export was interrupted; durable recovery is pending.']);
    }
}
