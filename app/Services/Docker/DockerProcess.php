<?php

namespace App\Services\Docker;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class DockerProcess
{
    private ?Closure $progressCallback = null;

    private int $progressIntervalSeconds = 30;

    private float $lastProgressAt = 0;

    private const SECRET_KEYS = [
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'WEBDAV_USERNAME',
        'WEBDAV_PASSWORD',
        'SSH_PASSWORD',
        'SSH_IDENTITY_PASSPHRASE',
        'AZURE_STORAGE_PRIMARY_ACCOUNT_KEY',
        'AZURE_STORAGE_CONNECTION_STRING',
        'DROPBOX_APP_KEY',
        'DROPBOX_APP_SECRET',
        'DROPBOX_REFRESH_TOKEN',
        'GOOGLE_DRIVE_CREDENTIALS_JSON',
        'SECRET_ACCESS_KEY',
        'ACCESS_KEY_ID',
        'PASSWORD',
        'TOKEN',
        'SECRET',
        'SHOUTRRR_URL',
    ];

    public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
    {
        $process = new Process($command, null, $this->environment($environment), null, $timeout);

        return $this->runProcess($process, $command);
    }

    /**
     * Invoke a callback periodically while a command executed by $operation is
     * running, including when the command produces no output.
     */
    public function whileMonitoring(callable $callback, callable $operation, int $intervalSeconds = 30): mixed
    {
        $previousCallback = $this->progressCallback;
        $previousInterval = $this->progressIntervalSeconds;
        $previousProgressAt = $this->lastProgressAt;

        $this->progressCallback = Closure::fromCallable($callback);
        $this->progressIntervalSeconds = max(1, $intervalSeconds);
        $this->lastProgressAt = microtime(true);

        try {
            ($this->progressCallback)();

            return $operation();
        } finally {
            $this->progressCallback = $previousCallback;
            $this->progressIntervalSeconds = $previousInterval;
            $this->lastProgressAt = $previousProgressAt;
        }
    }

    public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
    {
        $input = @fopen($inputPath, 'rb');

        if ($input === false) {
            throw new RuntimeException('Unable to open Docker process input file: '.$inputPath);
        }

        try {
            $process = new Process($command, null, $this->environment($environment), $input, $timeout);

            return $this->runProcess($process, $command);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    /**
     * Run a command and stream its stdout straight into a file instead of
     * buffering it in memory. Used to pull a backup archive out of a Docker
     * volume (`cat`-ing it from a throwaway container): an archive can be many
     * gigabytes, so {@see run()}'s in-memory capture is not an option here.
     * stderr is small (error messages only) and is captured for reporting.
     */
    public function runWithOutputFile(array $command, string $outputPath, int $timeout = 300, array $environment = []): DockerProcessResult
    {
        $output = @fopen($outputPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to open Docker process output file: '.$outputPath);
        }

        $process = new Process($command, null, $this->environment($environment), null, $timeout);
        $errorOutput = '';

        try {
            $process->run(function (string $type, string $buffer) use ($output, $outputPath, &$errorOutput, $process): void {
                if ($type !== Process::OUT) {
                    $errorOutput .= $buffer;
                    // stderr stays tiny, but clear it too so nothing accumulates.
                    $process->clearErrorOutput();

                    return;
                }

                // fwrite can do a short write; loop until the whole chunk lands.
                for ($offset = 0, $length = strlen($buffer); $offset < $length;) {
                    $written = fwrite($output, substr($buffer, $offset));

                    if ($written === false) {
                        throw new RuntimeException('Unable to write Docker output to file: '.$outputPath);
                    }

                    $offset += $written;
                }

                // Symfony keeps appending stdout to the Process buffer even when a
                // callback streams it; clear it after each chunk so a multi-GB
                // archive is never duplicated into memory (or the temp spool).
                $process->clearOutput();
            });

            return new DockerProcessResult(
                command: $this->sanitizeCommand($command),
                exitCode: $process->getExitCode() ?? 1,
                output: '',
                errorOutput: $errorOutput,
            );
        } catch (ProcessTimedOutException) {
            $process->stop(3);

            return new DockerProcessResult(
                command: $this->sanitizeCommand($command),
                exitCode: 124,
                output: '',
                errorOutput: 'Docker command timed out.',
                timedOut: true,
            );
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function runProcess(Process $process, array $command): DockerProcessResult
    {
        try {
            if ($this->progressCallback === null) {
                $process->run();
            } else {
                $process->start();

                while ($process->isRunning()) {
                    $process->checkTimeout();
                    $now = microtime(true);

                    if ($now - $this->lastProgressAt >= $this->progressIntervalSeconds) {
                        $this->lastProgressAt = $now;
                        ($this->progressCallback)();
                    }

                    usleep(200000);
                }

                $process->wait();
            }

            return new DockerProcessResult(
                command: $this->sanitizeCommand($command),
                exitCode: $process->getExitCode() ?? 1,
                output: $process->getOutput(),
                errorOutput: $process->getErrorOutput(),
            );
        } catch (ProcessTimedOutException) {
            $process->stop(3);

            return new DockerProcessResult(
                command: $this->sanitizeCommand($command),
                exitCode: 124,
                output: $process->getOutput(),
                errorOutput: 'Docker command timed out.',
                timedOut: true,
            );
        } catch (Throwable $exception) {
            if ($process->isRunning()) {
                $process->stop(3);
            }

            throw $exception;
        }
    }

    private function environment(array $environment): array
    {
        $home = storage_path('app/docker-cli/home');
        $config = storage_path('app/docker-cli/config');
        $dockerHost = (string) config('volumevault.docker_host', 'unix:///var/run/docker.sock');

        File::ensureDirectoryExists($home);
        File::ensureDirectoryExists($config);

        return array_merge($environment, [
            'DOCKER_CERT_PATH' => false,
            'DOCKER_CONFIG' => $config,
            'DOCKER_CONTEXT' => false,
            'DOCKER_HOST' => $dockerHost,
            'DOCKER_TLS' => false,
            'DOCKER_TLS_VERIFY' => false,
            'HOME' => $home,
            'XDG_CONFIG_HOME' => $config,
        ]);
    }

    private function sanitizeCommand(array $command): array
    {
        return array_map(function (string $argument): string {
            foreach (self::SECRET_KEYS as $key) {
                if (str_contains(strtoupper($argument), $key)) {
                    if (str_contains($argument, '=')) {
                        return preg_replace('/=.*/', '=********', $argument) ?: '********';
                    }

                    return $argument;
                }
            }

            return $argument;
        }, $command);
    }
}
