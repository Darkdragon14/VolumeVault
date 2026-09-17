<?php

namespace App\Services\BackupDestinations;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SecureLocalArchiveReader
{
    private const TIMEOUT_SECONDS = 3600;

    public function __construct(
        private readonly string $binary = '/usr/local/bin/volumevault-local-archive-reader',
    ) {}

    /** @param array<string|int, int> $expectedRootStat */
    public function copy(string $archiveRoot, string $key, string $targetPath, array $expectedRootStat, ?callable $progress = null): void
    {
        if (! is_executable($this->binary)) {
            throw new RuntimeException('The secure local archive reader is not installed.');
        }

        $targetHandle = null;
        $stagingPath = null;
        $process = null;

        try {
            for ($attempt = 0; $attempt < 10 && $targetHandle === null; $attempt++) {
                $candidate = dirname($targetPath).DIRECTORY_SEPARATOR.'.'.basename($targetPath).'.'.bin2hex(random_bytes(8)).'.tmp';
                $handle = @fopen($candidate, 'xb');

                if ($handle !== false) {
                    $stagingPath = $candidate;
                    $targetHandle = $handle;
                }
            }

            if (! is_resource($targetHandle)) {
                throw new RuntimeException('Unable to create a staging file for the local backup.');
            }

            $process = $this->createProcess([
                $this->binary,
                $archiveRoot,
                $key,
                $targetPath,
                (string) $expectedRootStat['dev'],
                (string) $expectedRootStat['ino'],
            ]);
            $errorOutput = '';
            $lastProgressAt = microtime(true);

            if ($progress !== null) {
                $progress();
            }

            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->start();

            do {
                $isRunning = $process->isRunning();
                $output = $process->getOutput();

                if ($output !== '') {
                    $this->writeChunk($targetHandle, $output);
                    $process->clearOutput();
                }

                $errorOutput .= $process->getErrorOutput();
                $process->clearErrorOutput();

                if ($progress !== null && microtime(true) - $lastProgressAt >= $this->progressIntervalSeconds()) {
                    $lastProgressAt = microtime(true);
                    $progress();
                }

                if ($isRunning) {
                    $process->checkTimeout();
                    usleep(200000);
                }
            } while ($isRunning);

            $process->wait();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($errorOutput) ?: 'Unable to stream the local backup file.');
            }

            if (! fflush($targetHandle) || (function_exists('fsync') && ! fsync($targetHandle))) {
                throw new RuntimeException('Unable to stream the local backup file.');
            }

            if (! fclose($targetHandle)) {
                throw new RuntimeException('Unable to close the staged local backup file.');
            }

            $targetHandle = null;

            if (! @rename($stagingPath, $targetPath)) {
                throw new RuntimeException('Unable to move the staged local backup into place.');
            }

            $stagingPath = null;
        } catch (Throwable $exception) {
            if ($process instanceof Process) {
                try {
                    $process->stop(0);
                } catch (Throwable) {
                    // Preserve the original streaming or publication failure.
                }
            }

            if (is_resource($targetHandle)) {
                @fclose($targetHandle);
            }

            if ($stagingPath !== null) {
                @unlink($stagingPath);
            }

            throw $exception;
        }
    }

    /** @param array<string|int, int> $expectedRootStat */
    public function write(string $archiveRoot, string $key, string $sourcePath, array $expectedRootStat): void
    {
        if (! is_executable($this->binary)) {
            throw new RuntimeException('The secure local archive reader is not installed.');
        }

        $process = $this->createProcess([
            $this->binary,
            'write',
            $archiveRoot,
            $key,
            $sourcePath,
            (string) $expectedRootStat['dev'],
            (string) $expectedRootStat['ino'],
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to write the local backup file.');
        }
    }

    /** @param list<string> $command */
    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    protected function progressIntervalSeconds(): int
    {
        return 30;
    }

    /** @param resource $targetHandle */
    protected function writeChunk(mixed $targetHandle, string $buffer): void
    {
        for ($offset = 0, $length = strlen($buffer); $offset < $length;) {
            $written = fwrite($targetHandle, substr($buffer, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to stream the local backup file.');
            }

            $offset += $written;
        }
    }
}
