<?php

namespace App\Services\BackupSources;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
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
    public function pathsInUse(int $dockerHostId = DockerHost::LOCAL_ID): array
    {
        $hostPaths = BackupJob::query()
            ->where('docker_host_id', $dockerHostId)
            ->where('source_type', BackupJob::SOURCE_TYPE_HOST_PATH)
            ->whereNotNull('host_path')
            ->pluck('host_path')
            ->all();

        $localPaths = BackupDestination::query()
            ->where('docker_host_id', $dockerHostId)
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
    public function blockedPaths(int $dockerHostId = DockerHost::LOCAL_ID): array
    {
        return $this->inspect($dockerHostId)['blocked_paths'];
    }

    public function hasMisconfiguration(int $dockerHostId = DockerHost::LOCAL_ID): bool
    {
        return $this->blockedPaths($dockerHostId) !== [];
    }

    /**
     * The allowlist value that keeps every in-use path working: the prefixes
     * already configured plus the in-use paths not yet covered by them.
     *
     * @return array<int, string>
     */
    public function suggestedAllowlist(int $dockerHostId = DockerHost::LOCAL_ID): array
    {
        return $this->inspect($dockerHostId)['suggested_allowlist'] ?? [];
    }

    public function suggestedEnvLine(int $dockerHostId = DockerHost::LOCAL_ID): string
    {
        return $this->inspect($dockerHostId)['suggested_env_line'] ?? '';
    }

    /**
     * Remote policies are inventory snapshots, never filesystem probes. A fresh
     * heartbeat cannot refresh an old policy; inventory normally arrives every
     * five minutes, so allow three intervals before declaring it unknown.
     *
     * @return array{docker_host_id: int, host_name: string, configuration_target: string, policy_status: string, reported_at: ?string, freshness: string, configured: ?bool, prefixes: array<int, string>, paths_in_use: array<int, string>, blocked_paths: array<int, string>, unknown_paths: array<int, string>, status: string, suggested_allowlist: ?array, suggested_env_line: ?string}
     */
    public function inspect(int $dockerHostId = DockerHost::LOCAL_ID): array
    {
        $host = DockerHost::query()->findOrFail($dockerHostId);
        $policy = $this->policyReport($host);
        $known = $policy['status'] === 'known';
        $disabled = $policy['status'] === 'local_disabled';
        $prefixes = $policy['prefixes'];
        $paths = $this->pathsInUse($dockerHostId);
        $blocked = $known ? array_values(array_filter($paths, fn (string $path): bool => ! $this->matchesPrefixes($path, $prefixes))) : [];
        $suggested = $known ? collect([...$prefixes, ...$blocked])->unique()->sort()->values()->all() : null;

        return [
            'docker_host_id' => $dockerHostId,
            'host_name' => $host->name,
            'configuration_target' => $host->isLocal() ? 'local' : 'remote_agent',
            'policy_status' => $disabled ? 'local_disabled' : ($known ? 'known' : 'policy_unknown'),
            'reported_at' => $policy['reported_at'],
            'freshness' => $policy['freshness'],
            'configured' => $disabled ? false : ($known ? $prefixes !== [] : null),
            'prefixes' => $prefixes,
            'paths_in_use' => $paths,
            'blocked_paths' => $blocked,
            'unknown_paths' => $policy['status'] === 'unknown' ? $paths : [],
            'status' => $disabled ? 'local_disabled' : (! $known ? 'policy_unknown' : ($blocked === [] ? 'allowed' : 'blocked')),
            'suggested_allowlist' => $suggested,
            'suggested_env_line' => $suggested === null ? null : 'VOLUMEVAULT_HOST_PATH_ALLOWLIST='.implode(',', $suggested),
        ];
    }

    /**
     * Describe an already-loaded host policy without queries or filesystem IO.
     * This is advisory metadata; the executing agent enforces its actual policy.
     *
     * @return array{status: 'known'|'unknown'|'local_disabled', reported_at: ?string, freshness: string, prefixes: array<int, string>}
     */
    public function policyReport(DockerHost $host): array
    {
        $local = $host->isLocal();
        $reportedAt = $local ? null : $host->last_inventory_at;
        $freshness = $local ? 'current' : ($reportedAt === null ? 'unavailable' : ($reportedAt->greaterThan(now()->subMinutes(15)) ? 'fresh' : 'stale'));
        $known = $local || ($freshness === 'fresh' && $host->agentStatus() === 'online' && is_array($host->agent_host_path_allowlist));
        $disabled = $local && ! DeploymentMode::localExecutionEnabled();
        $prefixes = [];
        if ($known && ! $disabled) {
            $prefixes = $local ? $this->policy->allowedPrefixes() : collect($host->agent_host_path_allowlist)
                ->map(fn (mixed $path): string => $this->policy->normalize(is_string($path) ? $path : null))
                ->filter()->unique()->values()->all();
        }
        return [
            'status' => $disabled ? 'local_disabled' : ($known ? 'known' : 'unknown'),
            'reported_at' => $reportedAt?->toISOString(),
            'freshness' => $freshness,
            'prefixes' => $prefixes,
        ];
    }

    /** @param array<int, string> $prefixes */
    private function matchesPrefixes(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log and record (throttled) a warning when in-use paths are blocked by the
     * current allowlist. Returns true when a misconfiguration was reported.
     */
    public function reportMisconfiguration(int $dockerHostId = DockerHost::LOCAL_ID): bool
    {
        $result = $this->inspect($dockerHostId);
        $blocked = $result['blocked_paths'];

        if ($blocked === []) {
            return false;
        }

        $message = 'Host-path sources or local destinations are blocked by the fail-closed VOLUMEVAULT_HOST_PATH_ALLOWLIST. '
            .'Configure it on host '.$dockerHostId.' ('.$result['host_name'].', '.$result['configuration_target'].'). '
            .'Run `php artisan volumevault:host-path-allowlist:audit --host='.$dockerHostId.'` for the value to set.';

        Log::warning($message, ['docker_host_id' => $dockerHostId, 'blocked_paths' => $blocked, 'suggested' => $result['suggested_env_line']]);

        // Record at most once per day so the scheduled audit does not flood the
        // activity feed while the admin gets around to fixing the .env.
        $throttleKey = self::REPORT_THROTTLE_KEY.':'.$dockerHostId;
        if (! Cache::has($throttleKey)) {
            ActivityLog::record('host_path_allowlist_misconfigured', $message, null, [
                'blocked_paths' => $blocked,
                'docker_host_id' => $dockerHostId,
                'configuration_target' => $result['configuration_target'],
                'reported_at' => $result['reported_at'],
                'suggested_allowlist' => $result['suggested_allowlist'],
            ]);

            Cache::put($throttleKey, now(), now()->addDay());
        }

        return true;
    }
}
