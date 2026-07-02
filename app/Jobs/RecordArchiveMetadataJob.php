<?php

namespace App\Jobs;

use App\Actions\Backup\RunBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Record a completed backup run's archive metadata (key + size) off the run's
 * critical path. The destination listing can be slow (WebDAV Depth: infinity,
 * recursive SFTP, slow NFS); running it inline inside a group member run would
 * block the group worker and let a live group run be reconciled as stale, so
 * group members defer it here. Best-effort: RunBackup swallows listing errors.
 */
class RecordArchiveMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $backupRunId) {}

    public function handle(RunBackup $runBackup): void
    {
        $runBackup->recordArchiveMetadata($this->backupRunId);
    }
}
