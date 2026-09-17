<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\User;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\S3\S3ClientFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DockerVolumeDestinationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $scriptDirectory = null;

    protected function tearDown(): void
    {
        if ($this->scriptDirectory !== null) {
            (new Filesystem)->deleteDirectory($this->scriptDirectory);
        }

        parent::tearDown();
    }

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
        $script = collect($process->calls)->flatten()
            ->first(fn ($arg): bool => is_string($arg) && str_contains($arg, 'awk -v prefix="$1/"'));

        $this->assertNotNull($script);
        $this->assertMatchesRegularExpression('/awk .*sort -t "\|" -k2,2nr .*head -n "\$2"/s', $script);
        $this->assertStringNotContainsString('accepted', $script);
        $this->assertStringNotContainsString('|| true', $script);
    }

    public function test_generated_listing_script_handles_missing_and_empty_directories(): void
    {
        $directory = $this->scriptFixture();

        foreach ([$directory.'/archives', $directory.'/missing/nested'] as $path) {
            $result = $this->executeListingScript($path);

            $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
            $this->assertSame('', $result->getOutput());
        }
    }

    public function test_generated_listing_script_rejects_non_directory_and_dangling_symlink(): void
    {
        $directory = $this->scriptFixture();
        file_put_contents($directory.'/file', 'not a directory');
        symlink($directory.'/missing', $directory.'/dangling');

        foreach (['file', 'file/nested', 'dangling', 'dangling/nested'] as $path) {
            $result = $this->executeListingScript($directory.'/'.$path);

            $this->assertNotSame(0, $result->getExitCode());
            $this->assertSame('', $result->getOutput());
        }
    }

    public function test_generated_listing_script_filters_sorts_and_caps_without_sigpipe(): void
    {
        $directory = $this->scriptFixture().'/archives';
        $names = [];

        for ($index = 0; $index < 1600; $index++) {
            $name = str_repeat('a', 120).sprintf('-%04d|backup:1.tar.gz', $index);
            file_put_contents($directory.'/'.$name, 'backup');
            touch($directory.'/'.$name, 1700000000 + $index);
            $names[] = $name;
        }

        foreach (["invalid\nname.tar.gz", "invalid\tname.tar.gz", 'C:drive.tar.gz', 'newer.txt'] as $name) {
            file_put_contents($directory.'/'.$name, 'invalid');
            touch($directory.'/'.$name, 1800000000);
        }

        $result = $this->executeListingScript($directory, 2);

        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $this->assertSame(
            "6|1700001599|$directory/{$names[1599]}\n6|1700001598|$directory/{$names[1598]}\n",
            $result->getOutput(),
        );

        $unbounded = $this->executeListingScript($directory, PHP_INT_MAX);

        $this->assertSame(0, $unbounded->getExitCode(), $unbounded->getErrorOutput());
        $this->assertCount(1603, explode("\n", trim($unbounded->getOutput())));
        $this->assertStringContainsString('/newer.txt', $unbounded->getOutput());
    }

    #[DataProvider('failingListingStages')]
    public function test_generated_listing_script_propagates_stage_failures(string $stage, int $limit): void
    {
        $directory = $this->scriptFixture();
        file_put_contents($directory.'/archives/backup.tar.gz', 'backup');
        $shim = $directory.'/bin/'.$stage;
        file_put_contents($shim, '#!/bin/sh'."\n".'PATH="$VV_TOOL_PATH"'."\nexport PATH\n".$stage.' "$@"'."\nprintf '%s\\n' 'injected $stage failure' >&2\nexit 42\n");
        chmod($shim, 0755);

        $result = $this->executeListingScript($directory.'/archives', $limit);

        $this->assertNotSame(0, $result->getExitCode());
        $this->assertStringContainsString('injected '.$stage.' failure', $result->getErrorOutput());
        if ($stage !== 'head') {
            $this->assertSame('', $result->getOutput());
        }
    }

    public static function failingListingStages(): array
    {
        return [
            'find capped' => ['find', 1],
            'stat capped' => ['stat', 1],
            'xargs capped' => ['xargs', 1],
            'awk capped' => ['awk', 1],
            'sort capped' => ['sort', 1],
            'head capped' => ['head', 1],
            'find unbounded' => ['find', PHP_INT_MAX],
            'stat unbounded' => ['stat', PHP_INT_MAX],
        ];
    }

    private function scriptFixture(): string
    {
        $this->scriptDirectory = sys_get_temp_dir().'/vv-list-script-'.bin2hex(random_bytes(8));
        mkdir($this->scriptDirectory);
        foreach (['archives', 'bin', 'spool'] as $directory) {
            mkdir($this->scriptDirectory.'/'.$directory);
        }

        return $this->scriptDirectory;
    }

    private function executeListingScript(string $path, int $limit = 1000): Process
    {
        $docker = $this->fakeProcess();
        (new ReflectionMethod(DestinationStorage::class, 'listDockerVolume'))
            ->invoke($this->storage($docker), $this->destination(), $limit);
        $command = collect($docker->calls)->first(fn (array $command): bool => in_array('-c', $command, true));
        $script = $command[array_search('-c', $command, true) + 1];
        $toolPath = getenv('PATH');
        $process = new Process(['sh', '-c', $script, 'sh', $path, (string) $limit], null, [
            'PATH' => $this->scriptDirectory.'/bin:'.$toolPath,
            'VV_TOOL_PATH' => $toolPath,
            'TMPDIR' => $this->scriptDirectory.'/spool',
        ]);
        $process->run();
        $this->assertSame([], (new Filesystem)->directories($this->scriptDirectory.'/spool'), 'Temporary spool must be cleaned up.');

        return $process;
    }

    public function test_list_handles_a_filename_that_contains_a_pipe_and_colon(): void
    {
        // Filename templates may render pipes and colons; listing
        // and restoring such an archive must work.
        $process = $this->fakeProcess();
        $process->listing = '512|1700000200|/archive/daily|backup:123.tar.gz';

        $objects = $this->storage($process)->listBackupObjects($this->destination());

        $this->assertCount(1, $objects);
        $this->assertSame('daily|backup:123.tar.gz', $objects[0]['key']);
    }

    public function test_list_filters_every_key_that_download_validation_rejects(): void
    {
        $process = $this->fakeProcess();
        $tooLongSegment = str_repeat('a', 256).'.tar.gz';
        $process->listing = implode("\n", [
            '1|1700000000|/archive/valid.tar.gz',
            '1|1700000000|/archive/./dot.tar.gz',
            '1|1700000000|/archive/../dotdot.tar.gz',
            '1|1700000000|/archive/double//separator.tar.gz',
            '1|1700000000|/archive/C:drive.tar.gz',
            "1|1700000000|/archive/control\tname.tar.gz",
            '1|1700000000|/archive/'.$tooLongSegment,
            '1|1700000000|/archive-sibling/wrong.tar.gz',
        ]);

        $objects = $this->storage($process)->listBackupObjects($this->destination());

        $this->assertSame(['valid.tar.gz'], collect($objects)->pluck('key')->all());
    }

    public function test_test_uses_a_noclobber_unique_write_probe(): void
    {
        // The write test must never truncate or delete a pre-existing file.
        $process = $this->fakeProcess();

        $this->storage($process)->test($this->destination());

        $script = collect($process->calls)->flatten()
            ->first(fn ($arg): bool => is_string($arg) && str_contains($arg, 'vv-write-test'));

        $this->assertNotNull($script);
        $this->assertStringContainsString('set -C', $script);
        $this->assertStringNotContainsString('.volumevault-write-test"', $script);
    }

    public function test_storage_usage_is_aggregated_inside_the_helper_container(): void
    {
        // The helper returns a single "bytes|count" line, so no full listing is
        // streamed back to PHP even for a volume with very many files.
        $process = $this->fakeProcess();
        $process->listing = '8192|5';

        $usage = $this->storage($process)->storageUsage($this->destination());

        $this->assertSame(8192, $usage['used_bytes']);
        $this->assertSame(5, $usage['object_count']);
        $this->assertTrue(
            collect($process->calls)->flatten()->contains(fn ($arg): bool => is_string($arg) && str_contains($arg, 'awk')),
            'usage should be aggregated in-container with awk, not by listing every object',
        );
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

    public function test_download_key_validation_is_non_normalizing_and_enforces_path_limits(): void
    {
        $invalidKeys = [
            '',
            '/absolute.tar.gz',
            '\\windows-rooted.tar.gz',
            'C:drive-qualified.tar.gz',
            './dot.tar.gz',
            'nested/../dotdot.tar.gz',
            'nested//empty.tar.gz',
            "control\tname.tar.gz",
            str_repeat('a', 256).'.tar.gz',
            implode('/', array_fill(0, 6, str_repeat('a', 200))).'.tar.gz',
        ];

        foreach ($invalidKeys as $key) {
            try {
                $this->storage($this->fakeProcess())->download($this->destination(), $key, tempnam(sys_get_temp_dir(), 'vv-dl-'));
                $this->fail('Expected Docker volume key to be rejected: '.var_export($key, true));
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid Docker volume object key.', $exception->getMessage());
            }
        }
    }

    public function test_download_accepts_a_colon_in_the_key(): void
    {
        // A colon is a valid filename character (passed as an argv path, never in
        // a mount spec), so an archive named daily:123.tar.gz must be restorable.
        $process = $this->fakeProcess();
        $target = tempnam(sys_get_temp_dir(), 'vv-dl-');

        $this->storage($process)->download($this->destination(), 'daily:123.tar.gz', $target);

        $this->assertSame('ARCHIVE-BYTES', file_get_contents($target));
        $this->assertSomeCommandContains($process, '/archive/daily:123.tar.gz');

        @unlink($target);
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
