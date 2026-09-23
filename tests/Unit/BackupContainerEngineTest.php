<?php

namespace Tests\Unit;

use App\Services\Backup\BackupContainerEngine;
use App\Services\Backup\BackupContainerPlan;
use App\Services\Docker\DockerProcessResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class BackupContainerEngineTest extends TestCase
{
    private array $events = [];

    private array $commands = [];

    private ?string $failureAt = null;

    private ?Throwable $executionError = null;

    private ?Throwable $removalError = null;

    private ?array $cleanup = null;

    private bool $monitoring = false;

    public function test_single_run_preserves_command_environment_and_callback_order_without_laravel(): void
    {
        $plan = new BackupContainerPlan(
            containerName: 'backup-1',
            image: 'offen/test:latest',
            dockerHost: 'unix:///run/custom.sock',
            dockerNetwork: ' backup-net ',
            sourceMountArguments: ['-v', 'data:/backup/data:ro'],
            mounts: ['archives:/archive'],
            environment: ['SSH_PASSWORD' => 'sensitive-value'],
        );

        $result = $this->execute($plan, heartbeat: true);

        $this->assertTrue($result->successful());
        $this->assertSame(['identity:backup-1', 'heartbeat', 'run', 'remove:backup-1', 'cleanup'], $this->events);
        $this->assertSame([true, null], $this->cleanup);
        $this->assertSame([[
            ['docker', 'run', '--rm', '--name', 'backup-1', '--entrypoint', '/usr/bin/backup',
                '--network', 'backup-net', '-v', 'data:/backup/data:ro',
                '-v', '/run/custom.sock:/run/custom.sock:ro', '-v', 'archives:/archive',
                '--env', 'SSH_PASSWORD', '--env', 'DOCKER_HOST', 'offen/test:latest'],
            0,
            ['SSH_PASSWORD' => 'sensitive-value', 'DOCKER_HOST' => 'unix:///run/custom.sock'],
            true,
        ]], $this->commands);
    }

    public function test_copy_lifecycle_monitors_all_execution_steps_and_removes_before_cleanup_notification(): void
    {
        $result = $this->execute($this->plan(copies: true), heartbeat: true);

        $this->assertTrue($result->successful());
        $this->assertSame(['identity:backup-1', 'heartbeat', 'create', 'cp', 'cp', 'start', 'remove:backup-1', 'cleanup'], $this->events);
        $this->assertSame([300, 300, 300, 0], array_column($this->commands, 1));
        $this->assertSame([true, true, true, true], array_column($this->commands, 3));
        $this->assertSame(['docker', 'create', '--name', 'backup-1', '--entrypoint', '/usr/bin/backup',
            '--mount', 'type=bind,src=/srv/data,dst=/backup/data,readonly',
            '--env', 'DOCKER_HOST', 'offen/test:latest'], $this->commands[0][0]);
        $this->assertSame(['docker', 'cp', '/secrets/key', 'backup-1:/tmp/key'], $this->commands[1][0]);
        $this->assertSame(['docker', 'cp', '/secrets/other', 'backup-1:/tmp/other'], $this->commands[2][0]);
        $this->assertSame(['docker', 'start', '--attach', 'backup-1'], $this->commands[3][0]);
        $this->assertSame([['DOCKER_HOST' => 'tcp://docker:2375'], [], [], []], array_column($this->commands, 2));
        $this->assertSame([true, null], $this->cleanup);
    }

    #[DataProvider('failureStages')]
    public function test_failed_commands_short_circuit_and_cleanup_preserves_the_result(string $stage, array $operations): void
    {
        $this->failureAt = $stage;
        $this->removalError = new RuntimeException('removal denied');

        $result = $this->execute($this->plan(copies: $stage !== 'run'));

        $this->assertSame(17, $result->exitCode);
        $this->assertSame($stage.' failed', $result->errorOutput);
        $this->assertSame($operations, array_map(fn (array $call): string => $call[0][1], $this->commands));
        $this->assertSame([false, $this->removalError], $this->cleanup);
        $this->assertSame('cleanup', end($this->events));
    }

    #[DataProvider('failureStages')]
    public function test_execution_exceptions_survive_cleanup_failures(string $stage, array $operations): void
    {
        $this->failureAt = $stage;
        $this->executionError = new RuntimeException('execution interrupted');
        $this->removalError = new RuntimeException('removal denied');

        try {
            $this->execute($this->plan(copies: $stage !== 'run'));
            $this->fail('Expected execution exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->executionError, $exception);
        }

        $this->assertSame($operations, array_map(fn (array $call): string => $call[0][1], $this->commands));
        $this->assertSame([false, $this->removalError], $this->cleanup);
    }

    public static function failureStages(): array
    {
        return [
            'run' => ['run', ['run']],
            'create' => ['create', ['create']],
            'copy' => ['cp', ['create', 'cp']],
            'start' => ['start', ['create', 'cp', 'cp', 'start']],
        ];
    }

    #[DataProvider('callbackFailures')]
    public function test_pre_execution_callback_failure_does_not_attempt_removal(string $stage): void
    {
        $this->failureAt = $stage;
        $this->executionError = new RuntimeException('callback failed');

        try {
            $this->execute($this->plan(copies: true), heartbeat: true);
            $this->fail('Expected callback exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->executionError, $exception);
        }

        $this->assertSame([], $this->commands);
        $this->assertNotContains('remove:backup-1', $this->events);
        $this->assertSame([true, null], $this->cleanup);
    }

    public static function callbackFailures(): array
    {
        return [['identity'], ['heartbeat']];
    }

    #[DataProvider('executionModes')]
    public function test_successful_backup_with_failed_removal_reports_pending_cleanup(bool $copies): void
    {
        $this->removalError = new RuntimeException('removal denied');

        $this->assertTrue($this->execute($this->plan(copies: $copies))->successful());
        $this->assertSame([false, $this->removalError], $this->cleanup);
    }

    #[DataProvider('executionModes')]
    public function test_heartbeat_exception_after_execution_still_attempts_removal(bool $copies): void
    {
        $this->failureAt = 'heartbeat-after';
        $this->executionError = new RuntimeException('heartbeat interrupted');
        $this->removalError = new RuntimeException('removal denied');

        try {
            $this->execute($this->plan(copies: $copies), heartbeat: true);
            $this->fail('Expected heartbeat exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->executionError, $exception);
        }

        $this->assertSame(['remove:backup-1', 'cleanup'], array_slice($this->events, -2));
        $this->assertSame([false, $this->removalError], $this->cleanup);
    }

    public static function executionModes(): array
    {
        return ['run' => [false], 'create' => [true]];
    }

    public function test_plan_arrays_are_immutable(): void
    {
        $plan = $this->plan(copies: true);

        $this->expectException(\Error::class);
        $plan->copies['/new'] = '/tmp/new';
    }

    private function plan(bool $copies): BackupContainerPlan
    {
        return new BackupContainerPlan(
            containerName: 'backup-1',
            image: 'offen/test:latest',
            dockerHost: 'tcp://docker:2375',
            dockerNetwork: '   ',
            sourceMountArguments: ['--mount', 'type=bind,src=/srv/data,dst=/backup/data,readonly'],
            copies: $copies ? ['/secrets/key' => '/tmp/key', '/secrets/other' => '/tmp/other'] : [],
        );
    }

    private function execute(BackupContainerPlan $plan, bool $heartbeat = false): DockerProcessResult
    {
        $engine = new BackupContainerEngine(
            function (array $command, int $timeout, array $environment): DockerProcessResult {
                $stage = $command[1];
                $this->events[] = $stage;
                $this->commands[] = [$command, $timeout, $environment, $this->monitoring];

                if ($stage === $this->failureAt) {
                    if ($this->executionError !== null) {
                        throw $this->executionError;
                    }

                    return new DockerProcessResult($command, 17, '', $stage.' failed');
                }

                return new DockerProcessResult($command, 0, 'ok', '');
            },
            function (callable $callback, callable $operation): DockerProcessResult {
                $this->monitoring = true;

                try {
                    $callback();

                    $result = $operation();

                    if ($this->failureAt === 'heartbeat-after') {
                        throw $this->executionError;
                    }

                    return $result;
                } finally {
                    $this->monitoring = false;
                }
            },
            function (string $name): void {
                $this->assertFalse($this->monitoring);
                $this->events[] = 'remove:'.$name;

                if ($this->removalError !== null) {
                    throw $this->removalError;
                }
            },
        );

        return $engine->handle(
            $plan,
            function (string $name): void {
                $this->events[] = 'identity:'.$name;

                if ($this->failureAt === 'identity') {
                    throw $this->executionError;
                }
            },
            function (bool $cleaned, ?Throwable $error): void {
                $this->events[] = 'cleanup';
                $this->cleanup = [$cleaned, $error];
            },
            $heartbeat ? function (): void {
                $this->events[] = 'heartbeat';

                if ($this->failureAt === 'heartbeat') {
                    throw $this->executionError;
                }
            } : null,
        );
    }
}
