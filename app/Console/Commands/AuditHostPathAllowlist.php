<?php

namespace App\Console\Commands;

use App\Services\BackupSources\HostPathAllowlistAudit;
use App\Support\DeploymentMode;
use Illuminate\Console\Command;

class AuditHostPathAllowlist extends Command
{
    protected $signature = 'volumevault:host-path-allowlist:audit';

    protected $description = 'Check whether host-path sources or local destinations are blocked by the fail-closed VOLUMEVAULT_HOST_PATH_ALLOWLIST, and suggest the value to set.';

    public function handle(HostPathAllowlistAudit $audit): int
    {
        if (DeploymentMode::isOrchestrator()) {
            return self::SUCCESS;
        }

        $inUse = $audit->pathsInUse();

        if ($inUse === []) {
            $this->info('No host-path backup sources or local destinations are configured; nothing to allowlist.');

            return self::SUCCESS;
        }

        if (! $audit->hasMisconfiguration()) {
            $this->info('VOLUMEVAULT_HOST_PATH_ALLOWLIST already covers every host path in use.');

            return self::SUCCESS;
        }

        $blocked = $audit->blockedPaths();

        $this->warn('The following in-use host paths are blocked by the current allowlist and their backups will fail:');
        foreach ($blocked as $path) {
            $this->line('  - '.$path);
        }

        $this->newLine();
        $this->line('Add this to your .env to keep them working, then restart VolumeVault:');
        $this->line('  '.$audit->suggestedEnvLine());

        // Also log + record the warning so a scheduled run surfaces the breakage
        // even when no human is reading the console output.
        $audit->reportMisconfiguration();

        return self::FAILURE;
    }
}
