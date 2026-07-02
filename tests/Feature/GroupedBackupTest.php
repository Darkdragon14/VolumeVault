<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\CreateBackupRun;
use App\Actions\Backup\RunBackupGroup;
use App\Jobs\DispatchDueBackupGroupsJob;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\VolumeJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class GroupedBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/volumevault-grouped-'.uniqid();
        File::ensureDirectoryExists($this->storagePath);
        $this->app->useStoragePath($this->storagePath);

        // The LOCAL destination bind-mount is re-validated against the host-path
        // allowlist at run time, so allow the temp archive path used by these tests.
        config(['volumevault.host_path_allowlist' => array_unique([$this->storagePath, realpath($this->storagePath) ?: $this->storagePath])]);
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== '') {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_a_group_run_backs_up_every_member_and_emits_one_start_and_one_finish_notification(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        // Member runs must stay silent — the group emits the single notification set.
        $notifier->shouldNotReceive('sendBackupRunStarted');
        $notifier->shouldNotReceive('sendBackupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $this->member($group, 'vol_a');
        $this->member($group, 'vol_b');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        app(RunBackupGroup::class)->handle($run);

        $run->refresh();
        $this->assertSame(BackupGroupRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(2, $run->total_members);
        $this->assertSame(2, $run->succeeded_members);
        $this->assertSame(0, $run->failed_members);
        $this->assertSame(BackupJobGroup::STATUS_ACTIVE, $group->fresh()->status);
        $this->assertNotNull($group->fresh()->last_success_at);

        $memberRuns = BackupRun::where('backup_group_run_id', $run->id)->get();
        $this->assertCount(2, $memberRuns);
        $this->assertTrue($memberRuns->every(fn (BackupRun $r): bool => $r->status === BackupRun::STATUS_SUCCESS));
    }

    public function test_a_group_run_fails_when_any_member_fails_but_still_backs_up_the_others(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker(['vol_b']));

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $notifier->shouldNotReceive('sendBackupRunStarted');
        $notifier->shouldNotReceive('sendBackupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $this->member($group, 'vol_a');
        $this->member($group, 'vol_b');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        app(RunBackupGroup::class)->handle($run);

        $run->refresh();
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->succeeded_members);
        $this->assertSame(1, $run->failed_members);
        $this->assertSame(BackupJobGroup::STATUS_ERROR, $group->fresh()->status);

        // Both members were attempted (continue policy): the healthy volume still ran.
        $this->assertSame(2, BackupRun::where('backup_group_run_id', $run->id)->count());
        $this->assertSame(1, BackupRun::where('backup_group_run_id', $run->id)->where('status', BackupRun::STATUS_SUCCESS)->count());
    }

    public function test_stop_on_first_failure_leaves_the_remaining_members_un_run(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker(['vol_a']));

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $notifier->shouldNotReceive('sendBackupRunStarted');
        $notifier->shouldNotReceive('sendBackupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group(BackupJobGroup::FAILURE_POLICY_STOP);
        $this->member($group, 'vol_a'); // lower id → runs first → fails
        $this->member($group, 'vol_b');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        app(RunBackupGroup::class)->handle($run);

        $run->refresh();
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        // The second member never ran: only one member run exists.
        $this->assertSame(1, BackupRun::where('backup_group_run_id', $run->id)->count());
    }

    public function test_a_failed_member_is_retried_on_the_next_group_run(): void
    {
        $group = $this->group();
        $this->member($group, 'vol_a');
        $memberB = $this->member($group, 'vol_b');

        // First run: vol_b fails, so its member job is left in error.
        $this->app->instance(DockerProcess::class, $this->fakeDocker(['vol_b']));
        $run1 = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);
        app(RunBackupGroup::class)->handle($run1);
        $this->assertSame(BackupJob::STATUS_ERROR, $memberB->fresh()->status);

        // Second run: nothing fails. The errored member must be retried (not
        // dropped) and recover, and the group must succeed.
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $run2 = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);
        app(RunBackupGroup::class)->handle($run2);

        $run2->refresh();
        $this->assertSame(BackupGroupRun::STATUS_SUCCESS, $run2->status);
        $this->assertSame(2, $run2->total_members, 'the previously failed member is included again');
        $this->assertSame(2, BackupRun::where('backup_group_run_id', $run2->id)->count());
        $this->assertSame(BackupJob::STATUS_ACTIVE, $memberB->fresh()->status);
    }

    public function test_a_paused_member_is_skipped_by_the_group_run(): void
    {
        $group = $this->group();
        $this->member($group, 'vol_a');
        $paused = $this->member($group, 'vol_b');
        $paused->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);
        app(RunBackupGroup::class)->handle($run);

        $run->refresh();
        $this->assertSame(1, $run->total_members, 'the paused member is skipped');
        $this->assertSame(1, BackupRun::where('backup_group_run_id', $run->id)->count());
    }

    public function test_the_scheduler_dispatches_due_groups_and_skips_group_members(): void
    {
        Bus::fake([RunBackupGroupJob::class, RunBackupJob::class]);

        $group = $this->group();
        $group->forceFill(['next_run_at' => now()->subMinute()])->save();
        $member = $this->member($group, 'vol_a');
        // A member is due on its own columns but must never be dispatched standalone.
        $member->forceFill(['next_run_at' => now()->subMinute()])->save();

        app(DispatchDueBackupGroupsJob::class)->handle(app(CreateBackupGroupRun::class));
        app(DispatchDueBackupJobsJob::class)->handle(app(CreateBackupRun::class));

        Bus::assertDispatched(RunBackupGroupJob::class);
        Bus::assertNotDispatched(RunBackupJob::class);
        $this->assertSame(1, BackupGroupRun::where('backup_job_group_id', $group->id)->count());
        // The member produced no standalone run.
        $this->assertSame(0, BackupRun::where('backup_job_id', $member->id)->whereNull('backup_group_run_id')->count());
    }

    public function test_a_successful_group_run_pings_the_webhook_start_then_success_url_once_each(): void
    {
        $docker = $this->recordingDocker();
        $this->app->instance(DockerProcess::class, $docker);

        $channel = NotificationChannel::create([
            'name' => 'Healthchecks',
            'service' => NotificationChannel::SERVICE_WEBHOOK,
            'url' => json_encode(['start' => 'START_URL', 'success' => 'SUCCESS_URL', 'fail' => 'FAIL_URL']),
            'notification_level' => NotificationChannel::LEVEL_INFO,
            'is_active' => true,
        ]);

        $group = $this->group();
        $group->notificationChannels()->attach($channel->id);
        $this->member($group, 'vol_a');
        $this->member($group, 'vol_b');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        app(RunBackupGroup::class)->handle($run);

        $urls = $docker->shoutrrrUrls;
        $this->assertSame(1, collect($urls)->filter(fn (string $u): bool => $u === 'START_URL')->count(), 'exactly one start ping');
        $this->assertSame(1, collect($urls)->filter(fn (string $u): bool => $u === 'SUCCESS_URL')->count(), 'exactly one success ping');
        $this->assertSame(0, collect($urls)->filter(fn (string $u): bool => $u === 'FAIL_URL')->count(), 'no fail ping on success');
    }

    public function test_only_one_run_can_be_queued_for_a_group_at_a_time(): void
    {
        $group = $this->group();
        $this->member($group, 'vol_a');

        $create = app(CreateBackupGroupRun::class);
        $create->handle($group, BackupGroupRun::TRIGGER_MANUAL);

        // A second creation while one is queued/running must be rejected (the
        // creation is serialized so concurrent requests cannot duplicate runs).
        $this->expectException(ValidationException::class);
        $create->handle($group, BackupGroupRun::TRIGGER_MANUAL);
    }

    public function test_a_member_is_skipped_when_its_volume_still_has_stopped_containers(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $group = $this->group();
        $member = $this->member($group, 'vol_a');

        // A prior terminal run on the same volume has not restarted its stopped
        // containers yet: the group must not read the volume (mirrors the
        // standalone volumeBusy guard).
        BackupRun::create([
            'backup_job_id' => $member->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'stopped_container_ids' => ['deadbeef'],
        ]);

        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);
        app(RunBackupGroup::class)->handle($run);

        $memberRun = BackupRun::where('backup_group_run_id', $run->id)->firstOrFail();
        $this->assertSame(BackupRun::STATUS_FAILED, $memberRun->status);
        $this->assertStringContainsString('not ready', (string) $memberRun->error_message);
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_reconciliation_releases_an_orphaned_group_lock(): void
    {
        $group = $this->group();
        $this->member($group, 'vol_a');

        // Simulate a crashed RUNNING group run: stale heartbeat, no member run in
        // flight, and its shared group lock still held.
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);

        $lockKey = VolumeJobLock::cacheKeyFor('backup-group-'.$group->id);
        $this->assertTrue(Cache::lock($lockKey, 86400)->get());

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->fresh()->status);
        // The orphaned lock is force-released, so a fresh acquire succeeds.
        $this->assertTrue(Cache::lock($lockKey, 86400)->get(), 'the group lock should be released');
    }

    public function test_reconciliation_releases_the_lock_of_a_stale_queued_group_run(): void
    {
        $group = $this->group();
        $this->member($group, 'vol_a');

        // The worker acquired the group lock (middleware) then crashed before
        // RunBackupGroup flipped the run to running — the run is still queued but
        // the lock is held.
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
        ]);
        BackupGroupRun::whereKey($run->id)->update(['created_at' => now()->subHour()]);

        $lockKey = VolumeJobLock::cacheKeyFor('backup-group-'.$group->id);
        $this->assertTrue(Cache::lock($lockKey, 86400)->get());

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertTrue(Cache::lock($lockKey, 86400)->get(), 'the queued group run lock should be released');
    }

    private function group(string $failurePolicy = BackupJobGroup::FAILURE_POLICY_CONTINUE): BackupJobGroup
    {
        return BackupJobGroup::create([
            'name' => 'Nightly',
            'schedule_type' => BackupJobGroup::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJobGroup::STATUS_ACTIVE,
            'failure_policy' => $failurePolicy,
            'notifications_enabled' => true,
            'next_run_at' => now()->addDay(),
        ]);
    }

    private function member(BackupJobGroup $group, string $volume): BackupJob
    {
        return BackupJob::create([
            'name' => 'Member '.$volume,
            'backup_job_group_id' => $group->id,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => $volume,
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'notifications_enabled' => false,
            'next_run_at' => null,
        ]);
    }

    private ?BackupDestination $destination = null;

    private function destination(): BackupDestination
    {
        return $this->destination ??= BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'is_active' => true,
            'settings' => ['archive_path' => $this->storagePath],
        ]);
    }

    /**
     * A Docker fake that succeeds for every volume except those named in
     * $failVolumes, whose backup container returns a non-zero exit.
     */
    private function fakeDocker(array $failVolumes = []): DockerProcess
    {
        return new class($failVolumes) extends DockerProcess
        {
            public function __construct(private array $failVolumes) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (($command[1] ?? null) === 'volume' && ($command[2] ?? null) === 'inspect') {
                    $name = $command[3] ?? 'vol';

                    return new DockerProcessResult($command, 0, '[{"Name":"'.$name.'"}]', '');
                }

                foreach ($this->failVolumes as $volume) {
                    foreach ($command as $arg) {
                        if (is_string($arg) && str_contains($arg, ':/backup/'.$volume)) {
                            return new DockerProcessResult($command, 1, '', 'backup failed for '.$volume);
                        }
                    }
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
    }

    /** A Docker fake that records the SHOUTRRR_URL of every notification send. */
    private function recordingDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            /** @var array<int, string> */
            public array $shoutrrrUrls = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (array_key_exists('SHOUTRRR_URL', $environment)) {
                    $this->shoutrrrUrls[] = $environment['SHOUTRRR_URL'];

                    return new DockerProcessResult($command, 0, '', '');
                }

                if (($command[1] ?? null) === 'volume' && ($command[2] ?? null) === 'inspect') {
                    $name = $command[3] ?? 'vol';

                    return new DockerProcessResult($command, 0, '[{"Name":"'.$name.'"}]', '');
                }

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
    }
}
