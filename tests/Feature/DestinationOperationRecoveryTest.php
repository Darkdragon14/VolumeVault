<?php

namespace Tests\Feature;

use App\Actions\Docker\CleanupDestinationOperationHelper;
use App\Jobs\RunDestinationOperation;
use App\Models\AgentOperation;
use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Services\Agents\AgentLifecycle;
use App\Services\BackupDestinations\DestinationOperations;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\ExecuteDestinationOperation;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DestinationOperationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.mode' => 'hybrid']);
        Queue::fake();
    }

    private function destination(string $provider = 'docker_volume'): BackupDestination
    {
        return BackupDestination::create(['name' => 'Local archives', 'provider' => $provider, 'docker_host_id' => 1,
            'bucket' => 'archives', 'access_key_id' => 'access', 'secret_access_key' => 'secret-value',
            'settings' => $provider === 'docker_volume' ? ['volume_name' => 'archives'] : ($provider === 'local' ? ['archive_path' => '/srv/archives'] : []), 'is_active' => true]);
    }

    public static function helperActions(): array
    {
        return [['test'], ['list'], ['stats']];
    }

    #[DataProvider('helperActions')]
    public function test_helper_timeout_cannot_complete_until_removal_and_absence_are_confirmed(string $action): void
    {
        $operations = app(DestinationOperations::class);
        $operation = $operations->create($this->destination(), $action);
        $process = new class($operation->id) extends DockerProcess
        {
            public bool $cleanupAllowed = false;

            public bool $alive = false;

            public int $launches = 0;

            public function __construct(private string $id) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if ($command[1] === 'run') {
                    $expected = CleanupDestinationOperationHelper::name($this->id);
                    Assert::assertContains('--name', $command);
                    Assert::assertContains($expected, $command);
                    Assert::assertSame($expected, AgentOperation::findOrFail($this->id)->context['helper_name']);
                    $this->alive = true;
                    $this->launches++;
                    if (AgentOperation::findOrFail($this->id)->destination_action === 'stats') {
                        throw new \RuntimeException('secret-value CLI exception');
                    }

                    return new DockerProcessResult($command, 124, '', 'secret-value CLI timeout', true);
                }
                if ($command[1] === 'rm') {
                    if (! $this->cleanupAllowed) {
                        return new DockerProcessResult($command, 1, '', 'daemon unavailable');
                    }
                    $this->alive = false;
                }
                if ($command[1] === 'container') {
                    return $this->alive ? new DockerProcessResult($command, 0, '[{}]', '')
                        : new DockerProcessResult($command, 1, '', 'Error: No such container: '.end($command));
                }

                return new DockerProcessResult($command, 0, '{}', '');
            }
        };
        app()->instance(DockerProcess::class, $process);
        $job = new RunDestinationOperation($operation->id);
        $job->handle($operations, app(ExecuteDestinationOperation::class));
        $this->assertTrue($process->alive);
        $this->assertSame('running', $operation->fresh()->status);
        $this->assertNull($operation->fresh()->result);
        $host = DockerHost::findOrFail(1);
        $this->assertSame(1, app(AgentLifecycle::class)->activeOperations($host));
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $job->failed(new \RuntimeException('secret-value'));
        $this->assertSame('running', $operation->fresh()->status);
        $process->cleanupAllowed = true;
        $job->handle($operations, app(ExecuteDestinationOperation::class));
        $this->assertSame(1, $process->launches);
        $this->assertFalse($process->alive);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertTrue($operation->fresh()->result['cleanup_complete']);
        $this->assertSame('failed', $operation->fresh()->result['status']);
        $this->assertStringNotContainsString('secret-value', json_encode($operations->safe($operation->fresh())));
        $this->assertSame(0, app(AgentLifecycle::class)->activeOperations($host));
    }

    public function test_local_creation_and_queued_claim_respect_maintenance_and_sweep_resumes_waiting_work(): void
    {
        $operations = app(DestinationOperations::class);
        $destination = $this->destination('local');
        $operation = $operations->create($destination, 'test');
        $host = DockerHost::findOrFail(1);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        try {
            $operations->create($destination, 'test');
            $this->fail('Maintenance admission accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('docker_host_id', $exception->errors());
        }
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('testReadOnly')->once()->andReturnUsing(function () use ($host): void {
            $this->assertSame(1, app(AgentLifecycle::class)->activeOperations($host));
        });
        $job = new RunDestinationOperation($operation->id);
        $job->handle($operations, app(ExecuteDestinationOperation::class));
        $this->assertSame('pending', $operation->fresh()->status);
        $this->assertSame(0, app(AgentLifecycle::class)->activeOperations($host));
        Queue::fake();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        Queue::assertNothingPushed();
        $host->forceFill(['maintenance_requested_at' => null])->save();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        Queue::assertPushed(RunDestinationOperation::class, fn ($queued): bool => $queued->operationId === $operation->id);
        $job->handle($operations, app(ExecuteDestinationOperation::class));
        $this->assertSame('success', $operation->fresh()->result['status']);
    }

    public function test_socket_free_network_work_is_available_during_local_maintenance_in_orchestrator_mode(): void
    {
        config(['volumevault.mode' => 'orchestrator']);
        $host = DockerHost::findOrFail(1);
        $host->forceFill(['maintenance_requested_at' => now()])->save();
        $operations = app(DestinationOperations::class);
        $operation = $operations->create($this->destination('aws_s3'), 'test');
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $this->mock(DestinationStorage::class)->shouldReceive('testReadOnly')->once()->andReturnUsing(function () use ($host): void {
            $this->assertSame(0, app(AgentLifecycle::class)->activeOperations($host));
        });
        (new RunDestinationOperation($operation->id))->handle($operations, app(ExecuteDestinationOperation::class));
        $this->assertSame('success', $operation->fresh()->result['status']);
    }

    public function test_expired_running_claim_is_swept_for_cleanup_without_replaying_and_legacy_unknown_helpers_fail_closed(): void
    {
        $operations = app(DestinationOperations::class);
        $operation = $operations->create($this->destination(), 'stats');
        $operation->forceFill(['status' => 'running', 'claimed_at' => now()->subHour(), 'last_progress_at' => now()->subHour()])->save();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        Queue::fake();
        $this->artisan('volumevault:dispatch-queued-runs')->assertSuccessful();
        Queue::assertPushed(RunDestinationOperation::class);
        (new RunDestinationOperation($operation->id))->handle($operations, app(ExecuteDestinationOperation::class));
        $this->assertSame('running', $operation->fresh()->status);
        $this->assertNull($operation->fresh()->result);
    }
}
