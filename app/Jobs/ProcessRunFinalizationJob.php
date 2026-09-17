<?php

namespace App\Jobs;

use App\Actions\Runs\ProcessRunFinalization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ProcessRunFinalizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $runFinalizationId,
        public readonly string $enqueueToken,
        public readonly string $claimToken,
    ) {
        $this->onQueue('metadata');
    }

    public function handle(ProcessRunFinalization $processFinalization): void
    {
        $processFinalization->handle($this->runFinalizationId, $this->enqueueToken, $this->claimToken);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessRunFinalization::class)->failExecution(
            $this->runFinalizationId,
            $this->enqueueToken,
            $this->claimToken,
            $exception ?? new RuntimeException('Finalization queue job failed.'),
        );
    }
}
