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

    public function handle(string $archivePath): DockerProcessResult
    {
        $command = [
            'docker',
            'run',
            '--rm',
            '-i',
            '--entrypoint',
            'tar',
            RunBackupContainer::IMAGE,
            '-tzf',
            '-',
        ];

        return $this->dockerProcess->runWithInputFile($command, $archivePath, 0);
    }
}
