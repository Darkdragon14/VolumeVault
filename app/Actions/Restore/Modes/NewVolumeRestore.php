<?php

namespace App\Actions\Restore\Modes;

use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\RemoveDockerVolume;
use App\Models\RestoreRun;
use App\Services\Logging\AppendRunLog;
use RuntimeException;
use Throwable;

/**
 * Restore into a brand-new Docker volume, never touching the source.
 */
class NewVolumeRestore implements RestoreModeHandler
{
    public function __construct(
        private readonly InspectDockerVolume $inspectDockerVolume,
        private readonly CreateDockerVolume $createDockerVolume,
        private readonly RemoveDockerVolume $removeDockerVolume,
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function validate(RestoreRun $run): void
    {
        if ($this->volumeExists($run->target_volume_name)) {
            throw new RuntimeException('Target Docker volume already exists: '.$run->target_volume_name);
        }
    }

    public function prepareTarget(RestoreRun $run): void
    {
        $this->appendRunLog->handle($run, 'Creating target Docker volume '.$run->target_volume_name.'.');
        $this->createDockerVolume->handle($run->target_volume_name);
    }

    /**
     * Remove the target volume this run created after a failed extraction.
     *
     * Without this, the partially-created volume survives and the next retry
     * trips the prepareTarget() existence guard with no clear cause. Only
     * reached when prepareTarget() succeeded, so the volume is always ours to
     * remove. Cleanup failures are logged but never mask the original error.
     */
    public function cleanupAfterFailure(RestoreRun $run): void
    {
        try {
            $this->removeDockerVolume->handle($run->target_volume_name);
            $this->appendRunLog->handle($run, 'Removed partially-created target volume '.$run->target_volume_name.' so the run can be retried cleanly.');
        } catch (Throwable $cleanupException) {
            $this->appendRunLog->handle($run, 'Failed to remove partially-created target volume '.$run->target_volume_name.': '.$cleanupException->getMessage());
        }
    }

    private function volumeExists(string $volumeName): bool
    {
        try {
            $this->inspectDockerVolume->handle($volumeName);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
