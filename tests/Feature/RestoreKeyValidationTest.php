<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RestoreKeyValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivePath = sys_get_temp_dir().'/volumevault-restore-key-'.uniqid();
        File::ensureDirectoryExists($this->archivePath);
        File::put($this->archivePath.'/backup.tar.gz', 'fake-archive');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archivePath);

        parent::tearDown();
    }

    public function test_selected_backup_key_within_the_listing_is_accepted(): void
    {
        Queue::fake();
        $job = $this->backupJob();
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, RestoreRun::count());
        // The restore records who initiated it for audit purposes.
        $this->assertSame($user->id, RestoreRun::first()->initiated_by_user_id);
    }

    public function test_path_traversal_key_is_rejected(): void
    {
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => '../../../../etc/passwd',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('selected_backup_key');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_key_outside_the_listing_is_rejected(): void
    {
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'does-not-exist.tar.gz',
                'mode' => RestoreRun::MODE_NEW_VOLUME,
            ])
            ->assertSessionHasErrors('selected_backup_key');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_in_place_restore_requires_typed_confirmation_matching_the_volume_name(): void
    {
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => 'wrong',
            ])
            ->assertSessionHasErrors('confirmation_text');

        $this->assertSame(0, RestoreRun::count());
    }

    public function test_in_place_restore_is_accepted_with_the_exact_volume_name(): void
    {
        Queue::fake();
        $job = $this->backupJob();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => 'app_data',
            ])
            ->assertSessionDoesntHaveErrors();

        $run = RestoreRun::first();
        $this->assertNotNull($run);
        $this->assertSame(RestoreRun::MODE_INPLACE, $run->mode);
        // In-place restores overwrite the source volume itself.
        $this->assertSame('app_data', $run->target_volume_name);
    }

    public function test_in_place_restore_is_rejected_for_host_path_sources(): void
    {
        $job = $this->backupJob();
        $job->forceFill(['source_type' => BackupJob::SOURCE_TYPE_HOST_PATH, 'host_path' => '/srv/data'])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.restore', $job))
            ->post(route('backup-jobs.restore.store', $job), [
                'selected_backup_key' => 'backup.tar.gz',
                'mode' => RestoreRun::MODE_INPLACE,
                'confirmation_text' => '/srv/data',
            ])
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, RestoreRun::count());
    }

    private function backupJob(): BackupJob
    {
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $this->archivePath],
        ]);

        return BackupJob::create([
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
    }
}
