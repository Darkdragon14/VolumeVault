<?php

namespace App\Actions\Restore;

use App\Actions\Docker\InspectDockerVolume;
use App\Models\DockerVolume;
use Carbon\CarbonInterface;
use RuntimeException;

class GenerateRestoreVolumeName
{
    public function __construct(private readonly InspectDockerVolume $inspectDockerVolume) {}

    public function handle(string $sourceVolumeName, ?CarbonInterface $now = null): string
    {
        $timestamp = ($now ?: now())->format('Ymd_His');
        $source = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $sourceVolumeName) ?: 'volume';
        $source = trim($source, '._-') ?: 'volume';
        $source = mb_substr($source, 0, 80);
        $base = $source.'_restored_'.$timestamp;
        $candidate = $base;
        $suffix = 2;

        while ($this->volumeExists($candidate)) {
            $candidate = mb_substr($base, 0, 115).'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function volumeExists(string $volumeName): bool
    {
        if (DockerVolume::where('name', $volumeName)->exists()) {
            return true;
        }

        try {
            $this->inspectDockerVolume->handle($volumeName);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
