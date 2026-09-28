<?php

namespace Tests\Feature;

use App\Actions\Docker\ListDockerContainers;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\User;
use App\Services\BackupSources\HostPathAllowlistAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HostPathAllowlistAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_report_uses_loaded_hosts_without_queries_and_reports_local_mode(): void
    {
        config(['volumevault.host_path_allowlist' => ['/central//data/']]);
        $local = DockerHost::findOrFail(DockerHost::LOCAL_ID);
        $remote = $this->remoteHost(['/remote//data/']);
        $audit = app(HostPathAllowlistAudit::class);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(['status' => 'known', 'reported_at' => null, 'freshness' => 'current', 'prefixes' => ['/central/data']], $audit->policyReport($local));
        $this->assertSame(['/remote/data'], $audit->policyReport($remote)['prefixes']);
        config(['volumevault.mode' => 'orchestrator']);
        $this->assertSame('local_disabled', $audit->policyReport($local)['status']);
        $this->assertSame([], $audit->policyReport($local)['prefixes']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_create_and_edit_forms_expose_safe_policy_metadata_for_each_host(): void
    {
        $this->freezeTime();
        config(['volumevault.host_path_allowlist' => ['/central//data/']]);
        $this->mock(ListDockerContainers::class)->shouldReceive('handle')->andReturn([]);
        $host = $this->remoteHost(['/remote//data/']);
        $job = $this->hostPathJob('/remote/data');
        $job->update(['docker_host_id' => $host->id]);
        $this->actingAs(User::factory()->admin()->create());

        foreach ([
            ['attributes' => [], 'status' => 'known', 'prefixes' => ['/remote/data']],
            ['attributes' => ['agent_host_path_allowlist' => []], 'status' => 'known', 'prefixes' => []],
            ['attributes' => ['last_inventory_at' => now()->subMinutes(15)], 'status' => 'unknown', 'prefixes' => []],
            ['attributes' => ['last_seen_at' => now()->subSeconds(90)], 'status' => 'unknown', 'prefixes' => []],
            ['attributes' => ['agent_revoked_at' => now()], 'status' => 'unknown', 'prefixes' => []],
            ['attributes' => ['agent_host_path_allowlist' => null], 'status' => 'unknown', 'prefixes' => []],
        ] as $case) {
            $host->forceFill([
                'agent_host_path_allowlist' => ['/remote//data/'], 'last_inventory_at' => now(),
                'last_seen_at' => now(), 'agent_revoked_at' => null, ...$case['attributes'],
            ])->save();
            foreach (['/backup-jobs/create', '/backup-jobs/'.$job->id.'/edit'] as $url) {
                $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->component('BackupJobs/Form')
                    ->where('hosts', function ($hosts) use ($host, $case): bool {
                        $remote = collect($hosts)->firstWhere('id', $host->id);
                        $local = collect($hosts)->firstWhere('id', DockerHost::LOCAL_ID);
                        $this->assertSame($case['status'], $remote['host_path_policy']['status']);
                        $this->assertSame($case['prefixes'], $remote['host_path_policy']['prefixes']);
                        $this->assertSame($host->last_inventory_at->toISOString(), $remote['host_path_policy']['reported_at']);
                        $this->assertArrayNotHasKey('host_path_allowlist', $remote);
                        $this->assertArrayNotHasKey('agent_host_path_allowlist', $remote);
                        $this->assertSame('known', $local['host_path_policy']['status']);
                        $this->assertSame(['/central/data'], $local['host_path_policy']['prefixes']);

                        return true;
                    }));
            }
        }
    }

    public function test_audits_are_host_scoped_and_remote_policies_never_use_central_paths(): void
    {
        config(['volumevault.host_path_allowlist' => ['/central']]);
        Process::fake();
        $a = $this->remoteHost(['/srv//data/']);
        $b = $this->remoteHost(['/other']);
        foreach ([$a, $b] as $host) {
            $this->hostPathJob('/srv//data/app/')->update(['docker_host_id' => $host->id]);
            $this->localDestination('/srv/data-extra')->update(['docker_host_id' => $host->id]);
        }
        $this->hostPathJob('/central/app');
        $audit = app(HostPathAllowlistAudit::class);

        $this->assertSame(['/central/app'], $audit->pathsInUse());
        $this->assertSame([], $audit->blockedPaths());
        $this->assertSame(['/srv/data-extra'], $audit->blockedPaths($a->id));
        $this->assertSame(['/srv/data/app', '/srv/data-extra'], $audit->blockedPaths($b->id));
        $this->assertSame(['/srv/data', '/srv/data-extra'], $audit->suggestedAllowlist($a->id));
        $this->assertSame('remote_agent', $audit->inspect($a->id)['configuration_target']);
        $this->assertTrue($audit->reportMisconfiguration($a->id));
        $this->assertTrue($audit->reportMisconfiguration($b->id));
        $this->assertSame(2, ActivityLog::where('event_type', 'host_path_allowlist_misconfigured')->count());
        Process::assertNothingRan();
        $this->assertSame(['/central'], config('volumevault.host_path_allowlist'));
    }

    public function test_missing_stale_offline_and_revoked_remote_policies_are_unknown_not_blocked(): void
    {
        $this->freezeTime();
        $host = $this->remoteHost([]);
        $this->hostPathJob('/srv/data')->update(['docker_host_id' => $host->id]);
        $audit = app(HostPathAllowlistAudit::class);
        $this->assertSame('blocked', $audit->inspect($host->id)['status']);

        foreach ([
            ['agent_host_path_allowlist' => null],
            ['last_inventory_at' => null],
            ['last_inventory_at' => now()->subMinutes(15)],
            ['last_seen_at' => now()->subSeconds(90)],
            ['agent_revoked_at' => now()],
        ] as $attributes) {
            $host->forceFill([
                'agent_host_path_allowlist' => [], 'last_inventory_at' => now(),
                'last_seen_at' => now(), 'agent_revoked_at' => null,
                ...$attributes,
            ])->save();
            $result = $audit->inspect($host->id);
            $this->assertSame('policy_unknown', $result['status']);
            $this->assertNull($result['configured']);
            $this->assertSame([], $result['blocked_paths']);
            $this->assertSame(['/srv/data'], $result['unknown_paths']);
            $this->assertNull($result['suggested_env_line']);
            $this->assertFalse($audit->reportMisconfiguration($host->id));
        }
    }

    public function test_remote_inventory_freshness_is_independent_of_heartbeat_and_matching_is_lexical(): void
    {
        $this->freezeTime();
        $host = $this->remoteHost(['/srv//data/']);
        $host->forceFill(['last_inventory_at' => now()->subMinutes(6)])->save();
        $this->hostPathJob('/srv/data/app')->update(['docker_host_id' => $host->id]);
        $result = app(HostPathAllowlistAudit::class)->inspect($host->id);
        $this->assertSame('allowed', $result['status']);
        $this->assertSame('fresh', $result['freshness']);
        $this->assertSame($host->last_inventory_at->toISOString(), $result['reported_at']);
    }

    public function test_api_accepts_a_host_selector_and_preserves_local_defaults(): void
    {
        config(['volumevault.host_path_allowlist' => ['/central']]);
        $host = $this->remoteHost(['/remote']);
        $token = User::factory()->admin()->create()->createToken('audit', ['read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/host-path-allowlist')
            ->assertOk()->assertJsonPath('data.docker_host_id', 1)
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.prefixes', ['/central']);
        $this->getJson('/api/v1/host-path-allowlist?docker_host_id='.$host->id)
            ->assertOk()->assertJsonPath('data.prefixes', ['/remote'])
            ->assertJsonPath('data.policy_status', 'known');
        $host->forceFill(['last_inventory_at' => now()->subHour()])->save();
        $this->getJson('/api/v1/host-path-allowlist?docker_host_id='.$host->id)
            ->assertOk()->assertJsonPath('data.configured', null)
            ->assertJsonPath('data.policy_status', 'policy_unknown')
            ->assertJsonPath('data.freshness', 'stale');
        foreach (['0', 'invalid', '999999', '1.2'] as $id) {
            $this->getJson('/api/v1/host-path-allowlist?docker_host_id='.$id)
                ->assertUnprocessable()->assertJsonValidationErrors('docker_host_id');
        }
    }

    /** @param array<int, string> $prefixes */
    private function remoteHost(array $prefixes): DockerHost
    {
        return DockerHost::factory()->create([
            'agent_registered_at' => now(), 'last_seen_at' => now(),
            'last_inventory_at' => now(), 'agent_host_path_allowlist' => $prefixes,
        ]);
    }

    public function test_no_records_reports_no_misconfiguration(): void
    {
        config(['volumevault.host_path_allowlist' => []]);

        $audit = app(HostPathAllowlistAudit::class);

        $this->assertSame([], $audit->pathsInUse());
        $this->assertFalse($audit->hasMisconfiguration());
        $this->assertFalse($audit->reportMisconfiguration());
    }

    public function test_blocked_paths_are_detected_and_a_suggested_allowlist_is_derived(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $this->hostPathJob('/srv/data');
        $this->localDestination('/mnt/backups', '/mnt/backups');

        $audit = app(HostPathAllowlistAudit::class);

        $this->assertEqualsCanonicalizing(['/srv/data', '/mnt/backups'], $audit->pathsInUse());
        $this->assertEqualsCanonicalizing(['/srv/data', '/mnt/backups'], $audit->blockedPaths());
        $this->assertTrue($audit->hasMisconfiguration());
        $this->assertSame(['/mnt/backups', '/srv/data'], $audit->suggestedAllowlist());
        $this->assertSame('VOLUMEVAULT_HOST_PATH_ALLOWLIST=/mnt/backups,/srv/data', $audit->suggestedEnvLine());
    }

    public function test_paths_already_covered_are_not_flagged(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv', '/mnt']]);
        $this->hostPathJob('/srv/data');
        $this->localDestination('/mnt/backups', '/mnt/backups');

        $audit = app(HostPathAllowlistAudit::class);

        $this->assertSame([], $audit->blockedPaths());
        $this->assertFalse($audit->hasMisconfiguration());
    }

    public function test_suggestion_merges_existing_prefixes_with_blocked_paths(): void
    {
        config(['volumevault.host_path_allowlist' => ['/srv']]);
        $this->hostPathJob('/srv/data');           // already covered
        $this->localDestination('/mnt/backups');   // not covered

        $audit = app(HostPathAllowlistAudit::class);

        $this->assertSame(['/mnt/backups'], $audit->blockedPaths());
        $this->assertSame(['/mnt/backups', '/srv'], $audit->suggestedAllowlist());
    }

    public function test_report_records_activity_log_once_then_throttles(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $this->hostPathJob('/srv/data');

        $audit = app(HostPathAllowlistAudit::class);

        $this->assertTrue($audit->reportMisconfiguration());
        $this->assertSame(1, ActivityLog::where('event_type', 'host_path_allowlist_misconfigured')->count());

        // Within the throttle window, the warning is not recorded again.
        $this->assertTrue($audit->reportMisconfiguration());
        $this->assertSame(1, ActivityLog::where('event_type', 'host_path_allowlist_misconfigured')->count());

        Cache::flush();
        $this->assertTrue($audit->reportMisconfiguration());
        $this->assertSame(2, ActivityLog::where('event_type', 'host_path_allowlist_misconfigured')->count());
    }

    private function hostPathJob(string $hostPath): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'S3 '.$hostPath,
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'k',
            'secret_access_key' => 's',
        ]);

        return BackupJob::create([
            'name' => 'Host job '.$hostPath,
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => $hostPath,
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }

    private function localDestination(string $archivePath, ?string $mountSource = null): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'Local '.$archivePath,
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => array_filter([
                'archive_path' => $archivePath,
                'archive_mount_source' => $mountSource,
            ]),
        ]);
    }
}
