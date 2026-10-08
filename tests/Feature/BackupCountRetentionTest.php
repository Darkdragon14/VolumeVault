<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\PruneBackupArchives;
use App\Actions\Backup\RenderBackupFilename;
use App\Actions\Backup\RunBackup;
use App\Actions\Docker\CleanupBackupRetentionHelper;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Docker\RunBackupContainer;
use App\Models\BackupDestination;
use App\Models\BackupGroupRun;
use App\Models\BackupJob;
use App\Models\BackupJobGroup;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BackupCountRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_namespace_migration_rolls_back_and_reapplies_without_losing_jobs(): void
    {
        $job = $this->job();
        $migration = require database_path('migrations/2026_10_08_132442_add_archive_namespace_to_backup_jobs.php');
        $this->assertTrue(Schema::hasColumn('backup_jobs', 'archive_namespace'));

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('backup_jobs', 'archive_namespace'));
            $this->assertSame($job->name, BackupJob::findOrFail($job->id)->name);
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('backup_jobs', 'archive_namespace'));
        $this->assertTrue(Schema::hasIndex('backup_jobs', ['archive_namespace'], 'unique'));
        $this->assertSame($job->name, BackupJob::findOrFail($job->id)->name);
    }

    private function job(array $attributes = []): BackupJob
    {
        $destination = BackupDestination::create(['name' => 'Archives', 'provider' => 'custom_s3', 'endpoint' => 'https://93.184.216.34', 'bucket' => 'archives', 'access_key_id' => 'access', 'secret_access_key' => 'secret', 'is_active' => true]);

        return BackupJob::create(['name' => 'Job', 'volume_name' => 'app_data', 'backup_destination_id' => $destination->id, 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'], 'timezone' => 'UTC', 'status' => 'active', 'notifications_enabled' => false, 'retention_count' => 2, ...$attributes]);
    }

    private function backupRun(?BackupJob $job = null, string $trigger = 'manual'): BackupRun
    {
        return app(CreateBackupRunRecord::class)->handle($job ?? $this->job(), ['status' => 'success', 'trigger' => $trigger]);
    }

    private function object(BackupRun $run, int $id, string $modified = '2026-01-01T00:00:00Z'): array
    {
        $name = RenderBackupFilename::archivePrefix($run->execution_options_snapshot['archive_namespace']).'run-'.$id.'-custom.tar.gz';

        return ['key' => 'backups/'.$name, 'display_name' => $name, 'last_modified' => $modified];
    }

    private function current(BackupRun $run, string $modified = '2020-01-01T00:00:00Z'): array
    {
        return ['key' => 'backups/'.$run->backup_filename, 'display_name' => $run->backup_filename, 'last_modified' => $modified];
    }

    public function test_newest_actual_archives_are_selected_per_job_without_run_rows_and_current_upload_survives_clock_skew(): void
    {
        $run = $this->backupRun();
        $old = $this->object($run, 800);
        $new = $this->object($run, 801, '2026-02-01T00:00:00Z');
        $other = $this->object($this->backupRun(), 802);
        $safety = [...$old, 'key' => 'safety', 'display_name' => str_replace('volumevault-', 'volumevault-safety-', $old['display_name'])];
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturn(['objects' => [$new, $other, $old, $this->current($run), $safety, ['key' => 'unrelated.tar.gz', 'last_modified' => null], ['key' => 'volumevault-app_data-run-100.tar.gz', 'last_modified' => null]], 'next_cursor' => null]);
        $storage->shouldReceive('deleteBackupObjects')->once()->withArgs(fn ($destination, array $keys): bool => $destination->id === $run->backup_destination_id_snapshot && $keys === [$old['key']]);
        app(PruneBackupArchives::class)->handle($run);
        $this->assertStringContainsString('removed 1', $run->fresh()->logs);
    }

    public function test_count_one_and_timestamp_ties_always_keep_the_current_archive(): void
    {
        $run = $this->backupRun($this->job(['retention_count' => 1]));
        $old = $this->object($run, 900, '2020-01-01T00:00:00Z');
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturn(['objects' => [$old, $this->current($run)], 'next_cursor' => null]);
        $storage->shouldReceive('deleteBackupObjects')->once()->withArgs(fn ($destination, array $keys): bool => $keys === [$old['key']]);
        app(PruneBackupArchives::class)->handle($run);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_count_selection_uses_all_listed_archives_beyond_one_thousand(): void
    {
        $run = $this->backupRun($this->job(['retention_count' => 1]));
        $objects = array_map(fn (int $id): array => $this->object($run, $id), range(10, 1014));
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->withArgs(fn ($destination, $cursor): bool => $cursor === null)->andReturn(['objects' => array_slice($objects, 0, 1000), 'next_cursor' => 'page-2']);
        $storage->shouldReceive('listBackupObjectsPage')->once()->withArgs(fn ($destination, $cursor): bool => $cursor === 'page-2')->andReturn(['objects' => [...array_slice($objects, 1000), $this->current($run)], 'next_cursor' => null]);
        $storage->shouldReceive('deleteBackupObjects')->once()->withArgs(fn ($destination, array $keys): bool => count($keys) === 1005 && count(array_unique($keys)) === 1005 && ! in_array($this->current($run)['key'], $keys, true));
        app(PruneBackupArchives::class)->handle($run);
        $this->assertStringContainsString('removed 1005', $run->fresh()->logs);
    }

    #[DataProvider('unsafeListings')]
    public function test_missing_current_archive_or_incomplete_metadata_never_deletes(string $kind): void
    {
        $run = $this->backupRun($this->job(['retention_count' => 1]));
        $objects = [$this->object($run, 900)];
        if ($kind !== 'missing') {
            $objects[] = $this->current($run);
        }
        if ($kind === 'metadata') {
            $objects[0]['last_modified'] = null;
        }
        if ($kind === 'duplicate') {
            $objects[] = [...$this->current($run), 'key' => 'gdrive:duplicate'];
        }
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturn(['objects' => $objects, 'next_cursor' => null]);
        $storage->shouldNotReceive('deleteBackupObjects');
        app(PruneBackupArchives::class)->handle($run);
        $this->assertStringContainsString('could not be completed safely', $run->fresh()->logs);
        $this->assertSame('success', $run->fresh()->status);
    }

    public static function unsafeListings(): array
    {
        return ['missing upload' => ['missing'], 'unknown age' => ['metadata'], 'duplicate upload filename' => ['duplicate']];
    }

    public function test_run_snapshot_preserves_retention_destination_and_namespace_after_job_edit(): void
    {
        $job = $this->job();
        $run = $this->backupRun($job);
        $namespace = $run->execution_options_snapshot['archive_namespace'];
        $old = $this->object($run, 900);
        $new = $this->object($run, 901, '2026-03-01T00:00:00Z');
        $job->forceFill(['retention_count' => 99, 'archive_namespace' => (string) Str::uuid(), 'backup_destination_id' => $this->job()->backup_destination_id])->save();
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->withArgs(fn ($destination, $cursor): bool => $destination->id === $run->backup_destination_id_snapshot)->andReturn(['objects' => [$old, $new, $this->current($run)], 'next_cursor' => null]);
        $storage->shouldReceive('deleteBackupObjects')->once()->withArgs(fn ($destination, array $keys): bool => $keys === [$old['key']]);
        app(PruneBackupArchives::class)->handle($run);
        $this->assertSame($namespace, $run->fresh()->execution_options_snapshot['archive_namespace']);
    }

    public function test_custom_static_names_are_unique_across_jobs_and_runs_and_safety_backups_have_a_separate_prefix(): void
    {
        $job = $this->job(['backup_filename_template' => 'same']);
        $first = $this->backupRun($job);
        $second = $this->backupRun($job);
        $other = $this->backupRun($this->job(['backup_filename_template' => 'same']));
        $safety = $this->backupRun($job, 'pre_restore');
        $this->assertCount(4, array_unique([$first->backup_filename, $second->backup_filename, $other->backup_filename, $safety->backup_filename]));
        $prefix = RenderBackupFilename::archivePrefix($first->execution_options_snapshot['archive_namespace']);
        $this->assertStringStartsWith($prefix, $second->backup_filename);
        $this->assertStringNotContainsString($prefix, $safety->backup_filename);
        $this->assertStringEndsWith('-same.tar.gz', $first->backup_filename);
        $this->assertSame($first->execution_options_snapshot['archive_namespace'], $job->fresh()->archive_namespace);
    }

    #[DataProvider('literalFilenameTemplates')]
    public function test_persisted_templates_with_literal_glob_characters_are_pruned_without_expansion(string $template): void
    {
        $directory = '/tmp/volumevault-literal-retention-'.Str::uuid();
        File::ensureDirectoryExists($directory);
        config(['volumevault.host_path_allowlist' => ['/tmp']]);
        try {
            $this->assertNull(app(RenderBackupFilename::class)->validationError($template));
            $job = $this->job(['retention_count' => 1, 'backup_filename_template' => $template]);
            $job->destination->update(['provider' => 'local', 'settings' => ['archive_path' => $directory]]);
            $old = $this->backupRun($job);
            $current = $this->backupRun($job);
            File::put($directory.'/'.$old->backup_filename, 'old');
            File::put($directory.'/'.$current->backup_filename, 'new');
            File::put($directory.'/unrelated.tar.gz', 'unrelated');

            app(PruneBackupArchives::class)->handle($current);

            $this->assertFileDoesNotExist($directory.'/'.$old->backup_filename);
            $this->assertFileExists($directory.'/'.$current->backup_filename);
            $this->assertFileExists($directory.'/unrelated.tar.gz');
            $this->assertStringContainsString('removed 1', $current->fresh()->logs);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public static function literalFilenameTemplates(): array
    {
        return [['backup-[{year}]'], ['backup-*?']];
    }

    #[DataProvider('unknownAzureTimestamps')]
    public function test_real_azure_listing_with_unknown_age_fails_closed(string $timestamp): void
    {
        $job = $this->job(['retention_count' => 1]);
        $job->destination->update([
            'provider' => 'azure_blob',
            'settings' => ['account_name' => 'account', 'container' => 'archives', 'endpoint' => 'https://93.184.216.34'],
            'secrets' => ['account_key' => base64_encode('test-key')],
        ]);
        $run = $this->backupRun($job);
        $old = $this->object($run, 900);
        $dateElement = $timestamp === '' ? '' : '<Last-Modified>'.$timestamp.'</Last-Modified>';
        $xml = '<EnumerationResults><Blobs><Blob><Name>'.$run->backup_filename.'</Name><Properties><Content-Length>4</Content-Length><Last-Modified>2026-10-08T12:00:00Z</Last-Modified></Properties></Blob>'
            .'<Blob><Name>'.$old['display_name'].'</Name><Properties><Content-Length>4</Content-Length>'.$dateElement.'</Properties></Blob></Blobs><NextMarker/></EnumerationResults>';
        Http::preventStrayRequests();
        Http::fake(['https://93.184.216.34/*' => Http::response($xml)]);

        app(PruneBackupArchives::class)->handle($run);

        $this->assertSame('success', $run->fresh()->status);
        $this->assertStringContainsString('could not be completed safely', $run->fresh()->logs);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public static function unknownAzureTimestamps(): array
    {
        return [[''], ['invalid-date']];
    }

    public function test_age_retention_is_post_success_scoped_to_job_and_preserves_current_upload(): void
    {
        $run = $this->backupRun($this->job(['retention_count' => null, 'retention_days' => 7]));
        $old = $this->object($run, 900, now()->subDays(8)->toIso8601String());
        $new = $this->object($run, 901, now()->subDay()->toIso8601String());
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturn(['objects' => [$old, $new, $this->current($run), $this->object($this->backupRun(), 902)], 'next_cursor' => null]);
        $storage->shouldReceive('deleteBackupObjects')->once()->withArgs(fn ($destination, array $keys): bool => $keys === [$old['key']]);
        app(PruneBackupArchives::class)->handle($run);
        $this->assertStringContainsString('removed 1', $run->fresh()->logs);
    }

    #[DataProvider('nonPruningRuns')]
    public function test_failed_queued_cancelled_and_safety_runs_do_not_even_list(string $status, string $trigger): void
    {
        $run = $this->backupRun(null, $trigger);
        $run->update(['status' => $status]);
        $this->mock(DestinationStorage::class)->shouldNotReceive('listBackupObjectsPage', 'deleteBackupObjects');
        app(PruneBackupArchives::class)->handle($run);
        $this->assertNull($run->fresh()->logs);
    }

    public static function nonPruningRuns(): array
    {
        return [['failed', 'manual'], ['queued', 'manual'], ['cancelled', 'scheduled'], ['success', 'pre_restore']];
    }

    #[DataProvider('regularRuns')]
    public function test_regular_backup_pipeline_prunes_after_success_without_turning_deletion_errors_into_backup_failures(string $trigger, bool $grouped): void
    {
        Queue::fake();
        $job = $this->job(['retention_count' => 1]);
        $run = $this->backupRun($job, $trigger);
        $run->update(['status' => 'queued']);
        if ($grouped) {
            $group = BackupJobGroup::create(['name' => 'Group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'], 'timezone' => 'UTC', 'status' => 'active']);
            $job->update(['backup_job_group_id' => $group->id]);
            $groupRun = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'running', 'trigger' => $trigger]);
            $run->update(['backup_group_run_id' => $groupRun->id]);
        }
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, 'uploaded', ''));
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('useOperationProgress')->twice();
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturnUsing(function () use ($run): array {
            $this->assertSame('success', $run->fresh()->status);

            return ['objects' => [$this->object($run, 900), $this->current($run)], 'next_cursor' => null];
        });
        $storage->shouldReceive('deleteBackupObjects')->once()->andThrow(new RuntimeException('plaintext-secret'));
        app(RunBackup::class)->handle($run);
        $this->assertSame('success', $run->fresh()->status);
        $this->assertNull($run->fresh()->error_message);
        $this->assertSame('active', $job->fresh()->status);
        $this->assertStringContainsString('uploaded backup remains successful', $run->fresh()->logs);
        $this->assertStringNotContainsString('plaintext-secret', $run->fresh()->logs);
    }

    public static function regularRuns(): array
    {
        return ['manual' => ['manual', false], 'scheduled' => ['scheduled', false], 'group' => ['manual', true]];
    }

    public function test_slow_final_member_retention_refreshes_group_lease_during_listing_and_deletion(): void
    {
        Queue::fake();
        $this->freezeTime();
        $group = BackupJobGroup::create(['name' => 'Group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'], 'timezone' => 'UTC', 'status' => 'running']);
        $job = $this->job(['retention_count' => 1, 'backup_job_group_id' => $group->id]);
        $groupRun = BackupGroupRun::create(['backup_job_group_id' => $group->id, 'status' => 'running', 'trigger' => 'manual', 'started_at' => now(), 'last_heartbeat_at' => now()]);
        $run = $this->backupRun($job);
        $run->update(['status' => 'queued', 'backup_group_run_id' => $groupRun->id]);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, 'uploaded', ''));
        $progress = null;
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('useOperationProgress')->twice()->andReturnUsing(function (?callable $callback) use (&$progress): void {
            $progress = $callback;
        });
        $slowWork = function () use (&$progress, $run, $groupRun): void {
            for ($i = 0; $i < 4; $i++) {
                $this->travel(40)->seconds();
                $progress();
                $this->assertSame('success', $run->fresh()->status);
                $this->assertSame(now()->getTimestamp(), $run->fresh()->last_heartbeat_at->getTimestamp());
                $this->assertSame(now()->getTimestamp(), $groupRun->fresh()->last_heartbeat_at->getTimestamp());
                $this->artisan('volumevault:reconcile-stale-runs', ['--minutes' => 1])->assertSuccessful();
                $this->assertSame('running', $groupRun->fresh()->status);
            }
        };
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturnUsing(function () use ($slowWork, $run): array {
            $slowWork();

            return ['objects' => [$this->current($run), $this->object($run, 900)], 'next_cursor' => null];
        });
        $storage->shouldReceive('deleteBackupObjects')->once()->andReturnUsing($slowWork);
        app(RunBackup::class)->handle($run);
        $this->assertSame('running', $groupRun->fresh()->status);
        $this->assertNull($progress);
    }

    public function test_interrupted_retention_cleanup_keeps_success_and_pending_ownership_until_verified_removal(): void
    {
        Queue::fake();
        $job = $this->job(['retention_count' => 1]);
        $job->destination->update(['provider' => 'docker_volume', 'settings' => ['volume_name' => 'archives']]);
        $run = $this->backupRun($job);
        $name = CleanupBackupRetentionHelper::name($run);
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('useOperationHelper')->once()->with($name);
        $storage->shouldReceive('useOperationHelper')->once()->with(null);
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturnUsing(function () use ($run, $name): array {
            $this->assertSame($name, $run->fresh()->docker_container_id);
            $this->assertTrue($run->fresh()->docker_container_cleanup_pending);

            return ['objects' => [$this->current($run), $this->object($run, 900)], 'next_cursor' => null];
        });
        $storage->shouldReceive('deleteBackupObjects')->once()->andThrow(new RuntimeException('interrupted'));
        $this->mock(RemoveDockerContainer::class)->shouldReceive('handle')->twice()->with($name);
        $docker = $this->mock(DockerProcess::class);
        $docker->shouldReceive('run')->once()->with(['docker', 'container', 'inspect', $name], 30)->andReturn(new DockerProcessResult([], 0, 'still exists', ''));
        app(PruneBackupArchives::class)->handle($run);
        $this->assertSame('success', $run->fresh()->status);
        $this->assertTrue($run->fresh()->docker_container_cleanup_pending);
        $this->artisan('volumevault:reconcile-stale-runs', ['--minutes' => 1])->assertSuccessful();
        $this->assertTrue($run->fresh()->docker_container_cleanup_pending);
        $run->update(['last_heartbeat_at' => now()->subMinutes(2)]);
        $docker->shouldReceive('run')->once()->with(['docker', 'container', 'inspect', $name], 30)->andReturn(new DockerProcessResult([], 1, '', 'Error: No such container: '.$name));
        $this->artisan('volumevault:reconcile-stale-runs', ['--minutes' => 1])->assertSuccessful();
        $this->assertFalse($run->fresh()->docker_container_cleanup_pending);
        $this->assertNull($run->fresh()->docker_container_id);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_failed_container_never_prunes(): void
    {
        Queue::fake();
        $run = $this->backupRun();
        $run->update(['status' => 'queued']);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 1, '', 'failed'));
        $this->mock(DestinationStorage::class)->shouldNotReceive('listBackupObjectsPage', 'deleteBackupObjects');
        app(RunBackup::class)->handle($run);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_remote_retention_requires_capability_and_sends_immutable_namespace(): void
    {
        $host = DockerHost::factory()->create(['agent_registered_at' => now(), 'agent_protocol_version' => 1, 'agent_capabilities' => AgentCompatibility::CAPABILITIES]);
        $job = $this->job(['docker_host_id' => $host->id]);
        $run = $this->backupRun($job);
        $spec = app(DispatchAgentOperation::class)->specification($run);
        $this->assertSame($run->execution_options_snapshot['archive_namespace'], $spec['job']['archive_namespace']);
        $this->assertSame($run->backup_filename, $spec['run']['backup_filename']);
        $host->forceFill(['agent_capabilities' => ['inventory-v1', 'backup-v1']])->save();
        $this->expectException(ValidationException::class);
        $this->backupRun($job);
    }

    public function test_changed_destination_locator_fails_closed(): void
    {
        $run = $this->backupRun();
        $run->destinationForRun()->update(['bucket' => 'other-bucket']);
        $this->mock(DestinationStorage::class)->shouldNotReceive('listBackupObjectsPage', 'deleteBackupObjects');
        app(PruneBackupArchives::class)->handle($run);
        $this->assertStringContainsString('could not be completed safely', $run->fresh()->logs);
    }

    #[DataProvider('brokenPagination')]
    public function test_pagination_errors_after_current_upload_is_seen_fail_closed(bool $throws): void
    {
        $run = $this->backupRun($this->job(['retention_count' => 1]));
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->withArgs(fn ($destination, $cursor): bool => $cursor === null)->andReturn(['objects' => [$this->current($run), $this->object($run, 900)], 'next_cursor' => 'page-2']);
        $next = $storage->shouldReceive('listBackupObjectsPage')->once()->withArgs(fn ($destination, $cursor): bool => $cursor === 'page-2');
        if ($throws) {
            $next->andThrow(new RuntimeException('secret pagination error'));
        } else {
            $next->andReturn(['objects' => [], 'next_cursor' => 'page-2']);
        }
        $storage->shouldNotReceive('deleteBackupObjects');
        app(PruneBackupArchives::class)->handle($run);
        $this->assertStringContainsString('could not be completed safely', $run->fresh()->logs);
        $this->assertStringNotContainsString('secret pagination error', $run->fresh()->logs);
    }

    public static function brokenPagination(): array
    {
        return ['late listing exception' => [true], 'repeated provider cursor' => [false]];
    }

    public function test_real_local_storage_removes_only_selected_job_archives(): void
    {
        $directory = sys_get_temp_dir().'/volumevault-retention-'.Str::uuid();
        File::ensureDirectoryExists($directory);
        config(['volumevault.host_path_allowlist' => [$directory]]);
        try {
            $job = $this->job(['retention_count' => 1]);
            $job->destination->update(['provider' => 'local', 'settings' => ['archive_path' => $directory]]);
            $run = $this->backupRun($job);
            $old = $this->object($run, 900)['display_name'];
            foreach ([$run->backup_filename, $old, 'unrelated.tar.gz', 'volumevault-app_data-run-123.tar.gz'] as $name) {
                File::put($directory.'/'.$name, 'archive');
            }
            app(PruneBackupArchives::class)->handle($run);
            $this->assertFileDoesNotExist($directory.'/'.$old);
            $this->assertFileExists($directory.'/'.$run->backup_filename);
            $this->assertFileExists($directory.'/unrelated.tar.gz');
            $this->assertFileExists($directory.'/volumevault-app_data-run-123.tar.gz');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_pre_restore_pipeline_does_not_prune_or_change_paused_job_lifecycle(): void
    {
        Queue::fake();
        $job = $this->job(['status' => 'paused', 'retention_days' => 7, 'retention_count' => 1]);
        $run = $this->backupRun($job, 'pre_restore');
        $run->update(['status' => 'queued']);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, 'safety upload', ''));
        $this->mock(ListBackupObjects::class)->shouldReceive('findByFilename')->once()->andReturn(['key' => $run->backup_filename, 'size' => 42]);
        $this->mock(DestinationStorage::class)->shouldNotReceive('listBackupObjectsPage', 'deleteBackupObjects');
        app(RunBackup::class)->handle($run);
        $this->assertSame('success', $run->fresh()->status);
        $this->assertSame('paused', $job->fresh()->status);
        $this->assertNull($job->fresh()->last_success_at);
    }

    public function test_retention_disabled_does_not_list_archives(): void
    {
        $run = $this->backupRun($this->job(['retention_count' => null, 'retention_days' => null]));
        $this->mock(DestinationStorage::class)->shouldNotReceive('listBackupObjectsPage', 'deleteBackupObjects');
        app(PruneBackupArchives::class)->handle($run);
        $this->assertNull($run->fresh()->logs);
    }

    #[DataProvider('unsafeAgentOwnership')]
    public function test_agent_rejects_retention_payload_without_matching_regular_job_namespace(string $kind): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        $namespace = (string) Str::uuid();
        $operation['spec']['job']['retention_count'] = 1;
        if ($kind !== 'missing') {
            $operation['spec']['job']['archive_namespace'] = $namespace;
        }
        $operation['spec']['run']['backup_filename'] = $kind === 'safety'
            ? RenderBackupFilename::archivePrefix($namespace, true).'run-42-backup.tar.gz'
            : 'unrelated.tar.gz';
        $this->expectException(RuntimeException::class);
        app(AgentOperationSpecification::class)->validate($operation);
    }

    public static function unsafeAgentOwnership(): array
    {
        return ['missing namespace' => ['missing'], 'different job' => ['different'], 'safety backup prefix' => ['safety']];
    }
}
