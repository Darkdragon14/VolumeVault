<?php

namespace App\Actions\Restore\Modes;

use App\Actions\Docker\ClearDockerVolume;
use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\StopDockerContainers;
use App\Models\RestoreRun;
use App\Services\Docker\SelfContainerResolver;
use App\Services\Logging\AppendRunLog;
use Illuminate\Support\Str;
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

    public function validate(RestoreRun $run): void
    {
        $this->requireExistingVolume($run);
    }

    public function prepareTarget(RestoreRun $run, ?callable $heartbeat = null): void
    {
        $this->stopAffectedContainers($run, $heartbeat);

        // Stopping many (or slow) containers can take a while with no Docker
        // container of our own to check for liveness; reconciliation could have
        // failed this run on the age threshold during the stop and already
        // restarted the containers. Re-check before the destructive clear: abort
        // rather than wipe/extract a volume whose run is no longer ours.
        $this->requireStillRunning($run);

        // Record the clear container's name as the run's container id before it
        // runs, so a slow delete on a large volume is reconciled on liveness
        // rather than failed on the age threshold — which would also wrongly
        // restart the containers we just stopped while the delete is still going.
        $containerName = 'volumevault-clear-'.$run->id.'-'.Str::lower(Str::random(8));
        $run->forceFill(['docker_container_id' => $containerName])->save();

        $this->appendRunLog->handle($run, 'Clearing existing contents of volume '.$run->target_volume_name.' before in-place restore.');
        $this->clearDockerVolume->handle($run->target_volume_name, $containerName, $heartbeat);
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
     * The full `docker ps -a` rows are stored on `affected_containers` for the UI
     * audit trail (including containers that were already stopped); the bare IDs
     * we actually stop go to `stopped_container_ids`, persisted BEFORE stopping so
     * a worker crash mid-run can be reconciled. Only containers that were *running*
     * are stopped and recorded — a container intentionally stopped before the
     * restore must not be started afterwards by the restart step. The VolumeVault
     * container is never stopped — doing so would kill this restore.
     */
    private function stopAffectedContainers(RestoreRun $run, ?callable $heartbeat): void
    {
        $containers = $this->findContainersUsingVolume->handle($run->target_volume_name);

        [$containers, $selfContainers] = $this->selfContainerResolver->partitionSelf($containers);

        if ($selfContainers) {
            $skipped = collect($selfContainers)->pluck('id')->filter()->implode(', ');
            $this->appendRunLog->handle($run, "Skipping VolumeVault's own container ({$skipped}) to avoid interrupting the restore.");
        }

        $stopped = collect($containers)
            ->filter(fn (array $container): bool => $container['state'] === 'running')
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        $run->forceFill(['affected_containers' => $containers])->save();

        if ($stopped) {
            $run->forceFill(['stopped_container_ids' => $stopped])->save();
            $this->appendRunLog->handle($run, 'Stopping containers before in-place restore: '.implode(', ', $stopped));

            // Refresh the heartbeat after each container so a long stop keeps the
            // run looking alive to reconciliation (no container id exists yet).
            $this->stopDockerContainers->handle(
                $stopped,
                $heartbeat,
            );
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

    /**
     * Guard the destructive clear: if reconciliation (or any out-of-band actor)
     * finalized this run while we were stopping containers, abort before wiping.
     */
    private function requireStillRunning(RestoreRun $run): void
    {
        if ($run->fresh()?->status !== RestoreRun::STATUS_RUNNING) {
            throw new RuntimeException('Restore was finalized out of band before the volume was cleared; aborting the in-place wipe.');
        }
    }

}
