<?php

namespace Tests\Feature;

use App\Actions\Backup\ReconcileDockerLabelBackupJobs;
use App\Actions\Docker\ListDockerVolumes;
use App\Actions\Docker\ReadDockerHostInfo;
use App\Actions\Docker\SyncDockerVolumes;
use App\Models\DockerHost;
use App\Models\User;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DockerHostInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_docker_info_reads_server_version_and_total_container_count_with_bounded_timeout(): void
    {
        $this->mock(DockerProcess::class)->shouldReceive('run')->once()->with([
            'docker', 'info', '--format', '{"version":{{json .ServerVersion}},"containers":{{json .Containers}}}',
        ], 10)->andReturn(new DockerProcessResult([], 0, '{"version":"28.1.0","containers":0}', ''));

        $this->assertSame(['version' => '28.1.0', 'containers' => 0], app(ReadDockerHostInfo::class)->handle());
    }

    #[DataProvider('invalidResponses')]
    public function test_failed_or_malformed_docker_info_does_not_invent_metrics(int $code, string $output): void
    {
        $this->mock(DockerProcess::class)->shouldReceive('run')->once()->andReturn(new DockerProcessResult([], $code, $output, 'private daemon error'));
        $this->expectException(RuntimeException::class);
        try {
            app(ReadDockerHostInfo::class)->handle();
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private daemon error', $exception->getMessage());
            throw $exception;
        }
    }

    public static function invalidResponses(): array
    {
        return [
            [1, ''], [0, 'invalid'], [0, '{"containers":1}'],
            [0, '{"version":"","containers":1}'], [0, '{"version":"28.1.0","containers":-1}'],
            [0, '{"version":"28.1.0","containers":"12"}'],
        ];
    }

    public function test_successful_local_sync_populates_card_without_querying_docker_during_page_reads(): void
    {
        config(['volumevault.update_check.enabled' => false, 'app.version' => 'main']);
        $this->travelTo(now()->startOfSecond());
        $remote = DockerHost::factory()->create();
        $remote->forceFill(['docker_version' => 'remote-version', 'docker_container_count' => 19])->save();
        $remoteBefore = $remote->refresh()->getAttributes();
        $this->mock(ListDockerVolumes::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(ReconcileDockerLabelBackupJobs::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(ReadDockerHostInfo::class)->shouldReceive('handle')->once()->andReturn(['version' => '28.1.0', 'containers' => 42]);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');

        app(SyncDockerVolumes::class)->handle();
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $this->assertSame(42, $local->docker_container_count);
        $this->assertSame('28.1.0', $local->docker_version);
        $this->assertSame('ready', $local->docker_status);
        $this->assertTrue($local->last_inventory_at->equalTo(now()));
        $this->assertNull($local->last_seen_at);
        $this->assertSame($remoteBefore, $remote->refresh()->getAttributes());

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/docker-hosts')->assertInertia(fn (Assert $page) => $page
            ->component('DockerHosts/Index')->where('hosts.0.container_count', 42)
            ->where('hosts.0.docker_version', '28.1.0')->where('hosts.0.agent_version', 'main')
            ->where('hosts.0.last_inventory_at', now()->toISOString()));
    }

    public function test_failed_volume_discovery_preserves_last_successful_sync_and_metrics(): void
    {
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $host->forceFill(['docker_version' => '28.1.0', 'docker_container_count' => 7, 'last_inventory_at' => now()->subHour(), 'docker_status' => 'ready'])->save();
        $syncedAt = $host->last_inventory_at;
        $this->mock(ListDockerVolumes::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Discovery failed.'));
        $this->mock(ReadDockerHostInfo::class)->shouldNotReceive('handle');

        try {
            app(SyncDockerVolumes::class)->handle();
            $this->fail('Expected discovery failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Discovery failed.', $exception->getMessage());
        }
        $host->refresh();
        $this->assertSame('unavailable', $host->docker_status);
        $this->assertSame(7, $host->docker_container_count);
        $this->assertSame('28.1.0', $host->docker_version);
        $this->assertTrue($syncedAt->equalTo($host->last_inventory_at));
    }

    public function test_optional_info_failure_keeps_successful_volume_sync_and_last_known_metrics(): void
    {
        $this->travelTo(now()->startOfSecond());
        $host = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $host->forceFill(['docker_version' => '28.1.0', 'docker_container_count' => 7])->save();
        $this->mock(ListDockerVolumes::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(ReconcileDockerLabelBackupJobs::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(ReadDockerHostInfo::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Info unavailable.'));

        $this->assertSame(0, app(SyncDockerVolumes::class)->handle()['found']);
        $host->refresh();
        $this->assertTrue($host->last_inventory_at->equalTo(now()));
        $this->assertSame(7, $host->docker_container_count);
        $this->assertSame('unavailable', $host->docker_status);
    }

    public function test_orchestrator_only_never_collects_local_docker_metrics(): void
    {
        config(['volumevault.mode' => 'orchestrator']);
        $this->mock(ReadDockerHostInfo::class)->shouldNotReceive('handle');
        $this->mock(ListDockerVolumes::class)->shouldNotReceive('handle');
        $this->expectException(ValidationException::class);
        app(SyncDockerVolumes::class)->handle();
    }
}
