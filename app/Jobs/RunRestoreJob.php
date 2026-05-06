<?php

namespace App\Jobs;

use App\Actions\Restore\RunRestore;
use App\Models\RestoreRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0;

    public function __construct(public readonly int $restoreRunId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('restore-run-'.$this->restoreRunId))->expireAfter(86400)];
    }

    public function handle(RunRestore $runRestore): void
    {
        $runRestore->handle(RestoreRun::findOrFail($this->restoreRunId));
    }
}
