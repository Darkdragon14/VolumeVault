<?php

namespace Tests\Feature;
use App\Actions\Backup\RenderBackupFilename;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerVolume;
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

    public function test_a_manual_job_cannot_be_created_for_a_label_managed_volume(): void
    {
        $destination = $this->destination();
        DockerVolume::create(['name' => 'app_data', 'exists' => true]);
        BackupJob::create([
            ...$this->payload($destination, []),
            'status' => BackupJob::STATUS_ACTIVE,
            'configuration_source' => BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL,
            'configuration_key' => hash('sha256', 'docker-label:project/app:default'),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('backup-jobs.create'))
            ->post(route('backup-jobs.store'), $this->payload($destination, []))
            ->assertSessionHasErrors('volume_name');

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

    public function test_rendered_backup_filename_is_bounded_to_255_bytes_with_a_stable_hash(): void
    {
        $job = BackupJob::create($this->payload($this->destination(), [
            'name' => str_repeat('a', 255),
            'backup_filename_template' => str_repeat('{name}', 20).'-éè',
            'status' => BackupJob::STATUS_ACTIVE,
        ]));
        $renderer = app(RenderBackupFilename::class);
        $first = $renderer->preview($job->backup_filename_template, $job, 123);
        $second = $renderer->preview($job->backup_filename_template, $job, 123);

        $this->assertSame(255, strlen($first));
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/-[a-f0-9]{12}\.tar\.gz$/', $first);
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
        $payload = array_merge([
            'name' => 'Job',
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
        ], $overrides);

        if (is_string($payload['volume_name'] ?? null)) {
            DockerVolume::firstOrCreate(['name' => $payload['volume_name']], ['exists' => true]);
        }

        return $payload;
    }
}
