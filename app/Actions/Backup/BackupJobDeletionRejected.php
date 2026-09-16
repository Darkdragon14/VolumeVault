<?php

namespace App\Actions\Backup;

use RuntimeException;

class BackupJobDeletionRejected extends RuntimeException
{
    public const READ_ONLY = 'read_only';

    public const RUN_IN_PROGRESS = 'run_in_progress';

    public const FINALIZATION_PENDING = 'finalization_pending';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            self::READ_ONLY => 'Docker label managed jobs are read-only.',
            self::RUN_IN_PROGRESS => 'This job has a backup run in progress. Wait for it to finish before deleting it.',
            self::FINALIZATION_PENDING => 'This job still has run finalization work pending. Wait for it to finish before deleting it.',
        });
    }
}
