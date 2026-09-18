<?php

namespace Tests\Feature;

use App\Actions\Docker\CollectAgentInventory;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AgentInventoryCollectionTest extends TestCase
{
    public function test_availability_uses_a_bounded_lightweight_probe(): void
    {
        $process = $this->mock(DockerProcess::class);
        $process->shouldReceive('run')->once()->with(['docker', 'info', '--format', '{{.ID}}'], 10)
            ->andReturn(new DockerProcessResult([], 0, 'engine-id', ''));

        $this->assertTrue((new CollectAgentInventory($process))->available());
    }

    #[DataProvider('incompleteInventories')]
    public function test_incomplete_inventory_is_never_returned(string $stage): void
    {
        $process = $this->mock(DockerProcess::class);
        $process->shouldReceive('whileMonitoring')->once()->andReturnUsing(fn ($progress, $execute) => $execute());
        $process->shouldReceive('run')->andReturnUsing(function (array $command) use ($stage): DockerProcessResult {
            $output = match ($command[1].' '.($command[2] ?? '')) {
                'volume ls' => $stage === 'volume_listing' ? 'invalid json' : '{"Name":"data"}',
                'volume inspect' => $stage === 'inspection' ? '' : '[{"Name":"data","Driver":"local","Mountpoint":"/data"}]',
                default => $stage === 'container_identity' ? '{"Names":"web"}' : 'invalid json',
            };

            return new DockerProcessResult($command, $stage === 'inspection' && $command[2] === 'inspect' ? 1 : 0, $output, '');
        });
        $this->expectException(RuntimeException::class);
        (new CollectAgentInventory($process))->handle(fn () => null);
    }

    public static function incompleteInventories(): array
    {
        return [['volume_listing'], ['inspection'], ['container_listing'], ['container_identity']];
    }

    public function test_progress_runs_during_a_silent_inspection_and_can_interrupt_the_actual_process(): void
    {
        $process = new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $script = $command[2] === 'ls'
                    ? 'echo json_encode(["Name" => "data"]);'
                    : 'sleep(8); echo json_encode([["Name"=>"data","Driver"=>"local","Mountpoint"=>"/data"]]);';

                return parent::run([PHP_BINARY, '-r', $script], $timeout, $environment);
            }
        };
        $calls = 0;
        $interrupted = new RuntimeException('Stop the collection.');
        $startedAt = hrtime(true);
        try {
            (new CollectAgentInventory($process))->handle(function () use (&$calls, $interrupted): void {
                if (++$calls >= 2) {
                    throw $interrupted;
                }
            });
            $this->fail('Expected the inspection to be interrupted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($interrupted, $exception);
            $this->assertGreaterThanOrEqual(2, $calls);
            $this->assertLessThan(5, (hrtime(true) - $startedAt) / 1e9);
        }
    }
}
