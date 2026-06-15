<?php

namespace App\Actions\Restore\Modes;

use App\Actions\Docker\ClearDockerVolume;
use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\StopDockerContainers;
use App\Models\RestoreRun;
use App\Services\Docker\SelfContainerResolver;
use App\Services\Logging\AppendRunLog;
use RuntimeException;

/**
 * Safe in-place restore: like {@see InPlaceRestore} but stops the containers
 * mounting the source volume before wiping/extracting and restarts them once
 * the restore finishes.
 *
 * The restart itself lives in RunRestore's finally block (and the stale-run
 * reconciliation), driven off the `stopped_container_ids` this handler persists
 * before stopping — exactly the crash-recovery contract used for backups.
 */
class SafeInPlaceRestore implements RestoreModeHandler
{
    public function __construct(
        private readonly InspectDockerVolume $inspectDockerVolume,
        private readonly FindContainersUsingVolume $findContainersUsingVolume,
        private readonly StopDockerContainers $stopDockerContainers,
        private readonly SelfContainerResolver $selfContainerResolver,
        private readonly ClearDockerVolume $clearDockerVolume,
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function prepareTarget(RestoreRun $run): void
    {
        $this->requireExistingVolume($run);
        $this->stopAffectedContainers($run);

        $this->appendRunLog->handle($run, 'Clearing existing contents of volume '.$run->target_volume_name.' before in-place restore.');
        $this->clearDockerVolume->handle($run->target_volume_name);
    }

    public function cleanupAfterFailure(RestoreRun $run): void
    {
        // Source volume restored in place — nothing to remove. Stopped
        // containers are restarted by RunRestore's finally block regardless of
        // outcome, so there is no compensating work to do here.
    }

    /**
     * Discover the containers mounting the source volume and stop them.
     *
     * The full `docker ps` rows are stored on `affected_containers` for the UI
     * audit trail; the bare IDs we actually stop go to `stopped_container_ids`,
     * persisted BEFORE stopping so a worker crash mid-run can be reconciled. The
     * VolumeVault container is never stopped — doing so would kill this restore.
     */
    private function stopAffectedContainers(RestoreRun $run): void
    {
        $containers = $this->findContainersUsingVolume->handle($run->target_volume_name);

        [$containers, $selfContainers] = $this->selfContainerResolver->partitionSelf($containers);

        if ($selfContainers) {
            $skipped = collect($selfContainers)->pluck('id')->filter()->implode(', ');
            $this->appendRunLog->handle($run, "Skipping VolumeVault's own container ({$skipped}) to avoid interrupting the restore.");
        }

        $stopped = collect($containers)->pluck('id')->filter()->values()->all();

        $run->forceFill(['affected_containers' => $containers])->save();

        if ($stopped) {
            $run->forceFill(['stopped_container_ids' => $stopped])->save();
            $this->appendRunLog->handle($run, 'Stopping containers before in-place restore: '.implode(', ', $stopped));
            $this->stopDockerContainers->handle($stopped);
        }
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
