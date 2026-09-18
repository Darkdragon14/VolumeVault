<?php

namespace App\Services\Notifications;

use App\Services\Docker\DockerProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

class NativeShoutrrrProcess
{
    public const BINARY = '/usr/local/bin/shoutrrr';

    public function send(#[\SensitiveParameter] string $url, string $title, string $message): DockerProcessResult
    {
        $command = [self::BINARY, 'send'];

        try {
            $result = Process::timeout(60)
                ->env(['SHOUTRRR_URL' => $url])
                ->quietly()
                ->run([...$command, '--title', $title, '--message', $message]);

            return new DockerProcessResult(
                command: $command,
                exitCode: $result->exitCode(),
                output: '',
                errorOutput: $result->successful() ? '' : 'Notification delivery failed.',
            );
        } catch (ProcessTimedOutException) {
            return new DockerProcessResult($command, 124, '', 'Notification delivery timed out.', true);
        } catch (Throwable) {
            return new DockerProcessResult($command, 1, '', 'Notification delivery failed.');
        }
    }
}
