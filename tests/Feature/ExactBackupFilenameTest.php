<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\RenderBackupFilename;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\User;
use App\Services\Agents\AgentCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExactBackupFilenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_mode_defaults_to_false_in_model_and_database_and_migration_is_reversible(): void
    {
        $this->assertFalse((new BackupJob)->use_exact_filename);
        $job = BackupJob::create($this->payload());
        $this->assertFalse($job->fresh()->use_exact_filename);
        $migration = require database_path('migrations/2026_11_04_124751_add_use_exact_filename_to_backup_jobs_table.php');
        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('backup_jobs', 'use_exact_filename'));
        } finally {
            $migration->up();
        }
        $this->assertFalse($job->fresh()->use_exact_filename);
        DB::table('backup_jobs')->insert([
            ...$this->payload(), 'name' => 'Raw insert', 'schedule_config' => '{}',
        ]);
        $this->assertFalse(BackupJob::where('name', 'Raw insert')->firstOrFail()->use_exact_filename);
    }

    public function test_web_create_update_and_exposure(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());
        $payload = $this->payload(['use_exact_filename' => true, 'backup_filename_template' => ' latest-{name} ']);
        $this->post(route('backup-jobs.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $job = BackupJob::firstOrFail();
        $this->assertTrue($job->use_exact_filename);
        $this->assertSame('latest-{name}', $job->backup_filename_template);
        $this->get(route('backup-jobs.show', $job))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('job.use_exact_filename', true)
            ->where('job.backup_filename_template', 'latest-{name}'));
        $this->put(route('backup-jobs.update', $job), [...$payload, 'use_exact_filename' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($job->fresh()->use_exact_filename);
    }

    public function test_api_persists_and_exposes_exact_mode_and_preserves_omitted_mode_on_update(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['read', 'write']);
        $payload = $this->payload(['backup_filename_template' => 'latest']);
        $this->postJson('/api/v1/backup-jobs', $payload)->assertCreated()->assertJsonPath('data.use_exact_filename', false);
        $job = BackupJob::firstOrFail();
        $this->putJson('/api/v1/backup-jobs/'.$job->id, [...$payload, 'use_exact_filename' => true])
            ->assertOk()->assertJsonPath('data.use_exact_filename', true);
        $this->putJson('/api/v1/backup-jobs/'.$job->id, $payload)
            ->assertOk()->assertJsonPath('data.use_exact_filename', true);
        $this->getJson('/api/v1/backup-jobs/'.$job->id)->assertOk()->assertJsonPath('data.use_exact_filename', true);
        $this->getJson('/api/v1/backup-jobs')->assertOk()->assertJsonPath('data.0.use_exact_filename', true);
        $this->putJson('/api/v1/backup-jobs/'.$job->id, [...$payload, 'use_exact_filename' => false])
            ->assertOk()->assertJsonPath('data.use_exact_filename', false);
    }

    public function test_invalid_boolean_and_blank_or_unsafe_exact_templates_are_rejected_by_web_and_api(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $payload = $this->payload();
        foreach (['yes', 'false', 2, [], null] as $invalid) {
            $this->post(route('backup-jobs.store'), [...$payload, 'use_exact_filename' => $invalid])
                ->assertSessionHasErrors('use_exact_filename');
        }
        foreach ([null, '', '   ', '../latest', 'latest.tar.gz', '{unknown}', 'bad%name', 'a:latest'] as $template) {
            $this->post(route('backup-jobs.store'), [...$payload, 'use_exact_filename' => true, 'backup_filename_template' => $template])
                ->assertSessionHasErrors('backup_filename_template');
        }
        Sanctum::actingAs(User::factory()->admin()->create(), ['write']);
        foreach (['yes', 'false', 2, [], null] as $invalid) {
            $this->postJson('/api/v1/backup-jobs', [...$payload, 'use_exact_filename' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('use_exact_filename');
        }
        foreach ([null, '', '   ', '../latest', 'latest.tar.gz', '{unknown}', 'bad%name', 'a:latest'] as $template) {
            $this->postJson('/api/v1/backup-jobs', [...$payload, 'use_exact_filename' => true, 'backup_filename_template' => $template])
                ->assertUnprocessable()->assertJsonValidationErrors('backup_filename_template');
        }
        $this->assertSame(0, BackupJob::count());
    }

    public function test_drive_prefix_produced_by_tokens_is_rejected_before_any_run_is_admitted(): void
    {
        $job = BackupJob::create($this->payload(['name' => 'A', 'use_exact_filename' => true, 'backup_filename_template' => '{name}:latest']));
        try {
            app(CreateBackupRunRecord::class)->handle($job, ['status' => 'queued', 'trigger' => 'manual']);
            $this->fail('Unsafe filename was admitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('backup_filename_template', $exception->errors());
        }
        $this->assertDatabaseCount('backup_runs', 0);
    }

    public function test_missing_template_keeps_existing_reset_semantics_and_cannot_clear_an_exact_template(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['write']);
        $payload = $this->payload();
        $job = BackupJob::create([...$payload, 'backup_filename_template' => 'latest']);
        $this->putJson('/api/v1/backup-jobs/'.$job->id, $payload)
            ->assertOk()->assertJsonPath('data.backup_filename_template', null);
        $job->refresh()->update(['use_exact_filename' => true, 'backup_filename_template' => 'latest']);
        $this->putJson('/api/v1/backup-jobs/'.$job->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('backup_filename_template');
        $this->assertSame('latest', $job->fresh()->backup_filename_template);
    }

    public function test_regular_run_snapshots_mode_template_and_frozen_filename_while_safety_runs_stay_unique(): void
    {
        $job = BackupJob::create($this->payload(['use_exact_filename' => true, 'backup_filename_template' => 'latest-{source}']));
        $create = app(CreateBackupRunRecord::class);
        $run = $create->handle($job, ['trigger' => BackupRun::TRIGGER_MANUAL, 'status' => BackupRun::STATUS_QUEUED]);
        $this->assertTrue($run->execution_options_snapshot['use_exact_filename']);
        $this->assertSame('latest-{source}', $run->execution_options_snapshot['backup_filename_template']);
        $this->assertSame('latest-app_data.tar.gz', $run->backup_filename);
        $job->update(['use_exact_filename' => false, 'backup_filename_template' => 'changed']);
        $renderer = app(RenderBackupFilename::class);
        $this->assertSame('latest-app_data.tar.gz', $renderer->handle($run->fresh()));
        $unfrozen = $run->fresh();
        $unfrozen->backup_filename = null;
        $this->assertSame('latest-app_data.tar.gz', $renderer->handle($unfrozen));
        $job->update(['use_exact_filename' => true]);
        $safety = $create->handle($job, ['trigger' => BackupRun::TRIGGER_PRE_RESTORE, 'status' => BackupRun::STATUS_QUEUED]);
        $anotherSafety = $create->handle($job, ['trigger' => BackupRun::TRIGGER_PRE_RESTORE, 'status' => BackupRun::STATUS_QUEUED]);
        $this->assertSame('volumevault-safety-'.$job->fresh()->archive_namespace.'-run-'.$safety->id.'-changed.tar.gz', $safety->backup_filename);
        $this->assertNotSame($safety->backup_filename, $anotherSafety->backup_filename);
    }

    public function test_preview_uses_actual_renderer_namespace_timezone_and_existing_normalization(): void
    {
        $job = BackupJob::create($this->payload(['name' => 'My job!', 'timezone' => 'Europe/Berlin', 'backup_filename_template' => '{name}-{source}-{year}-{month}-{day}-{time}-{run}']));
        $renderer = app(RenderBackupFilename::class);
        $time = Carbon::parse('2026-11-04 23:30:45', 'UTC');
        $stem = 'My_job_-app_data-2026-11-05-00-30-45-123.tar.gz';
        $this->assertSame('volumevault-00000000-0000-4000-8000-000000000000-run-123-'.$stem, $renderer->preview($job->backup_filename_template, $job, 123, $time));
        $run = app(CreateBackupRunRecord::class)->handle($job, ['trigger' => 'manual', 'status' => 'queued', 'started_at' => $time]);
        $this->assertSame($run->backup_filename, $renderer->preview($job->backup_filename_template, $job->fresh(), $run->id, $time));
        $job->use_exact_filename = true;
        $this->assertSame($stem, $renderer->preview($job->backup_filename_template, $job, 123, $time));
        $this->assertSame('My_job_-été.tar.gz', $renderer->preview('{name}-été', $job, 123, $time));
        $literal = str_repeat('é', 180);
        $unicodeFilename = $renderer->preview($literal, $job, 123, $time);
        $this->assertTrue(mb_check_encoding($unicodeFilename, 'UTF-8'));
        $this->assertLessThanOrEqual(255, strlen($unicodeFilename));
        $this->assertStringEndsWith('-'.substr(hash('sha256', $literal), 0, 12).'.tar.gz', $unicodeFilename);
        $job->name = str_repeat('a', 255);
        $filename = $renderer->preview(str_repeat('{name}', 20), $job, 123, $time);
        $this->assertSame(255, strlen($filename));
        $this->assertMatchesRegularExpression('/-[a-f0-9]{12}\.tar\.gz$/', $filename);
        $this->assertSame($filename, $renderer->preview(str_repeat('{name}', 20), $job, 123, $time));
    }

    public function test_default_prefixed_filename_and_null_template_snapshot_are_unchanged(): void
    {
        $job = BackupJob::create($this->payload());
        $run = app(CreateBackupRunRecord::class)->handle($job, ['trigger' => 'manual', 'status' => 'queued']);
        $expected = 'volumevault-'.$job->fresh()->archive_namespace.'-run-'.$run->id.'-volumevault-app_data-run-'.$run->id.'.tar.gz';
        $this->assertSame($expected, $run->backup_filename);
        $job->update(['backup_filename_template' => 'changed']);
        $run->backup_filename = null;
        $this->assertSame($expected, app(RenderBackupFilename::class)->handle($run->unsetRelation('job')));
    }

    public function test_remote_exact_mode_requires_capability_for_requests_and_regular_runs_but_not_safety_runs(): void
    {
        $host = DockerHost::factory()->create([
            'agent_registered_at' => now(),
            'agent_protocol_version' => 1,
            'agent_capabilities' => [...AgentCompatibility::CAPABILITIES, 'exact-filename-v1'],
        ]);
        $destination = BackupDestination::create([
            'name' => 'Remote compatible', 'provider' => 'custom_s3', 'endpoint' => 'https://93.184.216.34',
            'bucket' => 'archives', 'access_key_id' => 'access', 'secret_access_key' => 'secret',
        ]);
        $payload = $this->payload([
            'docker_host_id' => $host->id, 'backup_destination_id' => $destination->id,
            'use_exact_filename' => true, 'backup_filename_template' => 'latest',
        ]);
        $job = BackupJob::create($payload);
        $create = app(CreateBackupRunRecord::class);
        $this->assertSame('latest.tar.gz', $create->handle($job, ['trigger' => 'manual', 'status' => 'queued'])->backup_filename);
        $host->forceFill(['agent_capabilities' => array_values(array_diff($host->agent_capabilities, ['exact-filename-v1']))])->save();
        $this->assertStringStartsWith('volumevault-safety-', $create->handle($job, ['trigger' => BackupRun::TRIGGER_PRE_RESTORE, 'status' => 'queued'])->backup_filename);
        Sanctum::actingAs(User::factory()->admin()->create(), ['write']);
        $this->postJson('/api/v1/backup-jobs', $payload)->assertUnprocessable()->assertJsonValidationErrors('docker_host_id');
        $this->expectException(ValidationException::class);
        $create->handle($job, ['trigger' => 'manual', 'status' => 'queued']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $destination = BackupDestination::firstOrCreate(['name' => 'Local'], [
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);
        DockerVolume::firstOrCreate(['name' => 'app_data'], ['exists' => true]);

        return [...[
            'name' => 'Job',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => 'daily',
            'schedule_config' => ['time' => '02:00'],
        ], ...$overrides];
    }
}
