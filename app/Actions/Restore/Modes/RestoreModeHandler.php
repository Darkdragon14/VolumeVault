<?php

namespace App\Actions\Restore\Modes;

use App\Models\RestoreRun;

/**
 * One restore mode (new volume / in place / safe in place).
 *
 * RunRestore owns the invariant skeleton — status transitions, archive
 * download, extraction, finalize and container restart — and delegates the
 * mode-specific behaviour (how the target volume is prepared and what to clean
 * up on failure) to one of these handlers, resolved by RestoreRun::mode.
 */
interface RestoreModeHandler
{
    /**
     * Validate preconditions and prepare the target volume (create / clear) and
     * environment (stop affected containers) before the archive is extracted.
     *
     * Throwing here aborts the restore before any download/extraction. Because a
     * throw means preparation did not complete, cleanupAfterFailure() is NOT
     * called in that case — so this method must never leave a volume this run
     * created behind without surfacing it through cleanupAfterFailure().
     */
    public function prepareTarget(RestoreRun $run): void;

    /**
     * Compensating cleanup for a failure that occurred *after* prepareTarget()
     * succeeded (e.g. remove a volume this run created). Never called when
     * prepareTarget() itself threw.
     */
    public function cleanupAfterFailure(RestoreRun $run): void;
}
