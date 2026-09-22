<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Services\Docker\DockerProcess;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AuditHostPathAllowlistCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_schedule_audits_remote_hosts_in_orchestrator_mode_without_docker(): void
    {
        $this->travelTo(now()->startOfHour());
        config(['volumevault.mode' => 'orchestrator']);
        $host = DockerHost::factory()->create([
            'agent_registered_at' => now(), 'last_seen_at' => now(),
            'last_inventory_at' => now(), 'agent_host_path_allowlist' => [],
        ]);
        $this->hostPathJob('/remote/data');
        BackupJob::query()->update(['docker_host_id' => $host->id]);
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        Process::fake();
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'host-path-allowlist:audit'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->filtersPass($this->app));
        $this->assertSame(1, preg_match('/volumevault:host-path-allowlist:audit --all$/', $event->command, $matches));
        $this->artisan($matches[0])->expectsOutputToContain('local_disabled')
            ->expectsOutputToContain('remote agent configuration on host '.$host->id)->assertFailed();
        $this->assertSame($host->id, ActivityLog::where('event_type', 'host_path_allowlist_misconfigured')->sole()->context['docker_host_id']);
        Process::assertNothingRan();
    }

    public function test_default_ignores_remote_paths_but_explicit_audits_work_in_orchestrator_mode(): void
    {
        $host = DockerHost::factory()->create([
            'agent_registered_at' => now(), 'last_seen_at' => now(),
            'last_inventory_at' => now(), 'agent_host_path_allowlist' => [],
        ]);
        $this->hostPathJob('/remote/data');
        BackupJob::query()->update(['docker_host_id' => $host->id]);
        $this->artisan('volumevault:host-path-allowlist:audit')
            ->expectsOutputToContain('nothing to allowlist')->assertSuccessful();

        config(['volumevault.mode' => 'orchestrator']);
        $this->artisan('volumevault:host-path-allowlist:audit', ['--host' => $host->id])
            ->expectsOutputToContain('remote agent configuration on host '.$host->id)
            ->doesntExpectOutputToContain('your .env')->assertFailed();
        $this->artisan('volumevault:host-path-allowlist:audit', ['--all' => true])
            ->expectsOutputToContain('local_disabled')
            ->expectsOutputToContain('VOLUMEVAULT_HOST_PATH_ALLOWLIST=/remote/data')->assertFailed();
        $host->forceFill(['last_inventory_at' => now()->subHour()])->save();
        $this->artisan('volumevault:host-path-allowlist:audit', ['--host' => $host->id])
            ->expectsOutputToContain('policy_unknown')
            ->doesntExpectOutputToContain('VOLUMEVAULT_HOST_PATH_ALLOWLIST=')->assertExitCode(2);
    }

    public function test_invalid_host_selections_are_rejected(): void
    {
        foreach ([['--host' => 'abc'], ['--host' => '0'], ['--host' => '999999'], ['--host' => '1', '--all' => true]] as $options) {
            $this->artisan('volumevault:host-path-allowlist:audit', $options)->assertExitCode(2);
        }
    }

    public function test_it_succeeds_when_nothing_uses_host_paths(): void
    {
        config(['volumevault.host_path_allowlist' => []]);

        $this->artisan('volumevault:host-path-allowlist:audit')
            ->expectsOutputToContain('nothing to allowlist')
            ->assertSuccessful();
    }

    public function test_it_fails_and_suggests_a_value_when_paths_are_blocked(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $this->hostPathJob('/srv/data');

        $this->artisan('volumevault:host-path-allowlist:audit')
            ->expectsOutputToContain('VOLUMEVAULT_HOST_PATH_ALLOWLIST=/srv/data')
            ->assertFailed();

        $this->assertDatabaseHas('activity_logs', ['event_type' => 'host_path_allowlist_misconfigured']);
    }

    public function test_it_succeeds_when_the_allowlist_already_covers_paths(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv']]);
        $this->hostPathJob('/srv/data');

        $this->artisan('volumevault:host-path-allowlist:audit')
            ->expectsOutputToContain('already covers')
            ->assertSuccessful();

        $this->assertSame(0, ActivityLog::where('event_type', 'host_path_allowlist_misconfigured')->count());
    }

    private function hostPathJob(string $hostPath): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'k',
            'secret_access_key' => 's',
        ]);

        BackupJob::create([
            'name' => 'Host job',
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => $hostPath,
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }
}
