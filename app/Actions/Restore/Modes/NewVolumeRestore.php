<?php

namespace App\Actions\Restore\Modes;

use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\InspectDockerVolume;
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
        private readonly AppendRunLog $appendRunLog,
    ) {}

    public function validate(RestoreRun $run): void
    {
        if ($this->volumeExists($run->target_volume_name)) {
            throw new RuntimeException('Target Docker volume already exists: '.$run->target_volume_name);
        }
    }

    public function prepareTarget(RestoreRun $run, ?callable $heartbeat = null): void
    {
        $this->appendRunLog->handle($run, 'Creating target Docker volume '.$run->target_volume_name.'.');
        $run->forceFill(['target_volume_ownership_token' => bin2hex(random_bytes(32))])->save();

        try {
            $this->createDockerVolume->handle($run->target_volume_name, $run->target_volume_ownership_token);

            if (! $this->ownsTarget($run)) {
                throw new RuntimeException('Target Docker volume ownership could not be verified: '.$run->target_volume_name);
            }
        } catch (Throwable $exception) {
            $this->cleanupAfterFailure($run);

            throw $exception;
        }
    }

    /**
     * Docker has no conditional remove-by-ownership operation. Once the helper
     * releases its reference, even a freshly inspected name can be replaced.
     */
    public function cleanupAfterFailure(RestoreRun $run): void
    {
        $this->appendRunLog->handle($run, 'Target volume '.$run->target_volume_name.' was not automatically removed. Inspect it and remove it manually if appropriate, or choose a different target name before retrying.');
    }

    private function ownsTarget(RestoreRun $run): bool
    {
        $token = $run->target_volume_ownership_token;

        if (! is_string($token) || $token === '') {
            return false;
        }

        $volume = $this->inspectDockerVolume->handle($run->target_volume_name);

        return ($volume['labels'][CreateDockerVolume::RESTORE_OWNERSHIP_LABEL] ?? null) === $token;
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
