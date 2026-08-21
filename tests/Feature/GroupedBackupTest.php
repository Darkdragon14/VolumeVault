<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupGroupRun;
use App\Actions\Backup\RunBackup;
use App\Actions\Backup\RunBackupGroup;
use App\Actions\Docker\StartDockerContainers;
use App\Jobs\DispatchDueBackupGroupsJob;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\RecordArchiveMetadataJob;
use App\Jobs\RunBackupGroupJob;
use App\Jobs\RunBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Docker\SelfContainerResolver;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\VolumeJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_group_run_detail_page_reports_the_aggregated_size(): void
    {
        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_SUCCESS,
            'trigger' => BackupGroupRun::TRIGGER_MANUAL,
            'total_members' => 1,
            'succeeded_members' => 1,
        ]);
        BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_size_bytes' => 4096,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-group-runs.show', $groupRun))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BackupGroups/RunShow')
                ->where('run.total_backup_size_bytes', 4096)
            );
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

        app()->call([app(DispatchDueBackupGroupsJob::class), 'handle']);
        app()->call([app(DispatchDueBackupJobsJob::class), 'handle']);

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

    public function test_a_member_is_skipped_while_prior_backup_container_cleanup_is_pending(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        BackupRun::create([
            'backup_job_id' => $member->id,
            'status' => BackupRun::STATUS_FAILED,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'finished_at' => now(),
            'docker_container_id' => 'volumevault-backup-prior',
            'docker_container_cleanup_pending' => true,
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

    public function test_the_group_run_job_releases_lock_losers_and_retries_until_a_deadline(): void
    {
        $group = $this->group();
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_SCHEDULED]);

        $job = new RunBackupGroupJob($run->id);
        $middleware = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        // A long group backup can be redelivered mid-run: a lock loser must be
        // released back to the queue with a delay (not dropped) and keep retrying
        // under a deadline rather than a single attempt.
        $this->assertNotNull($middleware->releaseAfter);
        $this->assertGreaterThan(0, $middleware->releaseAfter);
        $this->assertTrue($middleware->shareKey);
        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertInstanceOf(\DateTimeInterface::class, $job->retryUntil());
    }

    public function test_a_group_run_with_no_runnable_members_fails_instead_of_reporting_success(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $group = $this->group();
        $member = $this->member($group, 'vol_a');

        // Queued with a runnable member, then the member is paused before the
        // worker starts: the run must not report a false success.
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);
        $member->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        app(RunBackupGroup::class)->handle($run);

        $run->refresh();
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        $this->assertSame(0, $run->total_members);
        $this->assertSame(BackupJobGroup::STATUS_ERROR, $group->fresh()->status);
        $this->assertSame(0, BackupRun::where('backup_group_run_id', $run->id)->count());
    }

    public function test_reconciliation_releases_the_volume_lock_of_a_stale_queued_member_run(): void
    {
        $group = $this->group();
        $member = $this->member($group, 'vol_a');

        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        // A member run stuck queued: the group worker crashed after acquiring the
        // volume lock in-process but before RunBackup flipped it to running.
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        BackupRun::whereKey($memberRun->id)->update(['created_at' => now()->subHour()]);

        $lockKey = VolumeJobLock::cacheKey('vol_a');
        $this->assertTrue(Cache::lock($lockKey, 86400)->get());

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $memberRun->fresh()->status);
        $this->assertTrue(Cache::lock($lockKey, 86400)->get(), 'the member volume lock should be released');
    }

    public function test_reconciliation_does_not_close_a_group_run_with_a_recently_finished_member(): void
    {
        $group = $this->group();
        $member = $this->member($group, 'vol_a');

        // Running group run with a stale heartbeat (a long member kept the worker
        // busy) but a member that finished just now — the worker is alive,
        // finalizing that member. It must not be reconciled as stale.
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $run->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'finished_at' => now(),
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupGroupRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_a_member_run_defers_archive_metadata_to_a_job(): void
    {
        Queue::fake([RecordArchiveMetadataJob::class]);
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);

        app(RunBackup::class)->handle($memberRun);

        // The potentially-slow archive-metadata listing is deferred to a job so it
        // never blocks the group worker (which would let a live group run be
        // reconciled as stale).
        $this->assertSame(BackupRun::STATUS_SUCCESS, $memberRun->fresh()->status);
        Queue::assertPushed(RecordArchiveMetadataJob::class, fn (RecordArchiveMetadataJob $job): bool => $job->backupRunId === $memberRun->id);
    }

    public function test_a_member_restore_notification_is_delivered_through_the_group_channels(): void
    {
        $docker = $this->recordingDocker();
        $this->app->instance(DockerProcess::class, $docker);

        $channel = NotificationChannel::create([
            'name' => 'Group restore',
            'service' => NotificationChannel::SERVICE_NTFY,
            'url' => 'RESTORE_URL',
            'notification_level' => NotificationChannel::LEVEL_ERROR,
            'is_active' => true,
        ]);
        $group = $this->group();
        $group->notificationChannels()->attach($channel->id);
        $member = $this->member($group, 'vol_a');

        $restore = RestoreRun::create([
            'backup_job_id' => $member->id,
            'backup_destination_id' => $member->backup_destination_id,
            'selected_backup_key' => 'backup.tar.gz',
            'source_volume_name' => 'vol_a',
            'target_volume_name' => 'vol_a',
            'mode' => RestoreRun::MODE_INPLACE,
            'status' => RestoreRun::STATUS_FAILED,
        ]);

        app(SendShoutrrrNotification::class)->sendRestoreRun($restore);

        $this->assertContains('RESTORE_URL', $docker->shoutrrrUrls, 'the member restore should notify the group channel');
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

    public function test_starting_containers_refreshes_the_heartbeat_after_each_one(): void
    {
        // Per-container callback keeps a group run's heartbeat fresh through a slow
        // sequential restart (docker start is 120s each).
        $docker = new class extends DockerProcess
        {
            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, '', '');
            }
        };

        $calls = 0;
        (new StartDockerContainers($docker))->handle(['a', 'b', 'c'], function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame(3, $calls);
    }

    public function test_reconciliation_does_not_restart_the_current_group_members_containers(): void
    {
        $docker = $this->recordingCommandDocker();
        $this->app->instance(DockerProcess::class, $docker);

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        // The latest member run of a live group run: the worker is currently
        // restarting its containers in its own finally.
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'finished_at' => now(),
            'stopped_container_ids' => ['abc123'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // Reconciliation must not attempt a restart (no docker start) and must
        // leave the ids for the live worker to clear.
        $this->assertEmpty(array_filter($docker->commands, fn (string $c): bool => str_contains($c, 'start abc123')));
        $this->assertNotNull($memberRun->fresh()->stopped_container_ids);
    }

    public function test_reconciliation_recovers_an_abandoned_member_restart_while_the_group_continues(): void
    {
        $docker = $this->recordingCommandDocker();
        $this->app->instance(DockerProcess::class, $docker);

        $group = $this->group();
        $memberA = $this->member($group, 'vol_a');
        $memberB = $this->member($group, 'vol_b');
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        // Member A finished with a failed restart (ids kept)...
        $runA = BackupRun::create([
            'backup_job_id' => $memberA->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'finished_at' => now(),
            'stopped_container_ids' => ['a1'],
        ]);
        // ...and the worker has already moved on to a later member (B), which is
        // backing up its own, unrelated container.
        BackupRun::create([
            'backup_job_id' => $memberB->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'stopped_container_ids' => ['b1'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // A's container is unrelated to B's, so it is recovered now — not left down
        // until the group ends.
        $this->assertContains('docker start a1', $docker->commands);
        $this->assertNull($runA->fresh()->stopped_container_ids);
    }

    public function test_reconciliation_does_not_restart_a_container_a_running_member_shares(): void
    {
        $docker = $this->recordingCommandDocker();
        $this->app->instance(DockerProcess::class, $docker);

        $group = $this->group();
        $memberA = $this->member($group, 'vol_a');
        $memberB = $this->member($group, 'vol_b');
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        // Member A backed up vol_a, failed to restart the shared "app" container.
        $runA = BackupRun::create([
            'backup_job_id' => $memberA->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'finished_at' => now(),
            'stopped_container_ids' => ['app'],
        ]);
        // Member B is now backing up vol_b and has deliberately stopped the same
        // "app" container for its own consistent archive.
        BackupRun::create([
            'backup_job_id' => $memberB->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'stopped_container_ids' => ['app'],
        ]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // Restarting "app" now would corrupt B's in-flight archive, so it must be
        // left stopped and kept on A for a later sweep.
        $this->assertEmpty(array_filter($docker->commands, fn (string $c): bool => str_contains($c, 'start app')));
        $this->assertSame(['app'], $runA->fresh()->stopped_container_ids);
    }

    public function test_an_active_group_with_no_runnable_members_advances_its_schedule_instead_of_churning(): void
    {
        $group = $this->group();
        $group->forceFill(['next_run_at' => now()->subMinute()])->save();
        $member = $this->member($group, 'vol_a');
        $member->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        app()->call([app(DispatchDueBackupGroupsJob::class), 'handle']);

        $group->refresh();
        $this->assertTrue($group->next_run_at->isFuture(), 'schedule advanced so it stops firing every minute');
        $this->assertNotNull($group->last_error);
        $this->assertSame(0, BackupGroupRun::where('backup_job_group_id', $group->id)->count());
    }

    public function test_a_running_group_run_is_not_re_executed_on_redelivery(): void
    {
        $group = $this->group();
        $this->member($group, 'vol_a');
        // Already RUNNING (owned by a live worker); a redelivered copy after the
        // lock TTL must not re-claim and overlap.
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        app(RunBackupGroup::class)->handle($run);

        $this->assertSame(BackupGroupRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame(0, BackupRun::where('backup_group_run_id', $run->id)->count());
    }

    public function test_the_failed_hook_does_not_fail_a_running_group_run(): void
    {
        $group = $this->group();
        $run = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        // A copy redelivered until retryUntil must not fail a run a live worker owns.
        (new RunBackupGroupJob($run->id))->failed(new \RuntimeException('retry deadline reached'));

        $this->assertSame(BackupGroupRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_a_host_path_member_serialises_on_its_per_job_lock(): void
    {
        $group = $this->group();
        $member = $this->hostMember($group);
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $lockHeldDuringHandle = false;
        $runBackup = Mockery::mock(RunBackup::class);
        $runBackup->shouldReceive('markFailed');
        $runBackup->shouldReceive('handle')->once()->andReturnUsing(function (BackupRun $memberRun) use ($member, &$lockHeldDuringHandle): void {
            // A standalone RunBackupJob for a host-path job locks on backup-job-{id}.
            // If the group member holds that same lock while running, this get()
            // fails — proving the two cannot overlap.
            $lock = Cache::lock(VolumeJobLock::cacheKeyFor('backup-job-'.$member->id));
            $acquired = $lock->get();
            $lockHeldDuringHandle = ! $acquired;
            if ($acquired) {
                $lock->release();
            }
            $memberRun->forceFill(['status' => BackupRun::STATUS_SUCCESS, 'finished_at' => now()])->save();
        });
        $this->app->instance(RunBackup::class, $runBackup);

        app(RunBackupGroup::class)->handle($run);

        $this->assertTrue($lockHeldDuringHandle, 'a host-path member must hold its per-job overlap lock while running');
    }

    public function test_a_host_path_member_adopts_a_container_a_sibling_left_stopped(): void
    {
        $docker = new class extends DockerProcess
        {
            /** @var list<array{0:string,1:string}> */
            public array $lifecycleCalls = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                [$bin, $verb] = [$command[0] ?? null, $command[1] ?? null];

                if ($bin === 'docker' && $verb === 'ps') {
                    // The shared "app" container is currently stopped.
                    return new DockerProcessResult($command, 0, json_encode(['ID' => 'bbbbbbbbbbbb', 'Names' => 'app', 'Image' => 'app:latest', 'State' => 'exited', 'Status' => 'Exited (0)']), '');
                }

                if ($bin === 'docker' && $verb === 'stop') {
                    $this->lifecycleCalls[] = ['stop', $command[2] ?? ''];

                    return new DockerProcessResult($command, 0, '', '');
                }

                if ($bin === 'docker' && $verb === 'start') {
                    $this->lifecycleCalls[] = ['start', $command[2] ?? ''];

                    // Restart fails, so the adopted id must stay recorded for a sweep.
                    return new DockerProcessResult($command, 1, '', 'cannot start');
                }

                if ($bin === 'docker' && $verb === 'run') {
                    if (isset($environment['BACKUP_ARCHIVE'], $environment['BACKUP_FILENAME'])) {
                        File::put($environment['BACKUP_ARCHIVE'].'/'.$environment['BACKUP_FILENAME'], str_repeat('x', 1536));
                    }

                    return new DockerProcessResult($command, 0, 'backup complete', '');
                }

                return new DockerProcessResult($command, 0, '', '');
            }
        };
        $this->app->instance(DockerProcess::class, $docker);
        $this->app->instance(SelfContainerResolver::class, new class extends SelfContainerResolver
        {
            public function identifiers(): array
            {
                return [];
            }
        });

        $group = $this->group();
        $volumeMember = $this->member($group, 'vol_a');
        $hostMember = $this->hostMember($group, ['app']);
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        // A sibling member already ran and left the shared "app" container stopped
        // after a failed restart (host-path selection can't record it on its own).
        BackupRun::create([
            'backup_job_id' => $volumeMember->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_SUCCESS,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
            'finished_at' => now(),
            'stopped_container_ids' => ['bbbbbbbbbbbb'],
        ]);
        $hostRun = BackupRun::create([
            'backup_job_id' => $hostMember->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);

        app(RunBackup::class)->handle($hostRun);
        $hostRun->refresh();

        // The member adopted the sibling-stopped container: it recorded it (so
        // reconciliation sees it needed-stopped during the archive) and owns its
        // restart. The restart failed here, so the id is kept — proving it was
        // recorded rather than silently skipped as an already-stopped container.
        $this->assertSame(BackupRun::STATUS_SUCCESS, $hostRun->status);
        $this->assertSame(['bbbbbbbbbbbb'], $hostRun->stopped_container_ids);
        $this->assertContains(['stop', 'bbbbbbbbbbbb'], $docker->lifecycleCalls);
        $this->assertStringContainsString('was left stopped by another group member', $hostRun->logs);
    }

    public function test_reconciliation_releases_the_per_job_lock_of_a_stale_queued_host_path_member(): void
    {
        $group = $this->group();
        $member = $this->hostMember($group);
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        // A host-path member stuck queued: the group worker crashed after acquiring
        // the per-job lock in-process but before RunBackup flipped it to running.
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        BackupRun::whereKey($memberRun->id)->update(['created_at' => now()->subHour()]);

        // Host-path members lock on backup-job-{id}, not a volume key.
        $lockKey = VolumeJobLock::cacheKeyFor('backup-job-'.$member->id);
        $this->assertTrue(Cache::lock($lockKey, 86400)->get());

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $memberRun->fresh()->status);
        $this->assertTrue(Cache::lock($lockKey, 86400)->get(), 'the member per-job lock should be released');
    }

    public function test_a_queued_host_path_run_waiting_on_its_job_lock_is_not_reconciled(): void
    {
        $group = $this->group();
        $member = $this->hostMember($group);

        // A run of the same host-path job is still running: it holds the per-job
        // lock the queued run below is legitimately waiting on.
        BackupRun::create([
            'backup_job_id' => $member->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);
        $waiter = BackupRun::create([
            'backup_job_id' => $member->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        BackupRun::whereKey($waiter->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // The waiter is pending on the job lock, not stale — it must survive.
        $this->assertSame(BackupRun::STATUS_QUEUED, $waiter->fresh()->status);
    }

    public function test_a_host_path_member_is_skipped_when_a_sibling_run_is_still_working(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $member = $this->hostMember($group);

        // A sibling run of the same host-path job is still working; the group member
        // holds the same per-job lock only after the sibling's expired, so the busy
        // guard must skip it rather than overlap the sibling.
        BackupRun::create([
            'backup_job_id' => $member->id,
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => BackupRun::TRIGGER_MANUAL,
            'started_at' => now(),
        ]);

        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);
        app(RunBackupGroup::class)->handle($run);

        // Skipped, not run: had the guard not fired the fake docker would succeed.
        $memberRun = BackupRun::where('backup_group_run_id', $run->id)->firstOrFail();
        $this->assertSame(BackupRun::STATUS_FAILED, $memberRun->status);
    }

    public function test_a_member_deleted_before_its_create_fails_the_group_but_does_not_block_it(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $doomed = $this->member($group, 'vol_doomed'); // lower id → processed first
        $this->member($group, 'vol_a');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        // Simulate the doomed member being hard-deleted after the run's member
        // snapshot: creating its BackupRun throws, as a foreign-key violation would.
        // A cloned dispatcher keeps the listener from leaking to other tests.
        $original = BackupRun::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.creating: '.BackupRun::class, function (BackupRun $r) use ($doomed): void {
            if ((int) $r->backup_job_id === $doomed->id) {
                throw new \RuntimeException('member no longer exists');
            }
        });
        BackupRun::setEventDispatcher($dispatcher);

        try {
            app(RunBackupGroup::class)->handle($run);
        } finally {
            BackupRun::setEventDispatcher($original);
        }

        $run->refresh();
        // The vanished member does not abort the group (it still runs the survivor
        // and finalizes) but it counts as a failure — the run backed up less than
        // configured, so it must not report a green run a monitor would trust.
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->succeeded_members);
        $this->assertSame(1, $run->failed_members);
        $this->assertSame(1, BackupRun::where('backup_group_run_id', $run->id)->count());
    }

    public function test_a_member_run_cascade_deleted_mid_flight_does_not_crash_the_group(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $doomed = $this->member($group, 'vol_doomed'); // lower id → processed first
        $this->member($group, 'vol_a');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        // Simulate the member job being removed mid-run: its just-created run row is
        // cascade-deleted, so $memberRun->fresh() returns null. The loop must not
        // dereference null; it counts the vanished member as failed.
        $original = BackupRun::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.created: '.BackupRun::class, function (BackupRun $r) use ($doomed): void {
            if ((int) $r->backup_job_id === $doomed->id) {
                BackupRun::whereKey($r->getKey())->delete();
            }
        });
        BackupRun::setEventDispatcher($dispatcher);

        try {
            app(RunBackupGroup::class)->handle($run);
        } finally {
            BackupRun::setEventDispatcher($original);
        }

        $run->refresh();
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->succeeded_members);
        $this->assertSame(1, $run->failed_members);
    }

    public function test_a_member_whose_volume_changes_during_the_lock_wait_is_skipped(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        // Change the member's source after the lock key is computed (vol_a) but
        // before the in-lock re-check: the run holds the vol_a lock, so it must not
        // back up vol_b under it — it skips instead.
        $original = BackupRun::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.created: '.BackupRun::class, function (BackupRun $r) use ($member): void {
            if ((int) $r->backup_job_id === $member->id) {
                BackupJob::whereKey($member->id)->update(['volume_name' => 'vol_b']);
            }
        });
        BackupRun::setEventDispatcher($dispatcher);

        try {
            app(RunBackupGroup::class)->handle($run);
        } finally {
            BackupRun::setEventDispatcher($original);
        }

        $run->refresh();
        // Skipped rather than backed up on the wrong lock: the member run is
        // cancelled (counted as a group failure), never executed against vol_b.
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        $this->assertSame(BackupRun::STATUS_CANCELLED, BackupRun::where('backup_group_run_id', $run->id)->firstOrFail()->status);
    }

    public function test_a_member_paused_during_the_lock_wait_stays_paused_not_errored(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        // Pause the member right after its run is created — i.e. as the in-lock
        // recheck runs. It must be cancelled without markFailed(), so the job keeps
        // its PAUSED status (markFailed would flip it to ERROR, making
        // runnableMembers() pick it up again next run).
        $original = BackupRun::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.created: '.BackupRun::class, function (BackupRun $r) use ($member): void {
            if ((int) $r->backup_job_id === $member->id) {
                BackupJob::whereKey($member->id)->update(['status' => BackupJob::STATUS_PAUSED]);
            }
        });
        BackupRun::setEventDispatcher($dispatcher);

        try {
            app(RunBackupGroup::class)->handle($run);
        } finally {
            BackupRun::setEventDispatcher($original);
        }

        $memberRun = BackupRun::where('backup_group_run_id', $run->id)->firstOrFail();
        $this->assertSame(BackupRun::STATUS_CANCELLED, $memberRun->status);
        // The job stays PAUSED — not flipped to ERROR.
        $this->assertSame(BackupJob::STATUS_PAUSED, $member->fresh()->status);
    }

    public function test_a_standalone_run_is_cancelled_if_its_job_was_paused_while_queued(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $job = $this->standaloneJob();
        $run = BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_QUEUED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);
        // Admin paused the job after the run was queued.
        $job->forceFill(['status' => BackupJob::STATUS_PAUSED, 'pause_reason' => 'maintenance'])->save();

        app(RunBackup::class)->handle($run);

        // The worker honours the pause: the run is cancelled without executing, and
        // the job stays paused (not flipped to running/active).
        $this->assertSame(BackupRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame(BackupJob::STATUS_PAUSED, $job->fresh()->status);
        $this->assertSame('maintenance', $job->fresh()->pause_reason);
    }

    public function test_a_queued_group_run_waiting_on_the_group_lock_is_not_reconciled(): void
    {
        $group = $this->group();
        // The previous run just finished and is still notifying (fresh heartbeat).
        BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_SUCCESS,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'last_heartbeat_at' => now(),
        ]);
        // The next run is queued, waiting on the backup-group lock, and looks old.
        $waiter = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
        ]);
        BackupGroupRun::whereKey($waiter->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // A previous run of the same group is still finishing (fresh heartbeat), so
        // the waiter is legitimately pending — not stale.
        $this->assertSame(BackupGroupRun::STATUS_QUEUED, $waiter->fresh()->status);
    }

    public function test_group_mark_failed_does_not_overwrite_a_pause_applied_after_the_snapshot(): void
    {
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
        ]);
        // A stale snapshot: group loaded active.
        $loaded = BackupGroupRun::with('group')->find($groupRun->id);
        // Admin pauses the group in the DB after the load.
        BackupJobGroup::whereKey($group->id)->update(['status' => BackupJobGroup::STATUS_PAUSED, 'pause_reason' => 'maintenance']);

        app(RunBackupGroup::class)->markFailed($loaded, new \RuntimeException('boom'));

        // The atomic (where status != paused) flip must not resurrect the pause.
        $this->assertSame(BackupJobGroup::STATUS_PAUSED, $group->fresh()->status);
        $this->assertSame('maintenance', $group->fresh()->pause_reason);
    }

    public function test_marking_failed_does_not_overwrite_a_pause_applied_after_the_model_was_loaded(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $job = $this->standaloneJob();
        $run = BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_RUNNING, 'trigger' => BackupRun::TRIGGER_SCHEDULED, 'started_at' => now()]);

        // A stale snapshot (as reconciliation holds): the run's job is loaded active.
        $loaded = BackupRun::with('job')->find($run->id);
        // The admin pauses the job in the DB after that load.
        BackupJob::whereKey($job->id)->update(['status' => BackupJob::STATUS_PAUSED]);

        app(RunBackup::class)->markFailed($loaded, new \RuntimeException('boom'));

        // The run fails, but the atomic (where status != paused) flip must not
        // resurrect the pause the admin just applied.
        $this->assertSame(BackupRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame(BackupJob::STATUS_PAUSED, $job->fresh()->status);
    }

    public function test_no_runnable_members_branch_does_not_overwrite_a_concurrent_pause(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        // A paused member → runnableMembers() is empty, so handle() hits the
        // no-runnable-members branch (the group itself is still active at load time).
        $this->member($group, 'vol_a')->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();
        $groupRun = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_SCHEDULED]);

        // Pause the group in the DB the moment its model is loaded: the in-memory
        // top-of-handle check still sees it active, but the no-members write races it.
        $original = BackupJobGroup::getEventDispatcher();
        $dispatcher = clone $original;
        $done = false;
        $dispatcher->listen('eloquent.retrieved: '.BackupJobGroup::class, function (BackupJobGroup $g) use ($group, &$done): void {
            if ((int) $g->id === $group->id && ! $done) {
                $done = true;
                BackupJobGroup::whereKey($group->id)->update(['status' => BackupJobGroup::STATUS_PAUSED, 'pause_reason' => 'maintenance']);
            }
        });
        BackupJobGroup::setEventDispatcher($dispatcher);

        try {
            app(RunBackupGroup::class)->handle($groupRun);
        } finally {
            BackupJobGroup::setEventDispatcher($original);
        }

        // The no-members branch must not clobber the deliberate pause.
        $this->assertSame(BackupJobGroup::STATUS_PAUSED, $group->fresh()->status);
        $this->assertSame('maintenance', $group->fresh()->pause_reason);
    }

    public function test_a_stale_queued_member_run_is_reconciled_not_treated_as_a_lock_waiter(): void
    {
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        // Crashed group worker: group run running with a stale heartbeat...
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        // ...leaving a member run stuck queued (no queue job will ever resume it).
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        BackupRun::whereKey($memberRun->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // The member run is failed (not skipped as a lock waiter), and the same
        // sweep closes its stale parent group instead of waiting another threshold.
        $this->assertSame(BackupRun::STATUS_FAILED, $memberRun->fresh()->status);
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $groupRun->fresh()->status);
    }

    public function test_reconciling_a_stale_queued_member_keeps_a_paused_member_job_paused(): void
    {
        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_RUNNING,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        // Worker created the member run, crashed before RunBackup claimed it, then
        // the admin paused the member.
        $memberRun = BackupRun::create([
            'backup_job_id' => $member->id,
            'backup_group_run_id' => $groupRun->id,
            'status' => BackupRun::STATUS_QUEUED,
            'trigger' => BackupRun::TRIGGER_SCHEDULED,
        ]);
        BackupRun::whereKey($memberRun->id)->update(['created_at' => now()->subHour()]);
        $member->forceFill(['status' => BackupJob::STATUS_PAUSED])->save();

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // The stale run is failed, but the paused member must stay paused — flipping
        // it to error would make runnableMembers() pick it up again.
        $this->assertSame(BackupRun::STATUS_FAILED, $memberRun->fresh()->status);
        $this->assertSame(BackupJob::STATUS_PAUSED, $member->fresh()->status);
    }

    public function test_reconciling_a_stale_group_run_keeps_a_paused_group_paused(): void
    {
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $group->forceFill(['status' => BackupJobGroup::STATUS_PAUSED, 'pause_reason' => 'maintenance'])->save();
        $groupRun = BackupGroupRun::create([
            'backup_job_group_id' => $group->id,
            'status' => BackupGroupRun::STATUS_QUEUED,
            'trigger' => BackupGroupRun::TRIGGER_SCHEDULED,
        ]);
        BackupGroupRun::whereKey($groupRun->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('volumevault:reconcile-stale-runs')->assertSuccessful();

        // The stale group run is failed, but the paused group stays paused (with its
        // reason) so the scheduler does not dispatch it again.
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $groupRun->fresh()->status);
        $this->assertSame(BackupJobGroup::STATUS_PAUSED, $group->fresh()->status);
        $this->assertSame('maintenance', $group->fresh()->pause_reason);
    }

    public function test_a_queued_group_run_is_cancelled_if_the_group_is_paused_before_it_starts(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldNotReceive('sendGroupRunStarted');
        $notifier->shouldNotReceive('sendGroupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $this->member($group, 'vol_a');
        // Paused after the run was queued.
        $group->forceFill(['status' => BackupJobGroup::STATUS_PAUSED, 'pause_reason' => 'maintenance'])->save();
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        app(RunBackupGroup::class)->handle($run);

        $run->refresh();
        // The run is cancelled without executing; the pause (and reason) survive.
        $this->assertSame(BackupGroupRun::STATUS_CANCELLED, $run->status);
        $this->assertSame(BackupJobGroup::STATUS_PAUSED, $group->fresh()->status);
        $this->assertSame('maintenance', $group->fresh()->pause_reason);
        $this->assertSame(0, BackupRun::where('backup_group_run_id', $run->id)->count());
    }

    public function test_a_member_detached_mid_run_is_not_backed_up_by_the_group(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendGroupRunStarted')->once();
        $notifier->shouldReceive('sendGroupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $first = $this->member($group, 'vol_a'); // lower id → runs first
        $second = $this->member($group, 'vol_b');
        $run = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_QUEUED, 'trigger' => BackupGroupRun::TRIGGER_MANUAL]);

        // While the first member runs, an admin detaches the second from the group.
        // Its turn must re-read the current job and skip it — never lock/back it up
        // off the stale snapshot.
        $original = BackupRun::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.created: '.BackupRun::class, function (BackupRun $r) use ($first, $second): void {
            if ((int) $r->backup_job_id === $first->id) {
                BackupJob::whereKey($second->id)->update(['backup_job_group_id' => null]);
            }
        });
        BackupRun::setEventDispatcher($dispatcher);

        try {
            app(RunBackupGroup::class)->handle($run);
        } finally {
            BackupRun::setEventDispatcher($original);
        }

        $run->refresh();
        // Only the first member ran; the detached one was skipped (counted failed).
        $this->assertSame(1, BackupRun::where('backup_group_run_id', $run->id)->count());
        $this->assertSame(BackupGroupRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->succeeded_members);
        $this->assertSame(1, $run->failed_members);
        $this->assertNull($second->fresh()->backup_job_group_id);
    }

    public function test_a_standalone_run_defers_metadata_and_notification_to_a_job(): void
    {
        Queue::fake([RecordArchiveMetadataJob::class]);
        $this->app->instance(DockerProcess::class, $this->fakeDocker());

        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunStarted')->zeroOrMoreTimes();
        // The finished notification is deferred to the (faked) metadata job so the
        // queue job's volume lock is released without waiting on a slow listing.
        $notifier->shouldNotReceive('sendBackupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $job = $this->standaloneJob();
        $run = BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_QUEUED, 'trigger' => BackupRun::TRIGGER_SCHEDULED]);

        app(RunBackup::class)->handle($run);

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->fresh()->status);
        Queue::assertPushed(RecordArchiveMetadataJob::class, fn (RecordArchiveMetadataJob $j): bool => $j->backupRunId === $run->id);
        // On its own queue so a dedicated worker runs it — a slow listing never
        // blocks the main worker and starves a same-volume waiter.
        Queue::assertPushedOn('metadata', RecordArchiveMetadataJob::class);
    }

    public function test_the_metadata_job_sends_the_finished_notification_for_a_standalone_run(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldReceive('sendBackupRunFinished')->once();
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $job = $this->standaloneJob();
        $run = BackupRun::create(['backup_job_id' => $job->id, 'status' => BackupRun::STATUS_SUCCESS, 'trigger' => BackupRun::TRIGGER_SCHEDULED, 'finished_at' => now()]);

        // The deferred job records metadata then sends the finished notification
        // (the flag is captured at dispatch — true for a standalone run).
        app(RunBackup::class)->recordArchiveMetadata($run->id, sendFinishedNotification: true);
    }

    public function test_the_metadata_job_stays_silent_for_a_group_member_even_if_the_group_was_deleted(): void
    {
        $this->app->instance(DockerProcess::class, $this->fakeDocker());
        $notifier = Mockery::mock(SendShoutrrrNotification::class);
        $notifier->shouldNotReceive('sendBackupRunFinished');
        $this->app->instance(SendShoutrrrNotification::class, $notifier);

        $group = $this->group();
        $member = $this->member($group, 'vol_a');
        $groupRun = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => BackupGroupRun::STATUS_RUNNING, 'trigger' => BackupGroupRun::TRIGGER_SCHEDULED, 'started_at' => now(), 'last_heartbeat_at' => now()]);
        $memberRun = BackupRun::create(['backup_job_id' => $member->id, 'backup_group_run_id' => $groupRun->id, 'status' => BackupRun::STATUS_SUCCESS, 'trigger' => BackupRun::TRIGGER_SCHEDULED, 'finished_at' => now()]);

        // Simulate the group being deleted after dispatch: backup_group_run_id is
        // nulled, so belongsToGroupRun() is now false. The captured flag (false for
        // a member) must still keep it silent — no unexpected standalone notification.
        $memberRun->forceFill(['backup_group_run_id' => null])->save();

        app(RunBackup::class)->recordArchiveMetadata($memberRun->id, sendFinishedNotification: false);

        $this->assertSame(BackupRun::STATUS_SUCCESS, $memberRun->fresh()->status);
    }

    private function standaloneJob(): BackupJob
    {
        return BackupJob::create([
            'name' => 'Standalone',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'vol_a',
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'notifications_enabled' => true,
        ]);
    }

    private function hostMember(BackupJobGroup $group, array $stopContainerNames = []): BackupJob
    {
        return BackupJob::create([
            'name' => 'Host member',
            'backup_job_group_id' => $group->id,
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
            'host_path' => $this->storagePath,
            'backup_destination_id' => $this->destination()->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
            'notifications_enabled' => false,
            'next_run_at' => null,
            'stop_containers_before_backup' => $stopContainerNames !== [],
            'stop_container_names' => $stopContainerNames,
        ]);
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
    /** A Docker fake that records every command it is asked to run. */
    private function recordingCommandDocker(): DockerProcess
    {
        return new class extends DockerProcess
        {
            /** @var array<int, string> */
            public array $commands = [];

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->commands[] = implode(' ', $command);

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                return new DockerProcessResult($command, 0, 'ok', '');
            }
        };
    }

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
