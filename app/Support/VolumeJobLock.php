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

    /**
     * The Cache lock key the WithoutOverlapping middleware uses for a shared()
     * volume key — its default prefix plus {@see key()}. Reconciliation uses this
     * to force-release a lock a crashed worker left orphaned (WithoutOverlapping
     * sets a 24h expiry, so the volume would otherwise stay blocked for a day).
     *
     * Mirrors Illuminate\Queue\Middleware\WithoutOverlapping::$prefix; kept in
     * sync here so the release targets the exact key the jobs lock on.
     */
    public static function cacheKey(string $volumeName): string
    {
        return 'laravel-queue-overlap:'.self::key($volumeName, '');
    }
}
