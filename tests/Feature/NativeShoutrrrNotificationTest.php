<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\NativeShoutrrrProcess;
use App\Services\Notifications\SendShoutrrrNotification;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class NativeShoutrrrNotificationTest extends TestCase
{
    #[DataProvider('channels')]
    public function test_orchestrator_tests_every_provider_without_docker(string $service, string $url, string $resolvedUrl): void
    {
        config(['volumevault.mode' => 'orchestrator']);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        Process::fake();
        Process::preventStrayProcesses();

        $result = app(SendShoutrrrNotification::class)->sendTest(new NotificationChannel([
            'service' => $service, 'url' => $url,
        ]));

        $this->assertTrue($result->successful());
        Process::assertRan(function (PendingProcess $process) use ($resolvedUrl): bool {
            return is_array($process->command)
                && $process->command[0] === NativeShoutrrrProcess::BINARY
                && $process->command[1] === 'send'
                && ! in_array($resolvedUrl, $process->command, true)
                && $process->environment === ['SHOUTRRR_URL' => $resolvedUrl]
                && $process->timeout === 60
                && $process->quietly;
        });
        Process::assertRanTimes(fn (): bool => true, 1);
    }

    public static function channels(): array
    {
        return [
            'discord' => ['discord', 'discord://secret@123', 'discord://secret@123?splitLines=No'],
            'telegram' => ['telegram', 'telegram://secret@telegram?chats=123', 'telegram://secret@telegram?chats=123'],
            'ntfy' => ['ntfy', 'ntfy://secret@ntfy.example/topic', 'ntfy://secret@ntfy.example/topic'],
            'gotify' => ['gotify', 'gotify://gotify.example/secret', 'gotify://gotify.example/secret'],
            'smtp' => ['smtp', 'smtp://user:secret@mail.example:587/?to=a@example.com', 'smtp://user:secret@mail.example:587/?to=a@example.com'],
            'advanced' => ['advanced', 'slack://secret@channel', 'slack://secret@channel'],
            'webhook' => ['webhook', '{"start":"generic+https://example.com/secret"}', 'generic+https://example.com/secret'],
        ];
    }

    public function test_hybrid_keeps_the_docker_transport(): void
    {
        config(['volumevault.mode' => 'hybrid']);
        Process::fake();
        $this->mock(NativeShoutrrrProcess::class)->shouldNotReceive('send');
        $this->mock(DockerProcess::class)->shouldReceive('run')->once()
            ->withArgs(fn (array $command, int $timeout, array $environment): bool => $command[0] === 'docker'
                && $timeout === 60 && $environment === ['SHOUTRRR_URL' => 'ntfy://example.com/topic'])
            ->andReturn(new DockerProcessResult([], 0, '', ''));

        $this->assertTrue(app(SendShoutrrrNotification::class)->sendTest(new NotificationChannel([
            'service' => 'ntfy', 'url' => 'ntfy://example.com/topic',
        ]))->successful());
        Process::assertNothingRan();
    }

    public function test_empty_webhook_does_not_invoke_either_transport(): void
    {
        config(['volumevault.mode' => 'orchestrator']);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        Process::fake();

        $result = app(SendShoutrrrNotification::class)->sendTest(new NotificationChannel([
            'service' => 'webhook', 'url' => '{}',
        ]));

        $this->assertFalse($result->successful());
        Process::assertNothingRan();
    }

    #[DataProvider('exitCodes')]
    public function test_native_output_is_discarded_even_on_success(int $exitCode): void
    {
        Process::fake(['*' => Process::result(output: 'secret', errorOutput: 'parsed secret', exitCode: $exitCode)]);

        $result = app(NativeShoutrrrProcess::class)->send('ntfy://secret@example.com/topic', 'Title', 'Message');

        $this->assertSame($exitCode === 0, $result->successful());
        $this->assertSame($exitCode, $result->exitCode);
        $this->assertSame('', $result->output);
        $this->assertSame($exitCode === 0 ? '' : 'Notification delivery failed.', $result->errorOutput);
        $this->assertStringNotContainsString('secret', serialize($result));
    }

    public static function exitCodes(): array
    {
        return [[0], [1], [127]];
    }

    public function test_process_exceptions_never_report_secrets(): void
    {
        Log::spy();
        Process::fake(fn () => throw new RuntimeException('invalid parsed secret'));

        $result = app(NativeShoutrrrProcess::class)->send('ntfy://secret@example.com/topic', 'Title', 'Message');

        $this->assertFalse($result->successful());
        $this->assertSame('Notification delivery failed.', $result->errorOutput);
        $this->assertStringNotContainsString('secret', serialize($result));
        Log::shouldNotHaveReceived('error');
    }

    public function test_timeout_is_sanitized_and_marked_as_timed_out(): void
    {
        $original = new SymfonyTimeoutException(new SymfonyProcess(['shoutrrr', 'secret']), SymfonyTimeoutException::TYPE_GENERAL);
        Process::fake(fn () => throw new ProcessTimedOutException($original, Process::result(errorOutput: 'secret')));

        $result = app(NativeShoutrrrProcess::class)->send('ntfy://secret@example.com/topic', 'Title', 'Message');

        $this->assertFalse($result->successful());
        $this->assertTrue($result->timedOut);
        $this->assertSame(124, $result->exitCode);
        $this->assertSame('Notification delivery timed out.', $result->errorOutput);
        $this->assertStringNotContainsString('secret', serialize($result));
    }
}
