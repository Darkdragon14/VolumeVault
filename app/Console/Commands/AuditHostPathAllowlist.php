<?php

namespace App\Console\Commands;

use App\Services\BackupSources\HostPathAllowlistAudit;
use App\Models\DockerHost;
use Illuminate\Console\Command;

class AuditHostPathAllowlist extends Command
{
    protected $signature = 'volumevault:host-path-allowlist:audit {--host= : Docker host ID (default: local host)} {--all : Audit all hosts using stored inventory}';

    protected $description = 'Check whether host-path sources or local destinations are blocked by the fail-closed VOLUMEVAULT_HOST_PATH_ALLOWLIST, and suggest the value to set.';

    public function handle(HostPathAllowlistAudit $audit): int
    {
        $hostId = $this->option('host');
        if (($this->option('all') && $hostId !== null)
            || ($hostId !== null && (filter_var($hostId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || ! DockerHost::query()->whereKey($hostId)->exists()))) {
            $this->error('Use either --all or --host with an existing positive integer Docker host ID.');

            return self::INVALID;
        }

        $hostIds = $this->option('all') ? DockerHost::query()->orderBy('id')->pluck('id')->all() : [(int) ($hostId ?? DockerHost::LOCAL_ID)];
        $exitCode = self::SUCCESS;
        foreach ($hostIds as $id) {
            $exitCode = max($exitCode, $this->auditHost($audit, $id));
        }

        return $exitCode;
    }

    private function auditHost(HostPathAllowlistAudit $audit, int $hostId): int
    {
        $result = $audit->inspect($hostId);
        $this->line('Host '.$hostId.' ('.$result['host_name'].'): '.$result['status']);
        if ($result['status'] === 'local_disabled') {
            $this->info('Local execution is disabled; local host-path policy is not audited.');

            return self::SUCCESS;
        }
        if ($result['status'] === 'policy_unknown') {
            $this->warn('Remote policy_unknown: obtain fresh agent inventory before assessing paths. Last reported: '.($result['reported_at'] ?? 'never').'; freshness: '.$result['freshness'].'.');

            return self::INVALID;
        }
        if ($result['configuration_target'] === 'remote_agent') {
            $this->line('Using last reported agent policy: '.$result['reported_at'].' ('.$result['freshness'].'); filesystem permissions are not checked.');
        }

        $inUse = $result['paths_in_use'];

        if ($inUse === []) {
            $this->info('No host-path backup sources or local destinations are configured; nothing to allowlist.');

            return self::SUCCESS;
        }

        if ($result['blocked_paths'] === []) {
            $this->info('VOLUMEVAULT_HOST_PATH_ALLOWLIST already covers every host path in use.');

            return self::SUCCESS;
        }

        $blocked = $result['blocked_paths'];

        $this->warn($result['configuration_target'] === 'local'
            ? 'The following in-use host paths are blocked by the current allowlist and their backups will fail:'
            : 'The following in-use host paths are blocked by the last reported remote agent allowlist:');
        foreach ($blocked as $path) {
            $this->line('  - '.$path);
        }

        $this->newLine();
        $this->line($result['configuration_target'] === 'local'
            ? 'Add this to your .env to keep them working, then restart VolumeVault:'
            : 'Set VOLUMEVAULT_HOST_PATH_ALLOWLIST in the remote agent configuration on host '.$hostId.' ('.$result['host_name'].'), then restart that agent:');
        $this->line('  '.$result['suggested_env_line']);

        // Also log + record the warning so a scheduled run surfaces the breakage
        // even when no human is reading the console output.
        $audit->reportMisconfiguration($hostId);

        return self::FAILURE;
    }
}
