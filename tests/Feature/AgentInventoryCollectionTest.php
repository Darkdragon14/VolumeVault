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
    #[DataProvider('unusableLabelSnapshots')]
    public function test_optional_label_limits_do_not_block_ordinary_inventory(int $count, int $valueBytes): void
    {
        $inspected = [];
        $ordinary = [];
        foreach (range(1, $count) as $index) {
            $id = hash('sha256', (string) $index);
            $ordinary[] = ['ID' => $id, 'Names' => 'app-'.$index, 'State' => 'running'];
            $inspected[] = ['Id' => $id, 'Name' => '/app-'.$index, 'Created' => '2026-09-01T00:00:00Z', 'State' => ['Running' => true],
                'Config' => ['Labels' => ['dev.darkdragon14.volumevault.backup.include-paths' => str_repeat('x', $valueBytes)]],
                'Mounts' => [['Type' => 'volume', 'Name' => 'data', 'Destination' => '/data']]];
        }
        $process = $this->mock(DockerProcess::class);
        $process->shouldReceive('whileMonitoring')->once()->andReturnUsing(fn ($progress, $execute) => $execute());
        $process->shouldReceive('run')->andReturnUsing(function (array $command) use ($inspected, $ordinary, $count): DockerProcessResult {
            $output = match ($command[1]) {
                'volume' => $command[2] === 'ls' ? '{"Name":"data"}' : '[{"Name":"data","Driver":"local","Mountpoint":"/data"}]',
                'ps' => in_array('{{.ID}}', $command, true) ? implode("\n", array_column($ordinary, 'ID')) : implode("\n", array_map(json_encode(...), $ordinary)),
                'inspect' => json_encode($inspected),
                default => '{"version":"29.0.0","containers":'.$count.'}',
            };

            return new DockerProcessResult($command, 0, $output, '');
        });

        $inventory = (new CollectAgentInventory($process))->handle(fn () => null);
        $this->assertSame(['complete' => false, 'containers' => []], $inventory['label_inventory']);
        $this->assertCount($count, $inventory['containers']);
        $this->assertSame('data', $inventory['volumes'][0]['name']);
        $this->assertSame('29.0.0', $inventory['docker_version']);
    }

    public static function unusableLabelSnapshots(): array
    {
        return ['oversized value' => [1, 16385], 'aggregate transport budget' => [140, 16000]];
    }

    public function test_complete_label_inventory_contains_only_backup_identity_labels_and_named_volume_mounts(): void
    {
        $id = str_repeat('a', 64);
        $process = $this->mock(DockerProcess::class);
        $process->shouldReceive('whileMonitoring')->once()->andReturnUsing(fn ($progress, $execute) => $execute());
        $process->shouldReceive('run')->andReturnUsing(function (array $command) use ($id): DockerProcessResult {
            $output = match ($command[1].' '.($command[2] ?? '')) {
                'volume ls' => '{"Name":"data"}',
                'volume inspect' => '[{"Name":"data","Driver":"local","Mountpoint":"/data"}]',
                'ps -a' => in_array('{{.ID}}', $command, true) ? $id : json_encode(['ID' => $id, 'Names' => 'app', 'State' => 'running']),
                'inspect '.$id => json_encode([['Id' => $id, 'Name' => '/app', 'Created' => '2026-09-01T00:00:00Z', 'State' => ['Running' => true],
                    'Config' => ['Labels' => ['DATABASE_PASSWORD' => 'never-send', 'dev.darkdragon14.volumevault.enable' => 'true', 'dev.darkdragon14.volumevault.backup.mount' => '/data', 'com.docker.compose.config-hash' => 'deployment']],
                    'Mounts' => [['Type' => 'volume', 'Name' => 'data', 'Destination' => '/data', 'Source' => '/var/lib/docker/volumes/data/_data'], ['Type' => 'bind', 'Source' => '/etc', 'Destination' => '/host']]]]),
                default => '{"version":"29.0.0","containers":1}',
            };

            return new DockerProcessResult($command, 0, $output, '');
        });
        $inventory = (new CollectAgentInventory($process))->handle(fn () => null);
        $this->assertTrue($inventory['label_inventory']['complete']);
        $container = $inventory['label_inventory']['containers'][0];
        $this->assertSame([['name' => 'data', 'destination' => '/data']], $container['mounts']);
        $this->assertArrayNotHasKey('DATABASE_PASSWORD', $container['labels']);
        $this->assertSame('deployment', $container['labels']['com.docker.compose.config-hash']);
        $this->assertStringNotContainsString('/var/lib/docker', json_encode($container));
    }

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
