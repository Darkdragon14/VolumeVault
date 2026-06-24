<?php

namespace App\Actions\Restore;

use App\Actions\Backup\RunBackup;
use App\Actions\Restore\Modes\InPlaceRestore;
use App\Actions\Restore\Modes\SafeInPlaceRestore;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\Logging\AppendRunLog;
use RuntimeException;

/**
 * Safety net for the destructive in-place restore modes.
 *
 * Before {@see InPlaceRestore} /
 * {@see SafeInPlaceRestore} wipe the source volume,
 * this captures a full backup of its *current* contents to the configured
 * destination so the user can roll back if they picked the wrong archive.
 *
 * The BackupRun is created directly (not via CreateBackupRun) on purpose: a
 * restore-triggered safety backup must not advance the job's scheduling grid.
 * The full RunBackup pipeline is still reused for tar + upload + container
 * handling + archive metadata, so the result is a real, restorable backup that
 * shows up in the job's run history under the `pre_restore` trigger.
 *
 * A failure here throws: RunRestore runs this before prepareTarget, so the
 * source volume has not been touched yet and the restore aborts cleanly.
 */
class RunPreRestoreBackup
{
    public function __construct(
        private readonly RunBackup $runBackup,
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function handle(RestoreRun $run): void
    {
        // The restore froze target_volume_name at creation, but RunBackup backs up
        // the job's CURRENT volume_name. If the job was edited (volume changed, or
        // switched to a host-path source) while this in-place restore waited or
        // downloaded, the safety backup would capture the wrong volume — giving a
        // false safety net before the frozen volume is wiped. Abort instead.
        $run->loadMissing('job');
        $job = $run->job;

        if (! $job?->isDockerVolumeSource() || $job->volume_name !== $run->target_volume_name) {
            throw new RuntimeException(
                'The backup job no longer targets the volume being restored ('.$run->target_volume_name.'); '.
                'aborting before the safety backup to avoid backing up the wrong volume.'
            );
        }

        $this->appendRunLog->handle($run, 'Creating a safety backup of volume '.$run->target_volume_name.' before overwriting it.');

        $backup = BackupRun::create([
            'backup_job_id' => $run->backup_job_id,
            'initiated_by_user_id' => $run->initiated_by_user_id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_PRE_RESTORE,
        ]);

        // Link before running so the restore detail can surface the safety backup
        // even if it fails.
        $run->forceFill(['pre_restore_backup_run_id' => $backup->id])->save();

        $this->runBackup->handle($backup);

        $backup->refresh();

        if ($backup->status !== BackupRun::STATUS_SUCCESS) {
            throw new RuntimeException(
                'Safety backup before overwrite failed; aborting restore. '.
                ($backup->error_message ?: 'See the backup run for details.')
            );
        }

        $this->appendRunLog->handle($run, 'Safety backup completed: '.($backup->backup_key ?: 'backup #'.$backup->id).'.');
    }
}
