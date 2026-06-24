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
        // Discard the file listing tar -tzf writes to stdout — DockerProcess buffers
        // stdout in memory, and an archive with millions of entries would otherwise
        // blow up verification. The redirect keeps tar's exit status (the single
        // command's status), which is all we need: non-zero means corrupt/truncated.
        $command = [
            'docker',
            'run',
            '--rm',
            '-i',
            '--entrypoint',
            'sh',
            RunBackupContainer::IMAGE,
            '-c',
            'tar -tzf - > /dev/null',
        ];

        return $this->dockerProcess->runWithInputFile($command, $archivePath, 0);
    }
}
