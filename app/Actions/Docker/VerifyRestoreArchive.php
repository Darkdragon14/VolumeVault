<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;

/**
 * Prove a downloaded backup archive is fully readable before an in-place restore
 * wipes the live volume.
 *
 * A non-empty file can still be a truncated or corrupt .tar.gz that only blows
 * up partway through `tar -xzf` during extraction — by which point the source
 * volume is already cleared. Streaming the archive through `tar -tzf -` (list,
 * no extraction) forces the whole gzip stream and tar structure to be
 * decompressed and parsed, so corruption surfaces here, before anything
 * destructive runs. Mirrors {@see RunRestoreContainer}'s stdin streaming so it
 * works regardless of whether the archive path is reachable by a bind mount.
 */
class VerifyRestoreArchive
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    /**
     * @param  string|null  $containerName  Name to give the throwaway verify
     *                                      container. Pass it when the caller records the name as the run's
     *                                      docker_container_id, so stale-run reconciliation can confirm a long verify
     *                                      (a huge, many-file archive) is still alive instead of failing the restore.
     */
    public function handle(string $archivePath, ?string $containerName = null): DockerProcessResult
    {
        // Discard the file listing tar -tzf writes to stdout — DockerProcess buffers
        // stdout in memory, and an archive with millions of entries would otherwise
        // blow up verification. The redirect keeps tar's exit status (the single
        // command's status), which is all we need: non-zero means corrupt/truncated.
        $command = array_merge([
            'docker',
            'run',
            '--rm',
            '-i',
        ], $containerName ? ['--name', $containerName] : [], [
            '--entrypoint',
            'sh',
            RunBackupContainer::IMAGE,
            '-c',
            'tar -tzf - > /dev/null',
        ]);

        return $this->dockerProcess->runWithInputFile($command, $archivePath, 0);
    }
}
