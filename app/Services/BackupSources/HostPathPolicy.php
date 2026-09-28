<?php

namespace App\Services\BackupSources;

use App\Models\DockerHost;
use App\Support\DeploymentMode;
use InvalidArgumentException;

class HostPathPolicy
{
    public function normalize(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return $path === '/' ? $path : rtrim($path, '/');
    }

    public function assertValid(string $path): void
    {
        if ($message = $this->validationError($path)) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * Re-validate a path at run time (defence in depth against a symlink swap
     * happening between job/destination creation and the backup run — TOCTOU).
     *
     * Validates the configured path, then re-validates its canonical target when
     * visible to the app. Paths unavailable to VolumeVault remain lexical.
     */
    public function assertValidAtRuntime(string $path): void
    {
        $normalized = $this->normalize($path);

        $this->assertValid($normalized);

        $real = @realpath($normalized);

        if ($real !== false && $real !== $normalized) {
            $this->assertValid($this->normalize($real));

            return;
        }

        if ($real === false) {
            $ancestor = $normalized;
            $suffix = [];

            while ($ancestor !== '/' && ($canonicalAncestor = @realpath($ancestor)) === false) {
                array_unshift($suffix, basename($ancestor));
                $ancestor = dirname($ancestor);
            }

            if (isset($canonicalAncestor) && $canonicalAncestor !== false) {
                $this->assertValid($this->normalize($canonicalAncestor.'/'.implode('/', $suffix)));
            }
        }
    }

    public function validationError(string $path, ?int $dockerHostId = null): ?string
    {
        if (! str_starts_with($path, '/')) {
            return 'Host path must be an absolute path.';
        }

        if ($path === '/') {
            return 'Host path cannot be the filesystem root.';
        }

        if (str_contains($path, ',')) {
            return 'Host paths containing commas are not supported.';
        }

        $segments = array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '');

        if (collect($segments)->contains(fn (string $segment): bool => $segment === '.' || $segment === '..')) {
            return 'Host path cannot contain . or .. segments.';
        }

        if (preg_match('/[\x00-\x1F\x7F:]/', $path)) {
            return 'Host path contains unsupported characters.';
        }

        if (! $this->isAllowed($path, $dockerHostId)) {
            $prefixes = $this->allowedPrefixes($dockerHostId);

            if ($prefixes === []) {
                return 'Host path access is disabled. Configure at least one allowed prefix in VOLUMEVAULT_HOST_PATH_ALLOWLIST.';
            }

            return 'Host path is outside VOLUMEVAULT_HOST_PATH_ALLOWLIST. Allowed prefixes: '.implode(', ', $prefixes).'.';
        }

        return null;
    }

    public function isAllowed(string $path, ?int $dockerHostId = null): bool
    {
        $prefixes = $this->allowedPrefixes($dockerHostId);

        // Fail closed: with no allowlist configured, no host path is allowed.
        // (Out of the box this blocks mounting arbitrary host paths such as
        // /etc, /root/.ssh or /var/lib/docker as a backup source/destination.)
        if ($prefixes === []) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function allowedPrefixes(?int $dockerHostId = null): array
    {
        if ($dockerHostId !== null && $dockerHostId !== DockerHost::LOCAL_ID) {
            return collect(DockerHost::find($dockerHostId)?->agent_host_path_allowlist ?? [])
                ->map(fn (mixed $path): string => $this->normalize(is_string($path) ? $path : null))
                ->filter()->unique()->values()->all();
        }

        if (DeploymentMode::isOrchestrator()) {
            return [];
        }

        $allowlist = config('volumevault.host_path_allowlist', []);

        if (is_string($allowlist)) {
            $allowlist = explode(',', $allowlist);
        }

        if (! is_array($allowlist)) {
            return [];
        }

        return collect($allowlist)
            ->map(fn (mixed $path): string => $this->normalize(is_string($path) ? $path : null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
