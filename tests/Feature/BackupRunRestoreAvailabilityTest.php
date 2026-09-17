<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupRunRestoreAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('runCases')]
    public function test_detail_explains_only_successful_unverifiable_dropbox_runs(string $provider, ?string $key, string $status, bool $expected, bool $snapshot): void
    {
        $destination = BackupDestination::create([
            'name' => 'Original', 'provider' => $provider,
            'bucket' => 'backups', 'access_key_id' => '', 'secret_access_key' => '',
        ]);
        $job = BackupJob::create([
            'name' => 'Documents', 'volume_name' => 'documents',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);
        $run = $job->runs()->create([
            'status' => $status, 'trigger' => BackupRun::TRIGGER_MANUAL, 'backup_key' => $key,
            'backup_destination_id_snapshot' => $snapshot ? $destination->id : null,
            'backup_destination_provider' => $snapshot ? $provider : null,
        ]);

        if ($snapshot) {
            $replacement = BackupDestination::create([
                'name' => 'Replacement',
                'provider' => $provider === 'dropbox' ? 'local' : 'dropbox',
                'bucket' => 'replacement', 'access_key_id' => '', 'secret_access_key' => '',
            ]);
            $job->update(['backup_destination_id' => $replacement->id]);
        }

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('backup-runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BackupRuns/Show')
                ->where('run.status', $status)
                ->where('run.backup_key', $key)
                ->where('run.restore_unverifiable', $expected)
                ->missing('run.execution_options_snapshot')
                ->missing('run.backup_destination_locator_fingerprint'));
    }

    public static function runCases(): array
    {
        return [
            'Dropbox null snapshot' => ['dropbox', null, 'success', true, true],
            'Dropbox path snapshot' => ['dropbox', '/backups/documents.tar.gz', 'success', true, true],
            'Dropbox stable ID' => ['dropbox', 'id:stable-file', 'success', false, true],
            'legacy Dropbox null' => ['dropbox', null, 'success', true, false],
            'local null' => ['local', null, 'success', false, true],
            'local path' => ['local', 'documents.tar.gz', 'success', false, true],
            'queued Dropbox' => ['dropbox', null, 'queued', false, true],
            'failed Dropbox' => ['dropbox', null, 'failed', false, true],
        ];
    }
}
