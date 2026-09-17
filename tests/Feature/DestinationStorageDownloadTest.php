<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\SecureLocalArchiveReader;
use App\Services\S3\S3ClientFactory;
use App\Services\Security\OutboundHostGuard;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use phpseclib3\Net\SFTP;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DestinationStorageDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['volumevault.host_path_allowlist' => [sys_get_temp_dir()]]);
    }

    public function test_local_download_copies_the_backup_file(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-local-'.uniqid();
        File::ensureDirectoryExists($base);
        File::put($base.'/backup.tar.gz', 'archive-bytes');

        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $base],
        ]);

        $target = $base.'/restored.tar.gz';
        $heartbeats = 0;
        app(DestinationStorage::class)->download($destination, 'backup.tar.gz', $target, function () use (&$heartbeats): void {
            $heartbeats++;
        });

        $this->assertFileExists($target);
        $this->assertSame('archive-bytes', File::get($target));
        $this->assertGreaterThanOrEqual(1, $heartbeats);

        File::deleteDirectory($base);
    }

    public function test_local_download_reports_progress_while_the_reader_is_silent(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-silent-'.uniqid();
        File::ensureDirectoryExists($base);
        $reader = new class extends SecureLocalArchiveReader
        {
            public function __construct()
            {
                parent::__construct(PHP_BINARY);
            }

            protected function createProcess(array $command): Process
            {
                return new Process([PHP_BINARY, '-r', 'usleep(2200000); echo "archive-bytes";']);
            }

            protected function progressIntervalSeconds(): int
            {
                return 1;
            }
        };
        $heartbeats = 0;
        $target = $base.'/restored.tar.gz';

        try {
            $reader->copy($base, 'backup.tar.gz', $target, ['dev' => 1, 'ino' => 1], function () use (&$heartbeats): void {
                $heartbeats++;
            });

            $this->assertSame('archive-bytes', File::get($target));
            $this->assertGreaterThanOrEqual(3, $heartbeats);
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_download_throws_when_the_source_is_missing(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-missing-'.uniqid();
        File::ensureDirectoryExists($base);

        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $base],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local backup file does not exist.');

        try {
            app(DestinationStorage::class)->download($destination, 'nope.tar.gz', $base.'/out.tar.gz');
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_download_rejects_the_same_source_and_target_without_deleting_the_source(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-same-path-'.uniqid();
        File::ensureDirectoryExists($base);
        $source = $base.'/backup.tar.gz';
        File::put($source, 'archive-bytes');
        $destination = $this->localDestination($base);

        try {
            app(DestinationStorage::class)->download($destination, 'backup.tar.gz', $source);
            $this->fail('Expected the same source and target to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local backup source and target must be different files.', $exception->getMessage());
            $this->assertSame('archive-bytes', File::get($source));
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_download_rejects_a_hardlink_target_without_changing_either_name(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Hard-link behavior is platform-specific on Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-download-hardlink-'.uniqid();
        File::ensureDirectoryExists($base);
        $source = $base.'/backup.tar.gz';
        $target = $base.'/restored.tar.gz';
        File::put($source, 'archive-bytes');

        if (! link($source, $target)) {
            File::deleteDirectory($base);
            $this->markTestSkipped('The test filesystem does not support hard links.');
        }

        try {
            app(DestinationStorage::class)->download($this->localDestination($base), 'backup.tar.gz', $target);
            $this->fail('Expected a hardlink target to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local backup source and target must be different files.', $exception->getMessage());
            $this->assertSame('archive-bytes', File::get($source));
            $this->assertSame('archive-bytes', File::get($target));
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_download_rejects_a_hardlinked_source_that_can_escape_the_archive_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Hard-link behavior is platform-specific on Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-download-source-hardlink-'.uniqid();
        $archiveRoot = $base.'/archives';
        $outsideSource = $base.'/outside.tar.gz';
        File::ensureDirectoryExists($archiveRoot);
        File::put($outsideSource, 'outside-bytes');

        if (! link($outsideSource, $archiveRoot.'/backup.tar.gz')) {
            File::deleteDirectory($base);
            $this->markTestSkipped('The test filesystem does not support hard links.');
        }

        try {
            app(DestinationStorage::class)->download($this->localDestination($archiveRoot), 'backup.tar.gz', $base.'/restored.tar.gz');
            $this->fail('Expected a hardlinked source to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local backup file must not be linked outside the archive path.', $exception->getMessage());
            $this->assertSame('outside-bytes', File::get($outsideSource));
            $this->assertFileDoesNotExist($base.'/restored.tar.gz');
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_native_reader_rejects_a_fifo_without_waiting_for_a_writer(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Descriptor-relative local archive reads require Linux.');
        }

        $base = sys_get_temp_dir().'/volumevault-download-fifo-'.uniqid();
        File::ensureDirectoryExists($base);
        (new Process(['mkfifo', $base.'/backup.tar.gz']))->mustRun();
        $rootStat = stat($base);
        $reader = new Process([
            '/usr/local/bin/volumevault-local-archive-reader',
            $base,
            'backup.tar.gz',
            $base.'/restored.tar.gz',
            (string) $rootStat['dev'],
            (string) $rootStat['ino'],
        ]);
        $reader->setTimeout(2);

        try {
            $this->assertNotSame(0, $reader->run());
            $this->assertStringContainsString('must be a regular file', $reader->getErrorOutput());
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_failed_local_copy_preserves_an_existing_target_and_removes_staging_files(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-partial-'.uniqid();
        File::ensureDirectoryExists($base);
        File::put($base.'/backup.tar.gz', 'archive-bytes');
        $target = $base.'/restored.tar.gz';
        File::put($target, 'existing-target');
        $reader = new class extends SecureLocalArchiveReader
        {
            protected function writeChunk(mixed $targetHandle, string $buffer): void
            {
                fwrite($targetHandle, 'partial');

                throw new RuntimeException('Unable to stream the local backup file.');
            }
        };
        $storage = new DestinationStorage(app(S3ClientFactory::class), null, null, $reader);

        try {
            $storage->download($this->localDestination($base), 'backup.tar.gz', $target);
            $this->fail('Expected local streaming to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to stream the local backup file.', $exception->getMessage());
            $this->assertSame('existing-target', File::get($target));
            $this->assertSame([], glob($base.'/.restored.tar.gz.*.tmp'));
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_failed_local_copy_stops_the_reader_process(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-process-stop-'.uniqid();
        File::ensureDirectoryExists($base);
        File::put($base.'/backup.tar.gz', 'archive-bytes');
        $process = new Process([PHP_BINARY, '-r', 'echo "archive-bytes"; sleep(10);']);
        $reader = new class($process) extends SecureLocalArchiveReader
        {
            public function __construct(private readonly Process $process)
            {
                parent::__construct('/bin/true');
            }

            protected function createProcess(array $command): Process
            {
                return $this->process;
            }

            protected function writeChunk(mixed $targetHandle, string $buffer): void
            {
                throw new RuntimeException('Unable to stream the local backup file.');
            }
        };
        $storage = new DestinationStorage(app(S3ClientFactory::class), null, null, $reader);

        try {
            $storage->download($this->localDestination($base), 'backup.tar.gz', $base.'/restored.tar.gz');
            $this->fail('Expected local streaming to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to stream the local backup file.', $exception->getMessage());
            $this->assertFalse($process->isRunning());
            $this->assertSame(3600.0, $process->getTimeout());
            $this->assertSame([], glob($base.'/.restored.tar.gz.*.tmp'));
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_download_rejects_a_root_replaced_by_a_symlink_after_it_is_identified(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Descriptor-relative local archive reads require Linux.');
        }

        $base = sys_get_temp_dir().'/volumevault-download-root-'.uniqid();
        $original = $base.'-original';
        $attacker = $base.'-attacker';
        File::ensureDirectoryExists($base);
        File::ensureDirectoryExists($attacker);
        File::put($base.'/backup.tar.gz', 'trusted-archive');
        File::put($attacker.'/backup.tar.gz', 'attacker-archive');
        $reader = new class($attacker, $original) extends SecureLocalArchiveReader
        {
            public function __construct(
                private readonly string $attacker,
                private readonly string $original,
            ) {
                parent::__construct();
            }

            protected function createProcess(array $command): Process
            {
                rename($command[1], $this->original);
                symlink($this->attacker, $command[1]);

                return parent::createProcess($command);
            }
        };
        $storage = new DestinationStorage(app(S3ClientFactory::class), null, null, $reader);
        $target = $base.'-restored.tar.gz';

        try {
            $storage->download($this->localDestination($base), 'backup.tar.gz', $target);
            $this->fail('Expected the replaced archive root to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local archive path changed while it was being opened.', $exception->getMessage());
            $this->assertFileDoesNotExist($target);
            $this->assertSame('trusted-archive', File::get($original.'/backup.tar.gz'));
        } finally {
            @unlink($base);
            File::deleteDirectory($original);
            File::deleteDirectory($attacker);
            File::delete($target);
        }
    }

    public function test_local_download_copies_a_nested_backup_file(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-nested-'.uniqid();
        File::ensureDirectoryExists($base.'/daily/app');
        File::put($base.'/daily/app/backup.tar.gz', 'nested-archive');
        $destination = $this->localDestination($base);
        $target = $base.'/restored.tar.gz';

        app(DestinationStorage::class)->download($destination, 'daily/app/backup.tar.gz', $target);

        $this->assertSame('nested-archive', File::get($target));

        File::deleteDirectory($base);
    }

    public function test_local_upload_rejects_a_symlinked_target_directory_outside_the_archive_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link behavior is platform-specific on Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-upload-symlink-'.uniqid();
        $archiveRoot = $base.'/archives';
        $outside = $base.'/outside';
        $source = $base.'/source.tar.gz';
        File::ensureDirectoryExists($archiveRoot);
        File::ensureDirectoryExists($outside);
        File::put($source, 'archive');
        symlink($outside, $archiveRoot.'/linked');
        config(['volumevault.host_path_allowlist' => [$archiveRoot]]);

        try {
            $this->expectException(RuntimeException::class);
            app(DestinationStorage::class)->upload(
                $this->localDestination($archiveRoot),
                $source,
                'backup.tar.gz',
                'linked',
            );
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_upload_rejects_a_root_replaced_by_a_symlink_after_it_is_identified(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Descriptor-relative local archive writes require Linux.');
        }

        $base = sys_get_temp_dir().'/volumevault-upload-root-'.uniqid();
        $original = $base.'-original';
        $attacker = $base.'-attacker';
        $source = $base.'-source.tar.gz';
        File::ensureDirectoryExists($base);
        File::ensureDirectoryExists($attacker);
        File::put($source, 'trusted-archive');
        $reader = new class($attacker, $original) extends SecureLocalArchiveReader
        {
            public function __construct(
                private readonly string $attacker,
                private readonly string $original,
            ) {
                parent::__construct();
            }

            protected function createProcess(array $command): Process
            {
                rename($command[2], $this->original);
                symlink($this->attacker, $command[2]);

                return parent::createProcess($command);
            }
        };
        $storage = new DestinationStorage(app(S3ClientFactory::class), null, null, $reader);

        try {
            $storage->upload($this->localDestination($base), $source, 'backup.tar.gz');
            $this->fail('Expected the replaced archive root to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local archive path changed while it was being opened.', $exception->getMessage());
            $this->assertFileDoesNotExist($attacker.'/backup.tar.gz');
            $this->assertFileDoesNotExist($original.'/backup.tar.gz');
        } finally {
            @unlink($base);
            File::deleteDirectory($original);
            File::deleteDirectory($attacker);
            File::delete($source);
        }
    }

    public function test_local_upload_rejects_a_symlinked_ancestor_even_when_it_resolves_to_the_original_root_inode(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Descriptor-relative local archive writes require Linux.');
        }

        $base = sys_get_temp_dir().'/volumevault-upload-ancestor-'.uniqid();
        $ancestor = $base.'/allowed';
        $movedAncestor = $base.'/moved';
        $archiveRoot = $ancestor.'/archives';
        $source = $base.'/source.tar.gz';
        File::ensureDirectoryExists($archiveRoot);
        File::put($source, 'trusted-archive');
        $reader = new class($ancestor, $movedAncestor) extends SecureLocalArchiveReader
        {
            public function __construct(
                private readonly string $ancestor,
                private readonly string $movedAncestor,
            ) {
                parent::__construct();
            }

            protected function createProcess(array $command): Process
            {
                rename($this->ancestor, $this->movedAncestor);
                symlink($this->movedAncestor, $this->ancestor);

                return parent::createProcess($command);
            }
        };
        $storage = new DestinationStorage(app(S3ClientFactory::class), null, null, $reader);

        try {
            $storage->upload($this->localDestination($archiveRoot), $source, 'backup.tar.gz');
            $this->fail('Expected the symlinked archive ancestor to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local archive path changed while it was being opened.', $exception->getMessage());
            $this->assertFileDoesNotExist($movedAncestor.'/archives/backup.tar.gz');
        } finally {
            @unlink($ancestor);
            File::deleteDirectory($movedAncestor);
            File::delete($source);
            File::deleteDirectory($base);
        }
    }

    public function test_local_download_rejects_absolute_and_traversal_keys(): void
    {
        $base = sys_get_temp_dir().'/volumevault-download-invalid-'.uniqid();
        File::ensureDirectoryExists($base);
        $destination = $this->localDestination($base);

        foreach (['/etc/passwd', '../outside.tar.gz', 'nested/../../outside.tar.gz', 'C:drive.tar.gz', 'C:\\drive.tar.gz', './backup.tar.gz', "bad\0key.tar.gz"] as $key) {
            try {
                app(DestinationStorage::class)->download($destination, $key, $base.'/out.tar.gz');
                $this->fail('Expected local key to be rejected: '.$key);
            } catch (RuntimeException $exception) {
                $this->assertSame('Local backup key must stay within the archive path.', $exception->getMessage());
            }
        }

        File::deleteDirectory($base);
    }

    public function test_local_download_rejects_a_symlink_that_escapes_the_archive_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link behavior is platform-specific on Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-download-symlink-'.uniqid();
        $outside = sys_get_temp_dir().'/volumevault-download-outside-'.uniqid().'.tar.gz';
        File::ensureDirectoryExists($base);
        File::put($outside, 'outside-archive');
        symlink($outside, $base.'/backup.tar.gz');
        $destination = $this->localDestination($base);

        try {
            app(DestinationStorage::class)->download($destination, 'backup.tar.gz', $base.'/out.tar.gz');
            $this->fail('Expected an escaping symlink to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local backup file must be a regular file within the archive path.', $exception->getMessage());
        } finally {
            File::deleteDirectory($base);
            File::delete($outside);
        }
    }

    public function test_local_download_rejects_a_symlink_even_when_it_points_inside_the_archive_root(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link behavior is platform-specific on Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-download-inner-symlink-'.uniqid();
        File::ensureDirectoryExists($base);
        File::put($base.'/real.tar.gz', 'archive');
        symlink($base.'/real.tar.gz', $base.'/linked.tar.gz');
        $destination = $this->localDestination($base);

        try {
            app(DestinationStorage::class)->download($destination, 'linked.tar.gz', $base.'/out.tar.gz');
            $this->fail('Expected a symlink source to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Local backup file must be a regular file within the archive path.', $exception->getMessage());
            $this->assertFileDoesNotExist($base.'/out.tar.gz');
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_local_availability_rejects_an_intermediate_symlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic-link behavior is platform-specific on Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-availability-symlink-'.uniqid();
        $outside = sys_get_temp_dir().'/volumevault-availability-outside-'.uniqid();
        File::ensureDirectoryExists($base);
        File::ensureDirectoryExists($outside);
        File::put($outside.'/backup.tar.gz', 'archive');
        symlink($outside, $base.'/linked-directory');

        try {
            $this->assertFalse(app(DestinationStorage::class)->hasBackupObject(
                $this->localDestination($base),
                'linked-directory/backup.tar.gz',
            ));
        } finally {
            File::deleteDirectory($base);
            File::deleteDirectory($outside);
        }
    }

    public function test_filename_lookup_does_not_accept_a_nested_suffix_match(): void
    {
        $base = sys_get_temp_dir().'/volumevault-exact-filename-'.uniqid();
        File::ensureDirectoryExists($base.'/nested');
        File::put($base.'/backup.tar.gz', 'expected');
        File::put($base.'/nested/backup.tar.gz', 'unrelated');
        $destination = $this->localDestination($base);

        try {
            $this->assertSame(
                'backup.tar.gz',
                app(DestinationStorage::class)->findBackupObjectByFilename($destination, 'backup.tar.gz')['key'],
            );

            File::delete($base.'/backup.tar.gz');
            $this->assertNull(app(DestinationStorage::class)->findBackupObjectByFilename($destination, 'backup.tar.gz'));
        } finally {
            File::deleteDirectory($base);
        }
    }

    public function test_s3_download_requests_the_key_and_saves_to_the_target_path(): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        $target = sys_get_temp_dir().'/volumevault-download-s3-'.uniqid().'.tar.gz';

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('getObject')
            ->once()
            ->with([
                'Bucket' => 'backups',
                'Key' => 'path/to/backup.tar.gz',
                'SaveAs' => $target,
            ]);

        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->once()->with($destination)->andReturn($client);

        (new DestinationStorage($factory))->download($destination, 'path/to/backup.tar.gz', $target);
    }

    public function test_s3_download_forwards_the_progress_callback(): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);
        $heartbeats = 0;
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('getObject')->once()->with(Mockery::on(
            fn (array $options): bool => is_callable($options['@http']['progress'] ?? null),
        ));
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->once()->with($destination)->andReturn($client);

        (new DestinationStorage($factory))->download(
            $destination,
            'backup.tar.gz',
            '/tmp/backup.tar.gz',
            function () use (&$heartbeats): void {
                $heartbeats++;
            },
        );

        $this->assertSame(1, $heartbeats);
    }

    public function test_dropbox_download_uses_the_opaque_file_id_without_normalizing_it(): void
    {
        $key = 'id:opaque-dropbox-file-id';
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://content.dropboxapi.com/2/files/download' => Http::response('archive-bytes'),
        ]);
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/backups'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);

        $heartbeats = 0;
        app(DestinationStorage::class)->download($destination, $key, sys_get_temp_dir().'/dropbox.tar.gz', function () use (&$heartbeats): void {
            $heartbeats++;
        });

        $this->assertSame(1, $heartbeats);
        Http::assertSent(function ($request) use ($key): bool {
            if ($request->url() !== 'https://content.dropboxapi.com/2/files/download') {
                return false;
            }

            $arguments = json_decode($request->header('Dropbox-API-Arg')[0] ?? '', true);

            return ($arguments['path'] ?? null) === $key;
        });
    }

    public function test_dropbox_upload_returns_the_opaque_file_id_instead_of_the_mutable_path(): void
    {
        $source = sys_get_temp_dir().'/dropbox-upload-'.uniqid().'.tar.gz';
        File::put($source, 'archive-bytes');
        Http::fake([
            'https://api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://content.dropboxapi.com/2/files/upload' => Http::response([
                'id' => 'id:opaque-dropbox-file-id',
                'path_display' => '/backups/renamed-backup.tar.gz',
            ]),
        ]);
        $destination = BackupDestination::create([
            'name' => 'Dropbox',
            'provider' => BackupDestination::PROVIDER_DROPBOX,
            'bucket' => 'dropbox',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['remote_path' => '/backups'],
            'secrets' => ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'],
        ]);

        try {
            $key = app(DestinationStorage::class)->upload($destination, $source, 'backup.tar.gz');

            $this->assertSame('id:opaque-dropbox-file-id', $key);
        } finally {
            File::delete($source);
        }
    }

    public function test_google_drive_guards_the_credentials_token_uri_and_configured_api_endpoint(): void
    {
        Http::fake([
            'https://credentials-token.example.test/oauth/token' => Http::response(['access_token' => 'token']),
            'https://drive.example.test/custom/drive/v3/files*' => Http::response(['files' => [[
                'id' => 'file-id',
                'name' => 'backup.tar.gz',
                'size' => '123',
                'modifiedTime' => '2026-09-02T12:00:00Z',
            ]]]),
        ]);
        $guard = Mockery::mock(OutboundHostGuard::class);
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://credentials-token.example.test/oauth/token');
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://drive.example.test/custom/drive/v3');
        $destination = $this->googleDriveDestination([
            'endpoint' => 'https://drive.example.test/custom/drive/v3',
        ], 'https://credentials-token.example.test/oauth/token');

        $objects = (new DestinationStorage(app(S3ClientFactory::class), $guard))->listBackupObjects($destination);

        $this->assertSame('gdrive:file-id', $objects[0]['key']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://drive.example.test/custom/drive/v3/files?'));
    }

    public function test_google_drive_upload_guards_and_uses_the_derived_configured_upload_endpoint(): void
    {
        Http::fake([
            'https://settings-token.example.test/token' => Http::response(['access_token' => 'token']),
            'https://drive.example.test/upload/custom/drive/v3/files*' => Http::response(['id' => 'uploaded-id']),
        ]);
        $guard = Mockery::mock(OutboundHostGuard::class);
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://settings-token.example.test/token');
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://drive.example.test/custom/drive/v3');
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://drive.example.test/upload/custom/drive/v3');
        $destination = $this->googleDriveDestination([
            'endpoint' => 'https://drive.example.test/custom/drive/v3',
            'token_url' => 'https://settings-token.example.test/token',
        ], 'https://ignored-credentials-token.example.test/token');
        $source = sys_get_temp_dir().'/volumevault-google-upload-'.uniqid().'.tar.gz';
        File::put($source, 'archive');

        try {
            $key = (new DestinationStorage(app(S3ClientFactory::class), $guard))->upload($destination, $source, 'backup.tar.gz');

            $this->assertSame('gdrive:uploaded-id', $key);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://drive.example.test/upload/custom/drive/v3/files?uploadType=multipart&supportsAllDrives=true');
            Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://ignored-credentials-token.example.test/token');
        } finally {
            File::delete($source);
        }
    }

    public function test_google_drive_download_accepts_a_progress_callback(): void
    {
        Http::fake([
            'https://credentials-token.example.test/oauth/token' => Http::response(['access_token' => 'token']),
            'https://drive.example.test/custom/drive/v3/files/file-id*' => Http::response('archive'),
        ]);
        $guard = Mockery::mock(OutboundHostGuard::class);
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://credentials-token.example.test/oauth/token');
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://drive.example.test/custom/drive/v3');
        $destination = $this->googleDriveDestination([
            'endpoint' => 'https://drive.example.test/custom/drive/v3',
        ], 'https://credentials-token.example.test/oauth/token');
        $heartbeats = 0;

        (new DestinationStorage(app(S3ClientFactory::class), $guard))->download(
            $destination,
            'gdrive:file-id',
            '/tmp/google-drive.tar.gz',
            function () use (&$heartbeats): void {
                $heartbeats++;
            },
        );

        $this->assertSame(1, $heartbeats);
    }

    public function test_google_drive_opaque_key_is_checked_by_exact_file_metadata(): void
    {
        Http::fake([
            'https://credentials-token.example.test/oauth/token' => Http::response(['access_token' => 'token']),
            'https://drive.example.test/custom/drive/v3/files/file-id*' => Http::response([
                'id' => 'file-id',
                'parents' => ['folder-id'],
                'trashed' => false,
                'mimeType' => 'application/gzip',
            ]),
        ]);
        $guard = Mockery::mock(OutboundHostGuard::class);
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://credentials-token.example.test/oauth/token');
        $guard->shouldReceive('assertUrlAllowed')->once()->with('https://drive.example.test/custom/drive/v3');
        $destination = $this->googleDriveDestination([
            'endpoint' => 'https://drive.example.test/custom/drive/v3',
        ], 'https://credentials-token.example.test/oauth/token');

        $exists = (new DestinationStorage(app(S3ClientFactory::class), $guard))
            ->hasBackupObject($destination, 'gdrive:file-id');

        $this->assertTrue($exists);
    }

    public function test_azure_listed_blob_key_round_trips_to_download_url_and_shared_key_signature_unchanged(): void
    {
        $key = '/daily/../literal?#%2F.tar.gz';
        $accountKey = base64_encode('azure-secret');
        $destination = BackupDestination::create([
            'name' => 'Azure',
            'provider' => BackupDestination::PROVIDER_AZURE_BLOB,
            'bucket' => 'azure',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => [
                'account_name' => 'account',
                'container' => 'backups',
                'endpoint' => 'https://account.blob.example.test',
            ],
            'secrets' => ['account_key' => $accountKey],
        ]);
        Http::fake(function (Request $request) use ($key, $accountKey) {
            if (str_contains($request->url(), 'comp=list')) {
                return Http::response($this->azureListing($key));
            }

            $date = $request->header('x-ms-date')[0];
            $canonicalHeaders = 'x-ms-date:'.$date."\n".'x-ms-version:2021-12-02'."\n";
            $stringToSign = implode("\n", ['GET', '', '', '', '', '', '', '', '', '', '', ''])
                ."\n".$canonicalHeaders.'/account/backups/'.$key;
            $expectedAuthorization = 'SharedKey account:'.base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($accountKey), true));

            $this->assertSame('https://account.blob.example.test/backups//daily/%2E%2E/literal%3F%23%252F.tar.gz', $request->url());
            $this->assertSame($expectedAuthorization, $request->header('Authorization')[0]);

            return Http::response('archive');
        });

        $objects = app(DestinationStorage::class)->listBackupObjects($destination);
        $heartbeats = 0;
        app(DestinationStorage::class)->download($destination, $objects[0]['key'], sys_get_temp_dir().'/azure-special.tar.gz', function () use (&$heartbeats): void {
            $heartbeats++;
        });

        $this->assertSame($key, $objects[0]['key']);
        $this->assertSame(1, $heartbeats);
        Http::assertSentCount(2);
    }

    public function test_sftp_download_joins_the_remote_path_and_pulls_the_file(): void
    {
        $destination = $this->sftpDestination();
        $target = sys_get_temp_dir().'/volumevault-download-sftp-'.uniqid().'.tar.gz';

        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups/backup.tar.gz')->andReturn(['type' => 1]);
        $sftp->shouldReceive('get')
            ->once()
            ->with('/srv/backups/backup.tar.gz', $target)
            ->andReturnTrue();
        $sftp->shouldReceive('disconnect')->once();

        $this->storageWithSftp($sftp)->download($destination, 'backup.tar.gz', $target);
    }

    public function test_sftp_download_throws_when_the_transfer_fails(): void
    {
        $destination = $this->sftpDestination();

        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups/backup.tar.gz')->andReturn(['type' => 1]);
        $sftp->shouldReceive('get')->once()->andReturnFalse();
        $sftp->shouldReceive('disconnect')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to download file over SFTP.');

        $this->storageWithSftp($sftp)->download($destination, 'backup.tar.gz', '/tmp/whatever.tar.gz');
    }

    public function test_sftp_download_forwards_the_progress_callback(): void
    {
        $destination = $this->sftpDestination();
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups/backup.tar.gz')->andReturn(['type' => 1]);
        $sftp->shouldReceive('get')
            ->once()
            ->with('/srv/backups/backup.tar.gz', '/tmp/whatever.tar.gz', 0, -1, Mockery::on(is_callable(...)))
            ->andReturnTrue();
        $sftp->shouldReceive('disconnect')->once();
        $heartbeats = 0;

        $this->storageWithSftp($sftp)->download(
            $destination,
            'backup.tar.gz',
            '/tmp/whatever.tar.gz',
            function () use (&$heartbeats): void {
                $heartbeats++;
            },
        );

        $this->assertSame(1, $heartbeats);
    }

    public function test_sftp_download_revalidates_the_path_and_rejects_a_symlink(): void
    {
        $destination = $this->sftpDestination();
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups/nested')->andReturn(['type' => 3]);
        $sftp->shouldNotReceive('get');
        $sftp->shouldReceive('disconnect')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SFTP backup path must contain only regular directories and a regular file.');

        $this->storageWithSftp($sftp)->download($destination, 'nested/backup.tar.gz', '/tmp/whatever.tar.gz');
    }

    public function test_sftp_listing_excludes_symlinks_and_special_files(): void
    {
        $destination = $this->sftpDestination();
        $entries = [];

        for ($index = 0; $index < 1000; $index++) {
            $entries['invalid\\'.$index.'.tar.gz'] = ['type' => 1, 'size' => 100];
        }

        $entries['linked.tar.gz'] = ['type' => 3, 'size' => 100];
        $entries['pipe.tar.gz'] = ['type' => 4, 'size' => 0];
        $entries['backup.tar.gz'] = ['type' => 1, 'size' => 100];
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('rawlist')->once()->with('/srv/backups')->andReturn($entries);
        $sftp->shouldReceive('disconnect')->once();

        $objects = $this->storageWithSftp($sftp)->listBackupObjects($destination);

        $this->assertSame(['backup.tar.gz'], collect($objects)->pluck('key')->all());
    }

    public function test_sftp_listing_rejects_a_root_that_is_not_a_regular_directory(): void
    {
        $destination = $this->sftpDestination();
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 3]);
        $sftp->shouldNotReceive('rawlist');
        $sftp->shouldReceive('disconnect')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SFTP backup root must be a regular directory.');

        $this->storageWithSftp($sftp)->listBackupObjects($destination);
    }

    public function test_sftp_availability_requires_regular_directories_and_a_regular_target(): void
    {
        $destination = $this->sftpDestination();
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups/nested')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups/nested/backup.tar.gz')->andReturn(['type' => 1]);
        $sftp->shouldReceive('disconnect')->once();

        $this->assertTrue($this->storageWithSftp($sftp)->hasBackupObject($destination, 'nested/backup.tar.gz'));
    }

    public function test_sftp_availability_rejects_symlinks_and_special_targets(): void
    {
        $destination = $this->sftpDestination();
        $symlink = Mockery::mock(SFTP::class);
        $symlink->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $symlink->shouldReceive('lstat')->once()->with('/srv/backups/linked')->andReturn(['type' => 3]);
        $symlink->shouldReceive('disconnect')->once();

        $this->assertFalse($this->storageWithSftp($symlink)->hasBackupObject($destination, 'linked/backup.tar.gz'));

        $special = Mockery::mock(SFTP::class);
        $special->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $special->shouldReceive('lstat')->once()->with('/srv/backups/archive.tar.gz')->andReturn(['type' => 4]);
        $special->shouldReceive('disconnect')->once();

        $this->assertFalse($this->storageWithSftp($special)->hasBackupObject($destination, 'archive.tar.gz'));
    }

    public function test_webdav_download_targets_the_remote_path_and_raises_on_failure(): void
    {
        Http::fake([
            '*' => Http::response('not found', 404),
        ]);

        $destination = BackupDestination::create([
            'name' => 'WebDAV',
            'provider' => BackupDestination::PROVIDER_WEBDAV,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['url' => 'https://dav.example.com/remote.php/dav', 'path' => 'backups'],
            'secrets' => ['username' => 'user', 'password' => 'pass'],
        ]);

        try {
            app(DestinationStorage::class)->download($destination, 'backup.tar.gz', sys_get_temp_dir().'/wd.tar.gz', static function (): void {});
            $this->fail('A failed WebDAV response should raise a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('WebDAV request failed', $exception->getMessage());
        }

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://dav.example.com/remote.php/dav/backups/backup.tar.gz');
    }

    public function test_webdav_listing_and_download_round_trip_literal_special_characters(): void
    {
        $key = 'daily/a+b #? 100% ü.tar.gz';
        $encodedKey = 'daily/a%2Bb%20%23%3F%20100%25%20%C3%BC.tar.gz';
        Http::fake([
            'https://dav.example.com/remote.php/dav/old%2520backups' => Http::response($this->webDavListing([
                '/remote.php/dav/old%2520backups/'.$encodedKey,
            ])),
            'https://dav.example.com/remote.php/dav/old%2520backups/'.$encodedKey => Http::response('archive'),
        ]);
        $destination = $this->webDavDestination('old%20backups');

        $objects = app(DestinationStorage::class)->listBackupObjects($destination);
        app(DestinationStorage::class)->download($destination, $objects[0]['key'], sys_get_temp_dir().'/wd-special.tar.gz');

        $this->assertSame($key, $objects[0]['key']);
        Http::assertSent(fn ($request): bool => $request->method() === 'PROPFIND'
            && $request->url() === 'https://dav.example.com/remote.php/dav/old%2520backups');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://dav.example.com/remote.php/dav/old%2520backups/'.$encodedKey);
    }

    public function test_webdav_listing_only_accepts_unambiguous_entries_below_the_exact_base_path(): void
    {
        Http::fake([
            '*' => Http::response($this->webDavListing([
                '/remote.php/dav/backups/good+literal.tar.gz',
                '/remote.php/dav/backups-sibling/wrong.tar.gz',
                '/remote.php/dav/backups/nested%2Fseparator.tar.gz',
                '/remote.php/dav/backups/nested%5Cseparator.tar.gz',
                '/remote.php/dav/backups/null%00byte.tar.gz',
            ])),
        ]);

        $objects = app(DestinationStorage::class)->listBackupObjects($this->webDavDestination('backups'));

        $this->assertSame(['good+literal.tar.gz'], collect($objects)->pluck('key')->all());
    }

    public function test_webdav_download_rejects_embedded_backslashes_without_sending_a_request(): void
    {
        Http::fake();

        try {
            app(DestinationStorage::class)->download(
                $this->webDavDestination('backups'),
                'nested\\backup.tar.gz',
                sys_get_temp_dir().'/wd-backslash.tar.gz',
            );
            $this->fail('Expected the ambiguous WebDAV key to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Invalid WebDAV object key.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function sftpDestination(): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['host' => 'ssh.example.com', 'port' => 22, 'remote_path' => '/srv/backups'],
            'secrets' => ['user' => 'backup', 'password' => 'secret'],
        ]);
    }

    private function localDestination(string $archivePath): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $archivePath],
        ]);
    }

    private function webDavDestination(string $path): BackupDestination
    {
        return BackupDestination::create([
            'name' => 'WebDAV',
            'provider' => BackupDestination::PROVIDER_WEBDAV,
            'bucket' => 'unused',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['url' => 'https://dav.example.com/remote.php/dav', 'path' => $path],
        ]);
    }

    /** @param array<string, string> $settings */
    private function googleDriveDestination(array $settings, string $credentialsTokenUri): BackupDestination
    {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($privateKey, $privateKeyPem);

        return BackupDestination::create([
            'name' => 'Google Drive',
            'provider' => BackupDestination::PROVIDER_GOOGLE_DRIVE,
            'bucket' => 'drive',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['folder_id' => 'folder-id', ...$settings],
            'secrets' => ['credentials_json' => json_encode([
                'client_email' => 'service@example.test',
                'private_key' => $privateKeyPem,
                'token_uri' => $credentialsTokenUri,
            ], JSON_THROW_ON_ERROR)],
        ]);
    }

    private function azureListing(string $key): string
    {
        return '<?xml version="1.0"?><EnumerationResults><Blobs><Blob><Name>'
            .htmlspecialchars($key, ENT_XML1)
            .'</Name><Properties><Content-Length>123</Content-Length><Last-Modified>Wed, 02 Sep 2026 12:00:00 GMT</Last-Modified></Properties></Blob></Blobs><NextMarker></NextMarker></EnumerationResults>';
    }

    /** @param array<int, string> $hrefs */
    private function webDavListing(array $hrefs): string
    {
        $responses = collect($hrefs)->map(fn (string $href): string => '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:getcontentlength>123</d:getcontentlength><d:getlastmodified>Wed, 02 Sep 2026 12:00:00 GMT</d:getlastmodified><d:resourcetype/></d:prop></d:propstat></d:response>')->implode('');

        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'.$responses.'</d:multistatus>';
    }

    private function storageWithSftp(SFTP $sftp): DestinationStorage
    {
        return new class(app(S3ClientFactory::class), $sftp) extends DestinationStorage
        {
            public function __construct(S3ClientFactory $factory, private readonly SFTP $sftpMock)
            {
                parent::__construct($factory);
            }

            protected function sftp(BackupDestination $destination): SFTP
            {
                return $this->sftpMock;
            }
        };
    }
}
