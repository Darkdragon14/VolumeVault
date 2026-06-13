<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupJobVolumeNameValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_volume_name_with_invalid_characters_is_rejected(): void
    {
        $destination = $this->destination();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.create'))
            ->post(route('backup-jobs.store'), $this->payload($destination, [
                'volume_name' => 'app_data --mount=evil',
            ]))
            ->assertSessionHasErrors('volume_name');

        $this->assertSame(0, BackupJob::count());
    }

    public function test_valid_volume_name_is_accepted(): void
    {
        $destination = $this->destination();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.create'))
            ->post(route('backup-jobs.store'), $this->payload($destination, [
                'volume_name' => 'app_data.01-prod',
            ]))
            ->assertSessionDoesntHaveErrors('volume_name');

        $this->assertSame(1, BackupJob::count());
    }

    public function test_backup_filename_template_with_unknown_token_is_rejected(): void
    {
        $destination = $this->destination();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.create'))
            ->post(route('backup-jobs.store'), $this->payload($destination, [
                'backup_filename_template' => '{name}-{unknown}',
            ]))
            ->assertSessionHasErrors('backup_filename_template');

        $this->assertSame(0, BackupJob::count());
    }

    public function test_backup_filename_template_with_path_segments_is_rejected(): void
    {
        $destination = $this->destination();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.create'))
            ->post(route('backup-jobs.store'), $this->payload($destination, [
                'backup_filename_template' => '../{name}-{id}',
            ]))
            ->assertSessionHasErrors('backup_filename_template');

        $this->assertSame(0, BackupJob::count());
    }

    public function test_valid_backup_filename_template_is_stored(): void
    {
        $destination = $this->destination();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.create'))
            ->post(route('backup-jobs.store'), $this->payload($destination, [
                'backup_filename_template' => '{name}-{year}-{month}-{day}-{time}',
            ]))
            ->assertSessionDoesntHaveErrors('backup_filename_template');

        $this->assertSame('{name}-{year}-{month}-{day}-{time}', BackupJob::first()?->backup_filename_template);
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(BackupDestination $destination, array $overrides): array
    {
        return array_merge([
            'name' => 'Job',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
        ], $overrides);
    }
}
