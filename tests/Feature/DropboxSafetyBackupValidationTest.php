<?php

namespace Tests\Feature;

use App\Actions\Restore\CreateRestoreRun;
use App\Jobs\RunRestoreJob;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerVolume;
use App\Models\RestoreRun;
use App\Models\User;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\DockerProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DropboxSafetyBackupValidationTest extends TestCase
{
    use RefreshDatabase;

    public static function blockedRestores(): array
    {
        return [
            'inplace current archive' => ['inplace', 'dropbox'],
            'safe inplace current archive' => ['safe_inplace', 'dropbox'],
            'inplace historical local archive' => ['inplace', 'local'],
            'safe inplace historical local archive' => ['safe_inplace', 'local'],
        ];
    }

    #[DataProvider('blockedRestores')]
    public function test_http_rejects_before_listing_execution_dispatch_or_persistence(string $mode, string $historicalProvider): void
    {
        [$job, $backup] = $this->restoreContext('dropbox', $historicalProvider);
        $this->mock(ListBackupObjects::class)->shouldNotReceive('contains');

        $this->actingAs(User::factory()->admin()->create())
            ->from('/backup-jobs/'.$job->id.'/restore')
            ->post('/backup-jobs/'.$job->id.'/restore', $this->payload($backup, $mode, true))
            ->assertRedirect('/backup-jobs/'.$job->id.'/restore')
            ->assertSessionHasErrors(['backup_before_overwrite' => CreateRestoreRun::DROPBOX_SAFETY_BACKUP_MESSAGE]);

        $this->assertNothingCreated();
    }

    public function test_direct_creation_rejects_before_listing_or_persistence_even_with_stale_job_relation(): void
    {
        [$job, $backup] = $this->restoreContext('local', 'local');
        $job->load('destination');
        BackupDestination::findOrFail($job->backup_destination_id)->update(['provider' => 'dropbox']);
        $this->mock(ListBackupObjects::class)->shouldNotReceive('contains');

        try {
            app(CreateRestoreRun::class)->handle($job, $this->payload($backup, 'inplace', true));
            $this->fail('The requested Dropbox safety backup must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame([CreateRestoreRun::DROPBOX_SAFETY_BACKUP_MESSAGE], $exception->errors()['backup_before_overwrite']);
        }

        $this->assertNothingCreated();
    }

    public function test_http_rechecks_current_destination_under_lock_after_listing(): void
    {
        [$job, $backup] = $this->restoreContext('local', 'dropbox');
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->once()->andReturnUsing(function () use ($job): bool {
            BackupDestination::findOrFail($job->backup_destination_id)->update(['provider' => 'dropbox']);

            return true;
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/backup-jobs/'.$job->id.'/restore', $this->payload($backup, 'safe_inplace', true))
            ->assertSessionHasErrors(['backup_before_overwrite' => CreateRestoreRun::DROPBOX_SAFETY_BACKUP_MESSAGE]);

        $this->assertNothingCreated();
    }

    public static function allowedRestores(): array
    {
        return [
            'exact Dropbox ID without safety' => ['dropbox', 'inplace', false, false],
            'safe inplace without safety' => ['dropbox', 'safe_inplace', false, false],
            'new volume ignores irrelevant safety' => ['dropbox', 'new_volume', true, false],
            'historical Dropbox with current local safety' => ['local', 'inplace', true, true],
            'historical Dropbox with current local safe inplace' => ['local', 'safe_inplace', true, true],
        ];
    }

    #[DataProvider('allowedRestores')]
    public function test_http_preserves_supported_restore_semantics(string $currentProvider, string $mode, bool $requestedSafety, bool $storedSafety): void
    {
        [$job, $backup] = $this->restoreContext($currentProvider, 'dropbox');
        $this->mock(ListBackupObjects::class)->shouldReceive('contains')->once()
            ->withArgs(fn (BackupDestination $destination, string $key, bool $exhaustive): bool => $destination->id === $backup->backup_destination_id_snapshot && $key === 'id:original' && $exhaustive)
            ->andReturnTrue();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/backup-jobs/'.$job->id.'/restore', $this->payload($backup, $mode, $requestedSafety))
            ->assertSessionHasNoErrors()->assertRedirect();

        $restore = RestoreRun::sole();
        $this->assertSame($mode, $restore->mode);
        $this->assertSame($storedSafety, $restore->backup_before_overwrite);
        $this->assertSame('id:original', $restore->selected_backup_key);
        $this->assertSame($backup->backup_destination_id_snapshot, $restore->backup_destination_id);
        $this->assertSame($mode === 'new_volume' ? 'documents-restored' : 'documents', $restore->target_volume_name);
        $this->assertDatabaseCount('backup_runs', 1);
        Queue::assertPushed(RunRestoreJob::class, 1);
    }

    private function restoreContext(string $currentProvider, string $historicalProvider): array
    {
        Queue::fake();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        $current = $this->destination($currentProvider);
        $historical = $this->destination($historicalProvider);
        $job = BackupJob::create([
            'name' => 'Documents', 'volume_name' => 'documents',
            'backup_destination_id' => $current->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *', 'status' => BackupJob::STATUS_ACTIVE,
        ]);
        DockerVolume::create(['name' => 'documents', 'driver' => 'local', 'is_available' => true]);
        $backup = BackupRun::create([
            'backup_job_id' => $job->id, 'status' => BackupRun::STATUS_SUCCESS, 'trigger' => BackupRun::TRIGGER_MANUAL,
            'backup_key' => $historicalProvider === 'dropbox' ? 'id:original' : 'backup.tar.gz',
            'backup_destination_id_snapshot' => $historical->id,
            'backup_destination_locator_fingerprint' => $historical->locatorFingerprint(),
            'source_type_snapshot' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'source_volume_name' => 'documents',
        ]);

        return [$job, $backup];
    }

    private function destination(string $provider): BackupDestination
    {
        return BackupDestination::create([
            'name' => $provider, 'provider' => $provider, 'bucket' => 'backups',
            'access_key_id' => '', 'secret_access_key' => '', 'is_active' => true,
            'settings' => ['archive_path' => '/tmp/backups'],
        ]);
    }

    private function payload(BackupRun $backup, string $mode, bool $safety): array
    {
        return [
            'backup_run_id' => $backup->id, 'selected_backup_key' => $backup->backup_key,
            'mode' => $mode, 'backup_before_overwrite' => $safety,
            'confirmation_text' => 'documents', 'target_volume_name' => 'documents-restored',
        ];
    }

    private function assertNothingCreated(): void
    {
        $this->assertDatabaseCount('restore_runs', 0);
        $this->assertDatabaseCount('backup_runs', 1);
        $this->assertDatabaseMissing('backup_runs', ['trigger' => BackupRun::TRIGGER_PRE_RESTORE]);
        Queue::assertNothingPushed();
    }
}
