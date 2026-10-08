<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\SecureLocalArchiveReader;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\S3\S3ClientFactory;
use App\Services\Security\OutboundHostGuard;
use Aws\S3\S3Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DestinationArchiveDeletionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = '/tmp/volumevault-archive-delete-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory.'/archives/nested');
        config(['volumevault.host_path_allowlist' => ['/tmp']]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_local_deletion_removes_only_exact_selected_regular_archives(): void
    {
        $root = $this->directory.'/archives';
        foreach (['old.tar.gz', 'nested/old.tar.gz', 'keep.tar.gz', 'notes.txt'] as $key) {
            File::put($root.'/'.$key, 'archive');
        }
        $this->storage()->deleteBackupObjects($this->destination('local', ['archive_path' => $root]), ['old.tar.gz', 'nested/old.tar.gz', 'old.tar.gz']);
        $this->assertFileDoesNotExist($root.'/old.tar.gz');
        $this->assertFileDoesNotExist($root.'/nested/old.tar.gz');
        $this->assertFileExists($root.'/keep.tar.gz');
        $this->assertFileExists($root.'/notes.txt');
        $this->assertDirectoryExists($root.'/nested');
    }

    public function test_empty_selection_is_a_noop(): void
    {
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldNotReceive('make');
        (new DestinationStorage($factory))->deleteBackupObjects($this->destination(BackupDestination::PROVIDER_AWS_S3), []);
        Http::assertNothingSent();
    }

    public function test_literal_glob_names_never_expand_to_other_archives(): void
    {
        $root = $this->directory.'/archives';
        foreach (['old[1].tar.gz', 'old1.tar.gz', 'old*.tar.gz', 'old-other.tar.gz', 'old?.tar.gz', 'olda.tar.gz'] as $name) {
            File::put($root.'/'.$name, 'archive');
        }

        $this->storage()->deleteBackupObjects($this->destination('local', ['archive_path' => $root]), ['old[1].tar.gz', 'old*.tar.gz', 'old?.tar.gz']);

        foreach (['old[1].tar.gz', 'old*.tar.gz', 'old?.tar.gz'] as $name) {
            $this->assertFileDoesNotExist($root.'/'.$name);
        }
        foreach (['old1.tar.gz', 'old-other.tar.gz', 'olda.tar.gz'] as $name) {
            $this->assertFileExists($root.'/'.$name);
        }
    }

    public function test_sftp_listing_failure_after_discovering_current_archive_prevents_deletion(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->with('/backups')->once()->andReturn(['type' => 2]);
        $sftp->shouldReceive('rawlist')->with('/backups')->once()->andReturn([
            'current.tar.gz' => ['type' => 1, 'size' => 4, 'mtime' => time()],
            'old.tar.gz' => ['type' => 1, 'size' => 4, 'mtime' => time() - 86400],
            'unreadable' => ['type' => 2],
        ]);
        $sftp->shouldReceive('rawlist')->with('/backups/unreadable')->once()->andReturnFalse();
        $sftp->shouldReceive('disconnect')->once();
        $sftp->shouldNotReceive('delete');

        $this->assertDeletionFails($this->sftpStorage($sftp), $this->destination(BackupDestination::PROVIDER_SSH, ['remote_path' => '/backups']), ['old.tar.gz']);
    }

    public function test_local_deletion_monitors_liveness_during_the_native_process(): void
    {
        $reader = new class extends SecureLocalArchiveReader
        {
            protected function createProcess(array $command): Process
            {
                return new Process(['sh', '-c', 'sleep 0.3']);
            }

            protected function progressIntervalSeconds(): int
            {
                return 0;
            }
        };
        $progress = 0;
        $reader->delete($this->directory.'/archives', ['old.tar.gz'], ['dev' => 1, 'ino' => 1], function () use (&$progress): void {
            $progress++;
        });

        $this->assertGreaterThan(1, $progress);
    }

    public function test_azure_container_cannot_inject_a_different_delete_scope(): void
    {
        $destination = $this->destination(BackupDestination::PROVIDER_AZURE_BLOB, [
            'account_name' => 'account', 'endpoint' => 'https://azure.example.test', 'container' => 'backups/../other',
        ], ['account_key' => base64_encode('secret')]);
        $this->assertDeletionFails($this->storage(), $destination, ['old.tar.gz']);
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeLocalKeys')]
    public function test_invalid_local_selection_is_rejected_before_any_deletion(string $invalid): void
    {
        $root = $this->directory.'/archives';
        File::put($root.'/old.tar.gz', 'archive');
        File::put($root.'/notes.txt', 'notes');
        File::put($this->directory.'/outside.tar.gz', 'outside');
        symlink($this->directory.'/outside.tar.gz', $root.'/link.tar.gz');
        symlink($this->directory, $root.'/linked');
        $this->assertDeletionFails($this->storage(), $this->destination('local', ['archive_path' => $root]), ['old.tar.gz', $invalid]);
        $this->assertFileExists($root.'/old.tar.gz');
        $this->assertSame('outside', File::get($this->directory.'/outside.tar.gz'));
    }

    public static function unsafeLocalKeys(): array
    {
        return array_map(fn (string $key): array => [$key], ['../outside.tar.gz', '/outside.tar.gz', 'nested/../old.tar.gz', 'old*.tar.gz', 'missing.tar.gz', 'notes.txt', 'link.tar.gz', 'linked/outside.tar.gz', "old\0.tar.gz", 'nested//old.tar.gz']);
    }

    public function test_local_root_symlink_and_hardlink_selection_fail_closed(): void
    {
        $root = $this->directory.'/archives';
        File::put($root.'/old.tar.gz', 'archive');
        symlink($root, $this->directory.'/root-link');
        $this->assertDeletionFails($this->storage(), $this->destination('local', ['archive_path' => $this->directory.'/root-link']), ['old.tar.gz']);
        File::put($this->directory.'/outside.tar.gz', 'outside');
        link($this->directory.'/outside.tar.gz', $root.'/hard.tar.gz');
        $this->assertDeletionFails($this->storage(), $this->destination('local', ['archive_path' => $root]), ['old.tar.gz', 'hard.tar.gz']);
        $this->assertFileExists($root.'/old.tar.gz');
        $this->assertFileExists($root.'/hard.tar.gz');
    }

    public function test_native_helper_validates_the_entire_selection_before_unlinking(): void
    {
        $root = $this->directory.'/archives';
        File::put($root.'/old.tar.gz', 'archive');
        $stat = stat($root);
        $process = new Process(['/usr/local/bin/volumevault-local-archive-reader', 'delete', $root, (string) $stat['dev'], (string) $stat['ino'], 'old.tar.gz', '../outside.tar.gz']);
        $this->assertNotSame(0, $process->run());
        $this->assertFileExists($root.'/old.tar.gz');
    }

    public function test_native_deletion_rejects_a_root_swapped_to_a_symlink(): void
    {
        $root = $this->directory.'/archives';
        File::put($root.'/old.tar.gz', 'archive');
        $stat = stat($root);
        rename($root, $this->directory.'/original');
        mkdir($this->directory.'/outside');
        File::put($this->directory.'/outside/old.tar.gz', 'outside');
        symlink($this->directory.'/outside', $root);
        try {
            (new SecureLocalArchiveReader)->delete($root, ['old.tar.gz'], $stat);
            $this->fail('Expected a replaced archive root to be rejected.');
        } catch (RuntimeException) {
            $this->assertFileExists($this->directory.'/original/old.tar.gz');
            $this->assertSame('outside', File::get($this->directory.'/outside/old.tar.gz'));
        }
    }

    public function test_native_deletion_keeps_the_parent_descriptor_pinned_during_a_symlink_swap(): void
    {
        $compiler = new Process(['sh', '-c', 'command -v cc']);
        if ($compiler->run() !== 0) {
            $this->markTestSkipped('A C compiler is required for the deterministic descriptor-race test.');
        }
        $root = $this->directory.'/archives';
        $parent = $root.'/nested';
        $moved = $root.'/pinned';
        $outside = $this->directory.'/outside';
        mkdir($outside);
        File::put($parent.'/old.tar.gz', 'archive');
        File::put($outside.'/old.tar.gz', 'outside');
        $source = $this->directory.'/swap.c';
        File::put($source, <<<'C'
#define _GNU_SOURCE
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
int unlinkat(int directory, const char *name, int flags) {
    int (*original)(int, const char *, int) = dlsym(RTLD_NEXT, "unlinkat");
    if (rename(getenv("VV_SWAP_PARENT"), getenv("VV_MOVED_PARENT")) != 0
        || symlink(getenv("VV_OUTSIDE"), getenv("VV_SWAP_PARENT")) != 0) {
        return -1;
    }
    return original(directory, name, flags);
}
C);
        $library = $this->directory.'/swap.so';
        (new Process(['cc', '-shared', '-fPIC', '-Wall', '-Wextra', '-Werror', $source, '-o', $library, '-ldl']))->mustRun();
        $stat = stat($root);
        $process = new Process(['/usr/local/bin/volumevault-local-archive-reader', 'delete', $root, (string) $stat['dev'], (string) $stat['ino'], 'nested/old.tar.gz'], env: [
            'LD_PRELOAD' => $library, 'VV_SWAP_PARENT' => $parent, 'VV_MOVED_PARENT' => $moved, 'VV_OUTSIDE' => $outside,
        ]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($moved.'/old.tar.gz');
        $this->assertSame('outside', File::get($outside.'/old.tar.gz'));
    }

    #[DataProvider('s3Providers')]
    public function test_s3_providers_delete_exact_bucket_keys(string $provider): void
    {
        $destination = $this->destination($provider, ['bucket' => 'backups', 'path_prefix' => 'jobs']);
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')->once()->andReturn(['Contents' => [['Key' => 'jobs/old.tar.gz'], ['Key' => 'jobs/keep.tar.gz']]]);
        $client->shouldReceive('deleteObject')->once()->with(['Bucket' => 'backups', 'Key' => 'jobs/old.tar.gz', '@http' => ['connect_timeout' => 15, 'timeout' => 60]])->andReturn([]);
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);
        (new DestinationStorage($factory))->deleteBackupObjects($destination, ['jobs/old.tar.gz']);
        $this->addToAssertionCount(1);
    }

    public static function s3Providers(): array
    {
        return [[BackupDestination::PROVIDER_AWS_S3], [BackupDestination::PROVIDER_CLOUDFLARE_R2], [BackupDestination::PROVIDER_CUSTOM_S3]];
    }

    public function test_s3_prefix_collision_and_missing_keys_never_issue_deletes(): void
    {
        $destination = $this->destination(BackupDestination::PROVIDER_AWS_S3, ['bucket' => 'backups', 'path_prefix' => 'jobs']);
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')->once()->andReturn(['Contents' => [['Key' => 'jobs/old.tar.gz']]]);
        $client->shouldNotReceive('deleteObject');
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);
        $storage = new DestinationStorage($factory);
        $this->assertDeletionFails($storage, $destination, ['jobs/old.tar.gz', 'jobs-other/old.tar.gz']);
        $this->assertDeletionFails($storage, $destination, ['jobs/old.tar.gz', 'jobs/missing.tar.gz']);
    }

    #[DataProvider('httpProviders')]
    public function test_http_providers_delete_exact_listed_archives(string $provider): void
    {
        [$destination, $key, $deleteUrl] = $this->httpFixture($provider);
        $this->storage()->deleteBackupObjects($destination, [$key]);
        Http::assertSent(fn (Request $request): bool => $request->url() === $deleteUrl
            && ($provider === BackupDestination::PROVIDER_DROPBOX ? $request->method() === 'POST' && $request['path'] === $key : $request->method() === 'DELETE'));
    }

    #[DataProvider('httpProviders')]
    public function test_http_providers_reject_unlisted_selection_before_deleting(string $provider): void
    {
        [$destination, $key, $deleteUrl] = $this->httpFixture($provider);
        $missing = match ($provider) {
            BackupDestination::PROVIDER_DROPBOX => 'id:missing',
            BackupDestination::PROVIDER_GOOGLE_DRIVE => 'gdrive:missing',
            default => 'missing.tar.gz',
        };
        $this->assertDeletionFails($this->storage(), $destination, [$key, $missing]);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' || str_contains($request->url(), '/delete_v2'));
    }

    #[DataProvider('httpProviders')]
    public function test_http_provider_failure_does_not_expose_secrets(string $provider): void
    {
        [$destination, $key] = $this->httpFixture($provider, true);
        $this->assertDeletionFails($this->storage(), $destination, [$key]);
    }

    public static function httpProviders(): array
    {
        return [[BackupDestination::PROVIDER_WEBDAV], [BackupDestination::PROVIDER_AZURE_BLOB], [BackupDestination::PROVIDER_DROPBOX], [BackupDestination::PROVIDER_GOOGLE_DRIVE]];
    }

    public function test_dropbox_objects_outside_the_configured_root_are_not_deletable(): void
    {
        [$destination, $key] = $this->httpFixture(BackupDestination::PROVIDER_DROPBOX, invalidObject: true);
        $this->assertDeletionFails($this->storage(), $destination, [$key]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'delete_v2'));
    }

    public function test_google_drive_folders_and_shortcuts_are_not_deletable(): void
    {
        [$destination] = $this->httpFixture(BackupDestination::PROVIDER_GOOGLE_DRIVE, invalidObject: true);
        $this->assertDeletionFails($this->storage(), $destination, ['gdrive:file-id']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    #[DataProvider('opaqueProviders')]
    public function test_opaque_archives_moved_outside_the_destination_after_listing_are_not_deleted(string $provider): void
    {
        [$destination, $key] = $this->httpFixture($provider, movedObject: true);
        $this->assertDeletionFails($this->storage(), $destination, [$key]);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' || str_contains($request->url(), '/delete_v2'));
    }

    public static function opaqueProviders(): array
    {
        return [[BackupDestination::PROVIDER_DROPBOX], [BackupDestination::PROVIDER_GOOGLE_DRIVE]];
    }

    public function test_sftp_deletes_regular_files_non_recursively_and_disconnects(): void
    {
        $sftp = $this->sftpFixture();
        $sftp->shouldReceive('delete')->once()->with('/backups/old.tar.gz', false)->andReturn(true);
        $this->sftpStorage($sftp)->deleteBackupObjects($this->destination(BackupDestination::PROVIDER_SSH, ['remote_path' => '/backups']), ['old.tar.gz']);
        $this->addToAssertionCount(1);
    }

    public function test_sftp_revalidates_symlink_types_before_any_deletion(): void
    {
        $sftp = $this->sftpFixture();
        $sftp->shouldReceive('lstat')->with('/backups/old.tar.gz')->andReturn(['type' => 3]);
        $sftp->shouldNotReceive('delete');
        $this->assertDeletionFails($this->sftpStorage($sftp), $this->destination(BackupDestination::PROVIDER_SSH, ['remote_path' => '/backups']), ['old.tar.gz']);
    }

    public function test_sftp_failure_is_safe_and_disconnects(): void
    {
        $sftp = $this->sftpFixture();
        $sftp->shouldReceive('delete')->with('/backups/old.tar.gz', false)->andThrow(new RuntimeException('plaintext-secret'));
        $this->assertDeletionFails($this->sftpStorage($sftp), $this->destination(BackupDestination::PROVIDER_SSH, ['remote_path' => '/backups']), ['old.tar.gz']);
    }

    public function test_sftp_rejects_a_symlink_in_the_configured_root_ancestors(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('disconnect')->twice();
        $sftp->shouldReceive('setTimeout')->once();
        $sftp->shouldReceive('disableStatCache')->once();
        $sftp->shouldReceive('lstat')->with('/parent/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->with('/parent')->andReturn(['type' => 3]);
        $sftp->shouldReceive('rawlist')->with('/parent/backups')->andReturn(['old.tar.gz' => ['type' => 1]]);
        $sftp->shouldNotReceive('delete');
        $this->assertDeletionFails($this->sftpStorage($sftp), $this->destination(BackupDestination::PROVIDER_SSH, ['remote_path' => '/parent/backups']), ['old.tar.gz']);
    }

    public function test_s3_deletion_failure_does_not_expose_provider_diagnostics(): void
    {
        $destination = $this->destination(BackupDestination::PROVIDER_AWS_S3, ['bucket' => 'backups']);
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')->andReturn(['Contents' => [['Key' => 'old.tar.gz']]]);
        $client->shouldReceive('deleteObject')->andThrow(new RuntimeException('plaintext-secret'));
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);
        $this->assertDeletionFails(new DestinationStorage($factory), $destination, ['old.tar.gz']);
    }

    public function test_s3_deletion_finds_an_archive_on_a_later_listing_page(): void
    {
        $destination = $this->destination(BackupDestination::PROVIDER_AWS_S3, ['bucket' => 'backups']);
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')->once()->with(Mockery::on(fn (array $options): bool => ! isset($options['ContinuationToken'])))
            ->andReturn(['Contents' => [['Key' => 'keep.tar.gz']], 'IsTruncated' => true, 'NextContinuationToken' => 'page-2']);
        $client->shouldReceive('listObjectsV2')->once()->with(Mockery::on(fn (array $options): bool => ($options['ContinuationToken'] ?? null) === 'page-2'))
            ->andReturn(['Contents' => [['Key' => 'old.tar.gz']]]);
        $client->shouldReceive('deleteObject')->once()->with(Mockery::on(fn (array $options): bool => $options['Key'] === 'old.tar.gz'))->andReturn([]);
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);
        (new DestinationStorage($factory))->deleteBackupObjects($destination, ['old.tar.gz']);
        $this->addToAssertionCount(1);
    }

    public function test_truncated_s3_listing_without_a_cursor_never_deletes(): void
    {
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')->once()->andReturn(['Contents' => [['Key' => 'old.tar.gz']], 'IsTruncated' => true]);
        $client->shouldNotReceive('deleteObject');
        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);

        $this->assertDeletionFails(new DestinationStorage($factory), $this->destination(BackupDestination::PROVIDER_AWS_S3), ['old.tar.gz']);
    }

    public function test_truncated_dropbox_listing_without_a_cursor_never_deletes(): void
    {
        $destination = $this->destination(BackupDestination::PROVIDER_DROPBOX, ['remote_path' => '/backups'], [
            'app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh',
        ]);
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'token']),
            '*list_folder' => Http::response(['entries' => [['.tag' => 'file', 'id' => 'id:file-id', 'name' => 'old.tar.gz', 'path_display' => '/backups/old.tar.gz']], 'has_more' => true]),
        ]);

        $this->assertDeletionFails($this->storage(), $destination, ['id:file-id']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'delete_v2'));
    }

    public function test_docker_volume_uses_named_helper_and_exact_positional_paths(): void
    {
        $process = $this->dockerFixture();
        $storage = $this->storage($process);
        $storage->useOperationHelper('vv-operation-retention-test');
        $storage->deleteBackupObjects($this->destination(BackupDestination::PROVIDER_DOCKER_VOLUME, ['volume_name' => 'backups', 'path_prefix' => 'daily']), ['old.tar.gz']);
        $command = $process->deletion;
        $this->assertContains('backups:/archive', $command);
        $this->assertContains('--name', $command);
        $this->assertContains('vv-operation-retention-test', $command);
        $this->assertSame('/archive/daily/old.tar.gz', end($command));
        $script = $command[array_search('-c', $command, true) + 1];
        $this->assertStringNotContainsString('rm -r', $script);
        File::put($this->directory.'/archives/old.tar.gz', 'archive');
        $run = new Process(['sh', '-c', $script, 'sh', $this->directory.'/archives/old.tar.gz']);
        $this->assertSame(0, $run->run(), $run->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/archives/old.tar.gz');
    }

    public function test_docker_deletion_script_rejects_parent_symlinks_before_deleting_anything(): void
    {
        $process = $this->dockerFixture();
        $this->storage($process)->deleteBackupObjects($this->destination(BackupDestination::PROVIDER_DOCKER_VOLUME, ['volume_name' => 'backups', 'path_prefix' => 'daily']), ['old.tar.gz']);
        $script = $process->deletion[array_search('-c', $process->deletion, true) + 1];
        File::put($this->directory.'/archives/old.tar.gz', 'archive');
        File::put($this->directory.'/outside.tar.gz', 'outside');
        symlink($this->directory, $this->directory.'/archives/linked');
        $run = new Process(['sh', '-c', $script, 'sh', $this->directory.'/archives/old.tar.gz', $this->directory.'/archives/linked/outside.tar.gz']);
        $this->assertNotSame(0, $run->run());
        $this->assertFileExists($this->directory.'/archives/old.tar.gz');
        $this->assertFileExists($this->directory.'/outside.tar.gz');
    }

    public function test_docker_deletion_timeout_returns_only_a_safe_error(): void
    {
        $this->assertDeletionFails($this->storage($this->dockerFixture(true)), $this->destination(BackupDestination::PROVIDER_DOCKER_VOLUME, ['volume_name' => 'backups', 'path_prefix' => 'daily']), ['old.tar.gz']);
    }

    private function destination(string $provider, array $settings = [], array $secrets = []): BackupDestination
    {
        return new BackupDestination(['name' => 'Archives', 'provider' => $provider, 'bucket' => 'backups', 'settings' => $settings, 'secrets' => $secrets]);
    }

    private function storage(?DockerProcess $process = null): DestinationStorage
    {
        $guard = Mockery::mock(OutboundHostGuard::class);
        $guard->shouldReceive('assertHostAllowed')->andReturnNull();
        $guard->shouldReceive('assertUrlAllowed')->andReturnNull();

        return new DestinationStorage(app(S3ClientFactory::class), $guard, $process);
    }

    private function assertDeletionFails(DestinationStorage $storage, BackupDestination $destination, array $keys): void
    {
        try {
            $storage->deleteBackupObjects($destination, $keys);
            $this->fail('Expected unsafe or unsuccessful deletion to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to delete selected backup archives safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /** @return array{BackupDestination, string, string} */
    private function httpFixture(string $provider, bool $failure = false, bool $invalidObject = false, bool $movedObject = false): array
    {
        $key = 'old.tar.gz';
        $settings = [];
        $secrets = [];
        $status = $failure ? 403 : 204;
        if ($provider === BackupDestination::PROVIDER_WEBDAV) {
            $settings = ['url' => 'https://dav.example.test', 'path' => 'backups'];
            $url = 'https://dav.example.test/backups/old.tar.gz';
            Http::fake(fn (Request $request) => $request->method() === 'PROPFIND'
                ? Http::response('<d:multistatus xmlns:d="DAV:"><d:response><d:href>/backups/old.tar.gz</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>4</d:getcontentlength></d:prop></d:propstat></d:response></d:multistatus>', 207)
                : Http::response('plaintext-secret', $status));
        } elseif ($provider === BackupDestination::PROVIDER_AZURE_BLOB) {
            $settings = ['account_name' => 'account', 'container' => 'backups', 'endpoint' => 'https://azure.example.test'];
            $secrets = ['account_key' => base64_encode('secret')];
            $url = 'https://azure.example.test/backups/old.tar.gz';
            Http::fake(fn (Request $request) => $request->method() === 'GET'
                ? Http::response('<EnumerationResults><Blobs><Blob><Name>old.tar.gz</Name><Properties><Content-Length>4</Content-Length><Last-Modified>2026-01-01T00:00:00Z</Last-Modified></Properties></Blob></Blobs><NextMarker/></EnumerationResults>')
                : Http::response('plaintext-secret', $status));
        } elseif ($provider === BackupDestination::PROVIDER_DROPBOX) {
            $settings = ['remote_path' => '/backups'];
            $secrets = ['app_key' => 'app', 'app_secret' => 'secret', 'refresh_token' => 'refresh'];
            $key = 'id:file-id';
            $url = 'https://api.dropboxapi.com/2/files/delete_v2';
            Http::fake([
                '*oauth2/token' => Http::response(['access_token' => 'token']),
                '*list_folder' => Http::response(['entries' => [['.tag' => 'file', 'id' => $key, 'name' => 'old.tar.gz', 'path_display' => $invalidObject ? '/backups-other/old.tar.gz' : '/backups/old.tar.gz']], 'has_more' => false]),
                '*get_metadata' => Http::response(['.tag' => 'file', 'id' => $key, 'name' => 'old.tar.gz', 'path_display' => $movedObject ? '/elsewhere/old.tar.gz' : '/backups/old.tar.gz']),
                '*delete_v2' => Http::response('plaintext-secret', $failure ? 403 : 200),
            ]);
        } else {
            $privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
            openssl_pkey_export($privateKey, $pem);
            $settings = ['folder_id' => 'folder-id'];
            $secrets = ['credentials_json' => json_encode(['client_email' => 'service@example.test', 'private_key' => $pem])];
            $key = 'gdrive:file-id';
            $url = 'https://www.googleapis.com/drive/v3/files/file-id?supportsAllDrives=true';
            Http::fake([
                '*oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
                '*drive/v3/files*' => function (Request $request) use ($status, $invalidObject, $movedObject) {
                    if ($request->method() === 'DELETE') {
                        return Http::response('plaintext-secret', $status);
                    }
                    if (str_contains($request->url(), '/files/file-id')) {
                        return Http::response(['id' => 'file-id', 'name' => 'old.tar.gz', 'parents' => [$movedObject ? 'elsewhere' : 'folder-id'], 'trashed' => false, 'mimeType' => 'application/gzip']);
                    }

                    return Http::response(['files' => [['id' => 'file-id', 'name' => 'old.tar.gz', 'mimeType' => $invalidObject ? 'application/vnd.google-apps.shortcut' : 'application/gzip']]]);
                },
            ]);
        }

        return [$this->destination($provider, $settings, $secrets), $key, $url];
    }

    private function sftpFixture(): SFTP
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('disconnect')->twice();
        $sftp->shouldReceive('setTimeout')->with(60)->once();
        $sftp->shouldReceive('disableStatCache')->once();
        $sftp->shouldReceive('lstat')->with('/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('lstat')->with('/backups/old.tar.gz')->byDefault()->andReturn(['type' => 1]);
        $sftp->shouldReceive('rawlist')->with('/backups')->andReturn(['old.tar.gz' => ['type' => 1, 'size' => 4]]);

        return $sftp;
    }

    private function sftpStorage(SFTP $sftp): DestinationStorage
    {
        return new class(app(S3ClientFactory::class), $sftp) extends DestinationStorage
        {
            public function __construct(S3ClientFactory $factory, private readonly SFTP $connection)
            {
                parent::__construct($factory);
            }

            protected function sftp(BackupDestination $destination): SFTP
            {
                return $this->connection;
            }
        };
    }

    private function dockerFixture(bool $timeout = false): DockerProcess
    {
        return new class($timeout) extends DockerProcess
        {
            public array $deletion = [];

            public function __construct(private readonly bool $timeout) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if (in_array('inspect', $command, true)) {
                    return new DockerProcessResult($command, 0, '', '');
                }
                if (in_array('backups:/archive:ro', $command, true)) {
                    return new DockerProcessResult($command, 0, "4|1700000000|/archive/daily/old.tar.gz\n", '');
                }
                $this->deletion = $command;

                return new DockerProcessResult($command, $this->timeout ? 1 : 0, '', $this->timeout ? 'plaintext-secret' : '', $this->timeout);
            }
        };
    }
}
