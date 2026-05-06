<?php

namespace App\Services\Docker;

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
        $process = new Process($command, null, $environment ?: null, null, $timeout);

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
