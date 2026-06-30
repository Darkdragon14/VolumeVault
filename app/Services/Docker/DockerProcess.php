<?php

namespace App\Services\Docker;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DockerProcess
{
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
            $process->run();

            return new DockerProcessResult(
                command: $this->sanitizeCommand($command),
                exitCode: $process->getExitCode() ?? 1,
                output: $process->getOutput(),
                errorOutput: $process->getErrorOutput(),
            );
        } catch (ProcessTimedOutException $exception) {
            $process->stop(3);

            return new DockerProcessResult(
                command: $this->sanitizeCommand($command),
                exitCode: 124,
                output: $process->getOutput(),
                errorOutput: 'Docker command timed out.',
                timedOut: true,
            );
        }
    }

    private function environment(array $environment): array
    {
        $home = storage_path('app/docker-cli/home');
        $config = storage_path('app/docker-cli/config');

        File::ensureDirectoryExists($home);
        File::ensureDirectoryExists($config);

        return array_merge($environment, [
            'DOCKER_CONFIG' => $config,
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
