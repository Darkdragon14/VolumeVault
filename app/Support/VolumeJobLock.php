<?php

namespace App\Support;

class VolumeJobLock
{
    /**
     * Overlap-prevention key shared by backup and restore queue jobs.
     *
     * Keying on the Docker volume (rather than the individual run or job) makes
     * any two operations touching the same volume serialize: two destructive
     * in-place restores, or a scheduled backup reading a volume an in-place
     * restore is mid-wipe, can no longer run concurrently. Operations with no
     * Docker volume (host-path backups, restore-to-new-volume) fall back to the
     * caller's own identity key, preserving their previous per-run/per-job
     * isolation.
     */
    public static function key(?string $volumeName, string $fallback): string
    {
        return filled($volumeName) ? 'volume-'.$volumeName : $fallback;
    }
}
