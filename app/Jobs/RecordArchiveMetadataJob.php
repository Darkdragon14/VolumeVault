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
 * critical path, and — for a standalone run — send its finished notification. The
 * destination listing can be slow (WebDAV Depth: infinity, recursive SFTP, slow
 * NFS); running it inline would block the backup's queue job while it holds the
 * volume lock, so it is deferred here. Runs on a dedicated "metadata" queue with
 * its own worker so a slow listing cannot block the main worker and starve a
 * same-volume backup/restore into a false stale-reconciliation on the packaged
 * single-worker image. Best-effort: RunBackup swallows listing errors.
 */
class RecordArchiveMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $backupRunId)
    {
        $this->onQueue('metadata');
    }

    public function handle(RunBackup $runBackup): void
    {
        $runBackup->recordArchiveMetadata($this->backupRunId);
    }
}
