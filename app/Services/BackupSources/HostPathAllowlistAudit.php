<?php

namespace App\Services\BackupSources;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Support\DeploymentMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Migration aid for the fail-closed host-path allowlist.
 *
 * Before VolumeVault enforced a fail-closed allowlist, an empty
 * VOLUMEVAULT_HOST_PATH_ALLOWLIST allowed every host path. Installations that
 * relied on that (host-path backup sources or local destinations) keep their
 * records but those backups now fail until the admin allowlists the paths.
 *
 * This service detects such records, derives the allowlist value that would
 * keep them working, and surfaces the misconfiguration so the breakage is
 * never silent.
 */
class HostPathAllowlistAudit
{
    /**
     * Cache key throttling how often the misconfiguration is recorded in the
     * activity log, so a scheduled audit does not flood the feed.
     */
    private const REPORT_THROTTLE_KEY = 'host_path_allowlist_misconfig_reported_at';

    public function __construct(private readonly HostPathPolicy $policy) {}

    /**
     * Distinct, normalized host paths currently referenced by host-path backup
     * sources and local backup destinations.
     *
     * @return array<int, string>
     */
    public function pathsInUse(): array
    {
        $hostPaths = BackupJob::query()
            ->where('source_type', BackupJob::SOURCE_TYPE_HOST_PATH)
            ->whereNotNull('host_path')
            ->pluck('host_path')
            ->all();

        $localPaths = BackupDestination::query()
            ->where('provider', BackupDestination::PROVIDER_LOCAL)
            ->get()
            ->flatMap(fn (BackupDestination $destination): array => [
                $destination->setting('archive_path'),
                $destination->setting('archive_mount_source'),
            ])
            ->all();

        return collect([...$hostPaths, ...$localPaths])
            ->map(fn (mixed $path): string => $this->policy->normalize(is_string($path) ? $path : null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * In-use paths the current allowlist would reject (the ones that break).
     *
     * @return array<int, string>
     */
    public function blockedPaths(): array
    {
        if (DeploymentMode::isOrchestrator()) {
            return [];
        }

        return collect($this->pathsInUse())
            ->reject(fn (string $path): bool => $this->policy->isAllowed($path))
            ->values()
            ->all();
    }

    public function hasMisconfiguration(): bool
    {
        return $this->blockedPaths() !== [];
    }

    /**
     * The allowlist value that keeps every in-use path working: the prefixes
     * already configured plus the in-use paths not yet covered by them.
     *
     * @return array<int, string>
     */
    public function suggestedAllowlist(): array
    {
        return collect([...$this->policy->allowedPrefixes(), ...$this->blockedPaths()])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function suggestedEnvLine(): string
    {
        return 'VOLUMEVAULT_HOST_PATH_ALLOWLIST='.implode(',', $this->suggestedAllowlist());
    }

    /**
     * Log and record (throttled) a warning when in-use paths are blocked by the
     * current allowlist. Returns true when a misconfiguration was reported.
     */
    public function reportMisconfiguration(): bool
    {
        $blocked = $this->blockedPaths();

        if ($blocked === []) {
            return false;
        }

        $message = 'Host-path sources or local destinations are blocked by the fail-closed VOLUMEVAULT_HOST_PATH_ALLOWLIST. '
            .'Their backups will fail until the allowlist is configured. Run `php artisan volumevault:host-path-allowlist:audit` for the value to set.';

        Log::warning($message, ['blocked_paths' => $blocked, 'suggested' => $this->suggestedEnvLine()]);

        // Record at most once per day so the scheduled audit does not flood the
        // activity feed while the admin gets around to fixing the .env.
        if (! Cache::has(self::REPORT_THROTTLE_KEY)) {
            ActivityLog::record('host_path_allowlist_misconfigured', $message, null, [
                'blocked_paths' => $blocked,
                'suggested_allowlist' => $this->suggestedAllowlist(),
            ]);

            Cache::put(self::REPORT_THROTTLE_KEY, now(), now()->addDay());
        }

        return true;
    }
}
