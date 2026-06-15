<?php

namespace App\Actions\Restore\Modes;

use App\Actions\Docker\ClearDockerVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Models\RestoreRun;
use App\Services\Logging\AppendRunLog;
use RuntimeException;

/**
 * Restore directly into the source volume (destructive).
 *
 * The source volume's contents are wiped and replaced by the backup, so the
 * volume ends up matching the archive exactly. Affected containers are NOT
 * stopped — that is what {@see SafeInPlaceRestore} adds. Guarded behind a typed
 * confirmation in the request/wizard layer.
 */
class InPlaceRestore implements RestoreModeHandler
{
    public function __construct(
        private readonly InspectDockerVolume $inspectDockerVolume,
        private readonly ClearDockerVolume $clearDockerVolume,
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function prepareTarget(RestoreRun $run): void
    {
        $this->requireExistingVolume($run);

        $this->appendRunLog->handle($run, 'Clearing existing contents of volume '.$run->target_volume_name.' before in-place restore.');
        $this->clearDockerVolume->handle($run->target_volume_name);
    }

    public function cleanupAfterFailure(RestoreRun $run): void
    {
        // The source volume is restored in place; this run never created it and
        // must not remove it. A partial extraction is surfaced via the run logs.
    }

    private function requireExistingVolume(RestoreRun $run): void
    {
        try {
            $this->inspectDockerVolume->handle($run->target_volume_name);
        } catch (RuntimeException) {
            throw new RuntimeException('Target Docker volume does not exist for in-place restore: '.$run->target_volume_name);
        }
    }
}
