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
     * Read-only precondition check, run BEFORE the archive download (and before
     * any safety backup) so an invalid target fails fast and cheaply — e.g. the
     * source volume missing for an in-place restore, or the target name already
     * taken for a new-volume restore. Must not mutate anything.
     */
    public function validate(RestoreRun $run): void;

    /**
     * Prepare the target volume (create / clear) and environment (stop affected
     * containers). Runs only AFTER the archive has been downloaded and verified,
     * so the destructive in-place wipe never happens unless a usable archive is
     * already on disk.
     *
     * Throwing here means preparation did not complete, so cleanupAfterFailure()
     * is NOT called in that case — this method must never leave a volume this run
     * created behind without surfacing it through cleanupAfterFailure().
     */
    public function prepareTarget(RestoreRun $run, ?callable $heartbeat = null): void;

    /**
     * Compensating cleanup for a failure that occurred *after* prepareTarget()
     * succeeded (e.g. remove a volume this run created). Never called when
     * prepareTarget() itself threw.
     */
    public function cleanupAfterFailure(RestoreRun $run): void;
}
