<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Backup\ExactFilenameRetention;
use App\Actions\Backup\PruneBackupArchives;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\DockerHost;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\DispatchAgentOperation;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerVolumeName;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExactFilenameRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $attributes = [], ?BackupDestination $destination = null): BackupJob
    {
        $destination ??= BackupDestination::create(['name' => 'Archives', 'provider' => 'custom_s3', 'endpoint' => 'https://93.184.216.34', 'bucket' => 'archives', 'access_key_id' => 'access', 'secret_access_key' => 'secret', 'is_active' => true]);

        return BackupJob::create(['name' => 'Job', 'volume_name' => 'app_data', 'backup_destination_id' => $destination->id, 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'], 'timezone' => 'UTC', 'status' => 'active', 'notifications_enabled' => false, 'retention_count' => 1, 'use_exact_filename' => true, 'backup_filename_template' => 'backup-{run}', ...$attributes]);
    }

    private function backupRun(BackupJob $job, string $trigger = 'manual'): BackupRun
    {
        return app(CreateBackupRunRecord::class)->handle($job, ['status' => 'success', 'trigger' => $trigger]);
    }

    private function object(BackupRun $run, string $modified = '2026-01-01T00:00:00Z'): array
    {
        return ['key' => 'backups/'.$run->backup_filename, 'display_name' => $run->backup_filename, 'size' => 100, 'last_modified' => $modified];
    }

    private function track(BackupRun $run, ?array $object = null): array
    {
        $object ??= $this->object($run);
        $run->forceFill(['backup_retention_identity' => app(ExactFilenameRetention::class)->identity($object)])->save();

        return $object;
    }

    private function prune(BackupRun $run, array $objects, array $deleted = []): void
    {
        $storage = $this->mock(DestinationStorage::class);
        $storage->shouldReceive('listBackupObjectsPage')->once()->andReturn(['objects' => $objects, 'next_cursor' => null]);
        if ($deleted === []) {
            $storage->shouldNotReceive('deleteBackupObjects');
        } else {
            $storage->shouldReceive('deleteBackupObjects')->once()->withArgs(fn ($destination, array $keys): bool => $keys === $deleted);
        }
        app(PruneBackupArchives::class)->handle($run);
    }

    private function seedHistory(BackupRun $reference, int $count, bool $distinctNames = false): void
    {
        $attributes = $reference->getAttributes();
        unset($attributes['id']);
        foreach (array_chunk(range(1, $count), 250) as $batch) {
            $rows = [];
            foreach ($batch as $index) {
                $row = $attributes;
                if ($distinctNames) {
                    $row['backup_filename'] = 'historical-'.$index.'.tar.gz';
                    if ($reference->backup_retention_identity !== null) {
                        $row['backup_retention_identity'] = json_encode([
                            ...$reference->backup_retention_identity,
                            'key' => 'backups/'.$row['backup_filename'], 'filename' => $row['backup_filename'],
                        ], JSON_THROW_ON_ERROR);
                    }
                }
                $rows[] = $row;
            }
            DB::table('backup_runs')->insert($rows);
        }
    }

    public function test_mysql_style_reordered_json_identities_match_for_old_and_current_archives(): void
    {
        $job = $this->job();
        $oldRun = $this->backupRun($job);
        $old = $this->track($oldRun);
        $oldRun->forceFill(['backup_retention_identity' => array_reverse($oldRun->backup_retention_identity, true)])->save();
        $run = $this->backupRun($job);
        $current = $this->track($run);
        $run->forceFill(['backup_retention_identity' => array_reverse($run->backup_retention_identity, true)])->save();
        $this->assertTrue(app(ExactFilenameRetention::class)->matches($old, $oldRun->fresh()->backup_retention_identity));
        $this->prune($run->fresh(), [$old, $current], [$old['key']]);
        $this->assertSame('success', $run->fresh()->status);
    }

    #[DataProvider('changedIdentityFields')]
    public function test_reordered_identities_still_require_each_field_value_and_type(string $field, mixed $value): void
    {
        $object = ['key' => 'backups/backup.tar.gz', 'display_name' => 'backup.tar.gz', 'size' => 100, 'last_modified' => '2026-01-01T00:00:00Z'];
        $retention = app(ExactFilenameRetention::class);
        $identity = array_reverse($retention->identity($object), true);
        $identity[$field] = $value;
        $this->assertFalse($retention->matches($object, $identity));
        unset($identity[$field]);
        $this->assertFalse($retention->matches($object, $identity));
    }

    public static function changedIdentityFields(): array
    {
        return [['key', 'another/backup.tar.gz'], ['filename', 'other.tar.gz'], ['size', 101], ['size', '100'], ['size', 100.0], ['modified', 1767225601], ['modified', '1767225600']];
    }

    #[DataProvider('namingModes')]
    public function test_more_than_ten_thousand_unrelated_runs_do_not_disable_standard_local_or_agent_retention(bool $otherExact): void
    {
        $other = $this->backupRun($this->job(['use_exact_filename' => $otherExact]));
        if ($otherExact) {
            $this->track($other);
        }
        $this->seedHistory($other, 10001, distinctNames: true);
        $this->backupRun($this->job());
        $job = $this->job(['use_exact_filename' => false]);
        $old = $this->object($this->backupRun($job));
        $run = $this->backupRun($job);
        $manifest = app(ExactFilenameRetention::class)->manifest($run);
        $this->assertTrue($manifest['complete']);
        $this->assertSame([], $manifest['archives']);
        $this->prune($run, [$old, $this->object($run)], [$old['key']]);
    }

    #[DataProvider('namingModes')]
    public function test_large_own_exact_history_is_scoped_to_present_archives_in_both_modes(bool $exact): void
    {
        $job = $this->job();
        $oldRun = $this->backupRun($job);
        $old = $this->track($oldRun);
        $this->seedHistory($oldRun, 10001, distinctNames: true);
        $job->update(['use_exact_filename' => $exact]);
        $run = $this->backupRun($job);
        $manifest = app(ExactFilenameRetention::class)->manifest($run, [$oldRun->backup_filename, $run->backup_filename]);
        $this->assertTrue($manifest['complete']);
        $this->assertCount(1, $manifest['archives']);
        $this->prune($run, [$old, $this->object($run)], [$old['key']]);
    }

    public static function namingModes(): array
    {
        return [[false], [true]];
    }

    public function test_duplicate_history_rows_protect_the_name_without_disabling_standard_agent_cleanup(): void
    {
        $job = $this->job(['backup_filename_template' => 'constant']);
        $duplicateRun = $this->backupRun($job);
        $duplicate = $this->track($duplicateRun);
        $this->seedHistory($duplicateRun, 10001);
        $job->update(['use_exact_filename' => false]);
        $old = $this->object($this->backupRun($job));
        $run = $this->backupRun($job);
        $manifest = app(ExactFilenameRetention::class)->manifest($run);
        $this->assertTrue($manifest['complete']);
        $this->assertSame([$duplicateRun->backup_filename], $manifest['protected_filenames']);
        $run->update(['execution_options_snapshot' => [...$run->execution_options_snapshot, 'retention_manifest' => $manifest]]);
        $this->prune($run, [$old, $duplicate, $this->object($run)], [$old['key']]);
    }

    public function test_history_scoping_still_finds_other_job_collisions_beyond_ten_thousand_rows(): void
    {
        $job = $this->job(['use_exact_filename' => false]);
        $oldRun = $this->backupRun($job);
        $old = $this->object($oldRun);
        $unrelated = $this->backupRun($this->job(['use_exact_filename' => false]));
        $this->seedHistory($unrelated, 10001, distinctNames: true);
        $collision = $this->backupRun($this->job());
        $collision->forceFill(['backup_filename' => $oldRun->backup_filename])->save();
        $run = $this->backupRun($job);
        $manifest = app(ExactFilenameRetention::class)->manifest($run);
        $this->assertTrue($manifest['complete']);
        $this->assertContains($oldRun->backup_filename, $manifest['protected_filenames']);
        $run->update(['execution_options_snapshot' => [...$run->execution_options_snapshot, 'retention_manifest' => $manifest]]);
        $this->prune($run, [$old, $this->object($run)]);
    }

    public function test_agent_manifest_remains_bounded_by_distinct_relevant_names_not_total_history(): void
    {
        $job = $this->job();
        $oldRun = $this->backupRun($job);
        $this->track($oldRun);
        $this->seedHistory($oldRun, 10001, distinctNames: true);
        $manifest = app(ExactFilenameRetention::class)->manifest($this->backupRun($job));
        $this->assertFalse($manifest['complete']);
        $this->assertSame([], $manifest['archives']);
        $this->assertSame([], $manifest['protected_filenames']);
    }

    public function test_case_insensitive_database_collation_cannot_hide_another_jobs_exact_archive(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->string('backup_filename')->nullable()->collation('NOCASE')->change();
        });
        $job = $this->job(['use_exact_filename' => false]);
        $oldRun = $this->backupRun($job);
        $collision = $this->backupRun($this->job());
        $collision->forceFill(['backup_filename' => str_replace('-backup-', '-BACKUP-', $oldRun->backup_filename)])->save();
        $run = $this->backupRun($job);
        $manifest = app(ExactFilenameRetention::class)->manifest($run);
        $this->assertContains($collision->backup_filename, $manifest['protected_filenames']);
        $run->update(['execution_options_snapshot' => [...$run->execution_options_snapshot, 'retention_manifest' => $manifest]]);
        $this->prune($run, [$this->object($oldRun), $this->object($collision), $this->object($run)]);
    }

    public function test_retention_uses_verified_records_and_always_preserves_current_upload(): void
    {
        $job = $this->job();
        $old = $this->track($this->backupRun($job));
        $run = $this->backupRun($job);
        $this->prune($run, [$old, $this->object($run, '2020-01-01T00:00:00Z'), ['key' => 'unrelated.tar.gz']], [$old['key']]);
        $this->assertNotNull($run->fresh()->backup_retention_identity);
    }

    public function test_day_retention_works_independently_of_count(): void
    {
        $job = $this->job(['retention_count' => null, 'retention_days' => 7]);
        $old = $this->track($this->backupRun($job));
        $run = $this->backupRun($job);
        $this->prune($run, [$old, $this->object($run)], [$old['key']]);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_identity_is_captured_even_without_retention(): void
    {
        $run = $this->backupRun($this->job(['retention_count' => null]));
        $this->prune($run, [$this->object($run)]);
        $this->assertSame(app(ExactFilenameRetention::class)->identity($this->object($run)), $run->fresh()->backup_retention_identity);
    }

    #[DataProvider('unverifiedObjects')]
    public function test_unverified_or_replaced_archive_is_not_deleted(string $kind): void
    {
        $job = $this->job();
        $oldRun = $this->backupRun($job);
        $old = $this->track($oldRun);
        if ($kind === 'size') {
            $old['size']++;
        } elseif ($kind === 'time') {
            $old['last_modified'] = '2026-02-01T00:00:00Z';
        } elseif ($kind === 'missing') {
            $oldRun->forceFill(['backup_retention_identity' => null])->save();
        } elseif ($kind === 'history') {
            $oldRun->delete();
        }
        $run = $this->backupRun($job);
        $this->prune($run, [$old, $this->object($run)]);
        $this->assertSame('success', $run->fresh()->status);
    }

    public static function unverifiedObjects(): array
    {
        return [['size'], ['time'], ['missing'], ['history']];
    }

    public function test_duplicate_names_and_other_job_references_are_protected_across_destination_aliases(): void
    {
        $job = $this->job();
        $oldRun = $this->backupRun($job);
        $old = $this->track($oldRun);
        $other = $this->job(['backup_filename_template' => pathinfo($oldRun->backup_filename, PATHINFO_FILENAME)]);
        $otherRun = $this->backupRun($other);
        $otherRun->forceFill(['backup_filename' => $oldRun->backup_filename])->save();
        $run = $this->backupRun($job);
        $this->prune($run, [$old, $this->object($run)]);
        $this->assertSame($otherRun->backup_filename, $oldRun->backup_filename);
    }

    public function test_switching_modes_keeps_both_generations_under_retention_but_not_safety_archives(): void
    {
        $job = $this->job(['use_exact_filename' => false]);
        $prefixed = $this->object($this->backupRun($job));
        $job->update(['use_exact_filename' => true]);
        $exact = $this->track($this->backupRun($job));
        $safety = $this->object($this->backupRun($job, 'pre_restore'));
        $job->update(['use_exact_filename' => false]);
        $run = $this->backupRun($job);
        $this->prune($run, [$prefixed, $exact, $safety, $this->object($run)], [$prefixed['key'], $exact['key']]);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_current_duplicate_name_or_missing_metadata_fails_closed(): void
    {
        $run = $this->backupRun($this->job());
        $this->prune($run, [$this->object($run), [...$this->object($run), 'key' => 'duplicate']]);
        $this->assertNull($run->fresh()->backup_retention_identity);
        $this->assertStringContainsString('could not be completed safely', $run->fresh()->logs);
    }

    #[DataProvider('safeTemplates')]
    public function test_remote_manifest_carries_history_without_exposing_it_through_run_serialization(string $template): void
    {
        config(['volumevault.mode' => 'controller']);
        $host = DockerHost::factory()->create(['agent_registered_at' => now(), 'agent_protocol_version' => 1, 'agent_capabilities' => AgentCompatibility::CAPABILITIES]);
        $job = $this->job(['name' => 'Job..data', 'docker_host_id' => $host->id, 'backup_filename_template' => $template]);
        $old = $this->track($this->backupRun($job));
        $run = $this->backupRun($job);
        $spec = app(DispatchAgentOperation::class)->specification($run);
        $this->assertTrue($spec['job']['use_exact_filename']);
        $this->assertSame($old['key'], $spec['retention_manifest']['archives'][0]['key']);
        app(AgentOperationSpecification::class)->validate(['id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'kind' => 'backup', 'spec' => $spec]);
        app(AgentOperationSpecification::class)->validate(['id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'kind' => 'destination', 'spec' => [
            'version' => 1, 'destination' => $spec['destination'], 'action' => 'metadata', 'limit' => 1,
            'archive' => ['filename' => $run->backup_filename, 'key' => null, 'size' => null],
        ]]);
        $this->assertArrayNotHasKey('backup_retention_identity', $run->toArray());
    }

    public static function safeTemplates(): array
    {
        return [['backup-{run}'], ['café-{run}'], ['backup [daily]-{run}'], ['_latest-{run}'], ['backup-*?-{run}'], ['{name}-{run}']];
    }

    #[DataProvider('unsafeArchiveNames')]
    public function test_remote_filename_rules_reject_unsafe_basenames_in_backup_and_metadata_operations(string $filename, string $kind): void
    {
        $spec = AgentOperationRuntimeTest::operation()['spec'];
        if ($kind === 'backup') {
            $spec['run']['backup_filename'] = $filename;
        } else {
            $spec = ['version' => 1, 'destination' => $spec['destination'], 'action' => 'metadata', 'limit' => 1,
                'archive' => ['filename' => $filename, 'key' => null, 'size' => null]];
        }
        $this->expectException(\RuntimeException::class);
        app(AgentOperationSpecification::class)->validate(['id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'kind' => $kind, 'spec' => $spec]);
    }

    public static function unsafeArchiveNames(): array
    {
        $cases = [];
        foreach (['backup', 'destination'] as $kind) {
            foreach (['.', '..', 'a:latest.tar.gz', '../backup.tar.gz', 'dir/backup.tar.gz', 'dir\\backup.tar.gz', "bad\nname.tar.gz", 'bad%name.tar.gz', str_repeat('é', 125).'.tar.gz'] as $filename) {
                $cases[] = [$filename, $kind];
            }
        }

        return $cases;
    }

    public function test_standard_remote_retention_requires_upgrade_when_exact_history_exists_on_destination(): void
    {
        $host = DockerHost::factory()->create(['agent_registered_at' => now(), 'agent_protocol_version' => 1, 'agent_capabilities' => AgentCompatibility::CAPABILITIES]);
        $job = $this->job(['docker_host_id' => $host->id]);
        $this->backupRun($job);
        $job->update(['use_exact_filename' => false]);
        $run = $this->backupRun($job);
        $host->forceFill(['agent_capabilities' => array_values(array_diff(AgentCompatibility::CAPABILITIES, ['exact-filename-v1']))])->save();
        $this->expectException(ValidationException::class);
        app(DispatchAgentOperation::class)->specification($run);
    }

    public function test_agent_uses_supplied_manifest_instead_of_its_isolated_history(): void
    {
        $job = $this->job();
        $oldRun = $this->backupRun($job);
        $old = $this->track($oldRun);
        $run = $this->backupRun($job);
        $run->update(['execution_options_snapshot' => [...$run->execution_options_snapshot, 'retention_manifest' => app(ExactFilenameRetention::class)->manifest($run)]]);
        $oldRun->delete();
        $this->prune($run, [$old, $this->object($run)], [$old['key']]);
        $this->assertNotNull($run->fresh()->backup_retention_identity);
    }

    public function test_incomplete_agent_manifest_never_deletes(): void
    {
        $run = $this->backupRun($this->job());
        $run->update(['execution_options_snapshot' => [...$run->execution_options_snapshot, 'retention_manifest' => ['complete' => false, 'archives' => [], 'protected_filenames' => [], 'exact_filenames' => []]]]);
        $this->prune($run, [$this->object($run)]);
        $this->assertStringContainsString('could not be completed safely', $run->fresh()->logs);
    }

    #[DataProvider('storageTemplates')]
    public function test_exact_names_round_trip_through_real_local_listing_download_and_retention(string $template): void
    {
        $directory = '/tmp/volumevault-exact-storage-'.Str::uuid();
        File::ensureDirectoryExists($directory);
        config(['volumevault.host_path_allowlist' => ['/tmp']]);
        try {
            $job = $this->job(['backup_filename_template' => $template]);
            $job->destination->update(['provider' => 'local', 'settings' => ['archive_path' => $directory]]);
            $old = $this->backupRun($job);
            $contents = gzencode('archive contents');
            File::put($directory.'/'.$old->backup_filename, $contents);
            app(PruneBackupArchives::class)->handle($old);
            $this->assertNotNull($old->fresh()->backup_retention_identity);
            $this->assertSame($old->backup_filename, DockerVolumeName::assertKey($old->backup_filename));
            app(DestinationStorage::class)->download($job->destination, $old->backup_filename, $directory.'/downloaded.tar.gz');
            $this->assertSame($contents, File::get($directory.'/downloaded.tar.gz'));

            $current = $this->backupRun($job);
            File::put($directory.'/'.$current->backup_filename, gzencode('new contents'));
            app(PruneBackupArchives::class)->handle($current);
            $this->assertFileDoesNotExist($directory.'/'.$old->backup_filename);
            $this->assertFileExists($directory.'/'.$current->backup_filename);
            $this->assertFileExists($directory.'/downloaded.tar.gz');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public static function storageTemplates(): array
    {
        return [['daily:backup-{run}'], ['_café [daily]-{run}']];
    }
}
