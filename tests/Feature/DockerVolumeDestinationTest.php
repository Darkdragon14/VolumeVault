<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\User;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\S3\S3ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DockerVolumeDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_volume_name_is_required(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from('/destinations/create')
            ->post('/destinations', $this->payload([]))
            ->assertSessionHasErrors('settings.volume_name');

        $this->assertSame(0, BackupDestination::count());
    }

    public function test_volume_name_with_a_slash_is_rejected(): void
    {
        // A slash would make Docker treat the `-v` source as a host bind mount.
        $this->actingAs(User::factory()->admin()->create())
            ->from('/destinations/create')
            ->post('/destinations', $this->payload(['volume_name' => '/etc']))
            ->assertSessionHasErrors('settings.volume_name');

        $this->assertSame(0, BackupDestination::count());
    }

    public function test_volume_name_with_a_colon_is_rejected(): void
    {
        // A colon would inject an extra `src:dst:opts` field into the mount spec.
        $this->actingAs(User::factory()->admin()->create())
            ->from('/destinations/create')
            ->post('/destinations', $this->payload(['volume_name' => 'backups:ro']))
            ->assertSessionHasErrors('settings.volume_name');

        $this->assertSame(0, BackupDestination::count());
    }

    public function test_path_prefix_with_parent_traversal_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from('/destinations/create')
            ->post('/destinations', $this->payload(['volume_name' => 'barril-backups', 'path_prefix' => '../escape']))
            ->assertSessionHasErrors('settings.path_prefix');

        $this->assertSame(0, BackupDestination::count());
    }

    public function test_valid_docker_volume_destination_is_stored_without_secrets(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from('/destinations/create')
            ->post('/destinations', [
                'name' => 'NAS backups',
                'provider' => BackupDestination::PROVIDER_DOCKER_VOLUME,
                'is_active' => true,
                'settings' => ['volume_name' => 'barril-backups', 'path_prefix' => 'volumevault'],
                // Junk credentials must be dropped: this provider has no secrets.
                'secrets' => ['access_key_id' => 'junk'],
            ])
            ->assertSessionDoesntHaveErrors();

        $destination = BackupDestination::sole();

        $this->assertSame(BackupDestination::PROVIDER_DOCKER_VOLUME, $destination->provider);
        $this->assertSame('barril-backups', $destination->setting('volume_name'));
        $this->assertSame('volumevault', $destination->setting('path_prefix'));
        $this->assertEqualsCanonicalizing(['volume_name', 'path_prefix'], array_keys($destination->settings));
        $this->assertNull($destination->secrets);
        $this->assertSame('barril-backups/volumevault', $destination->targetLabel());
        $this->assertSame('Docker volume', $destination->safeForFrontend()['provider_label']);
    }

    public function test_list_parses_helper_container_output_and_mounts_read_only(): void
    {
        $process = $this->fakeProcess();
        $process->listing = "1024|1700000000|/archive/volumevault-app_data-run-1.tar.gz\n2048|1700000100|/archive/volumevault-app_data-run-2.tar.gz";

        $objects = $this->storage($process)->listBackupObjects($this->destination());

        $this->assertCount(2, $objects);
        // Sorted newest first.
        $this->assertSame('volumevault-app_data-run-2.tar.gz', $objects[0]['key']);
        $this->assertSame(2048, $objects[0]['size']);
        $this->assertSame('volumevault-app_data-run-1.tar.gz', $objects[1]['key']);

        $this->assertCommandRecorded($process, ['docker', 'volume', 'inspect', 'barril-backups']);
        $this->assertSomeCommandContains($process, 'barril-backups:/archive:ro');
    }

    public function test_download_streams_the_archive_into_the_target_file(): void
    {
        $process = $this->fakeProcess();
        $target = tempnam(sys_get_temp_dir(), 'vv-dl-');

        $this->storage($process)->download($this->destination(), 'volumevault-app_data-run-2.tar.gz', $target);

        $this->assertSame('ARCHIVE-BYTES', file_get_contents($target));
        $this->assertSomeCommandContains($process, 'barril-backups:/archive:ro');
        $this->assertSomeCommandContains($process, '/archive/volumevault-app_data-run-2.tar.gz');

        @unlink($target);
    }

    public function test_download_rejects_a_traversal_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Docker volume object key.');

        $this->storage($this->fakeProcess())->download($this->destination(), '../../etc/passwd', tempnam(sys_get_temp_dir(), 'vv-dl-'));
    }

    public function test_upload_streams_the_file_into_the_volume(): void
    {
        $process = $this->fakeProcess();
        $source = tempnam(sys_get_temp_dir(), 'vv-up-');
        file_put_contents($source, 'payload');

        $key = $this->storage($process)->upload($this->destination(), $source, 'instance.vvsave', 'installation-saves');

        $this->assertSame('installation-saves/instance.vvsave', $key);
        // Writable mount (no :ro), interactive (-i) for the stdin stream.
        $this->assertSomeCommandContains($process, 'barril-backups:/archive');
        $this->assertSomeCommandContains($process, '-i');

        @unlink($source);
    }

    public function test_a_deleted_volume_is_reported_clearly_instead_of_silently_recreated(): void
    {
        // Docker would auto-create a missing named volume as an empty local
        // volume; the inspect guard turns that into a clear failure instead.
        $process = $this->fakeProcess();
        $process->runExit = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->storage($process)->test($this->destination());
    }

    private function destination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'NAS',
            'provider' => BackupDestination::PROVIDER_DOCKER_VOLUME,
            'bucket' => 'barril-backups',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['volume_name' => 'barril-backups'],
        ]);
    }

    private function storage(DockerProcess $process): DestinationStorage
    {
        return new DestinationStorage(app(S3ClientFactory::class), null, $process);
    }

    /**
     * A fake DockerProcess that records every command. `run` (used for the
     * `docker volume inspect` existence guard and for listings) succeeds by
     * default; set $runExit to simulate a missing volume. Downloads write
     * $downloadContent to the target file.
     */
    private function fakeProcess(): DockerProcess
    {
        return new class extends DockerProcess
        {
            /** @var array<int, array<int, string>> */
            public array $calls = [];

            public int $runExit = 0;

            public string $listing = '';

            public string $downloadContent = 'ARCHIVE-BYTES';

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->calls[] = $command;

                return new DockerProcessResult($command, $this->runExit, $this->listing, $this->runExit === 0 ? '' : 'Error: No such volume');
            }

            public function runWithInputFile(array $command, string $inputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->calls[] = $command;

                return new DockerProcessResult($command, 0, '', '');
            }

            public function runWithOutputFile(array $command, string $outputPath, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->calls[] = $command;
                file_put_contents($outputPath, $this->downloadContent);

                return new DockerProcessResult($command, 0, '', '');
            }
        };
    }

    /**
     * @param  object{calls: array<int, array<int, string>>}  $process
     */
    private function assertCommandRecorded(object $process, array $command): void
    {
        $this->assertContains($command, $process->calls, 'Expected docker command not recorded: '.implode(' ', $command));
    }

    /**
     * @param  object{calls: array<int, array<int, string>>}  $process
     */
    private function assertSomeCommandContains(object $process, string $argument): void
    {
        $found = collect($process->calls)->contains(fn (array $command): bool => in_array($argument, $command, true));

        $this->assertTrue($found, 'No recorded docker command contained the argument: '.$argument);
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, mixed>
     */
    private function payload(array $settings): array
    {
        return [
            'name' => 'NAS backups',
            'provider' => BackupDestination::PROVIDER_DOCKER_VOLUME,
            'is_active' => true,
            'settings' => $settings,
        ];
    }
}
