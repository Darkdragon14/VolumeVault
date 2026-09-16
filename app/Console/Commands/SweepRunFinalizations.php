<?php

namespace App\Console\Commands;

use App\Actions\Runs\ProcessRunFinalization;
use Illuminate\Console\Command;

class SweepRunFinalizations extends Command
{
    protected $signature = 'volumevault:sweep-run-finalizations';

    protected $description = 'Dispatch due and stale run finalization work';

    public function handle(ProcessRunFinalization $processFinalization): int
    {
        $count = $processFinalization->dispatchDue();
        $this->info("Dispatched {$count} run finalization(s).");

        return self::SUCCESS;
    }
}
