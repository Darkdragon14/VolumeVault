<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\S3\S3ClientFactory;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use phpseclib3\Net\SFTP;
use Tests\TestCase;

class DestinationStorageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_s3_usage_sums_sizes_across_paginated_pages(): void
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('listObjectsV2')
            ->twice()
            ->andReturn(
                new Result([
                    'Contents' => [
                        ['Key' => 'one.tar', 'Size' => 100],
                        ['Key' => 'two.tar', 'Size' => 200],
                    ],
                    'IsTruncated' => true,
                    'NextContinuationToken' => 'page-2',
                ]),
                new Result([
                    'Contents' => [
                        ['Key' => 'three.tar', 'Size' => 50],
                    ],
                    'IsTruncated' => false,
                ]),
            );

        $factory = Mockery::mock(S3ClientFactory::class);
        $factory->shouldReceive('make')->once()->with($destination)->andReturn($client);

        $usage = (new DestinationStorage($factory))->storageUsage($destination);

        $this->assertSame(350, $usage['used_bytes']);
        $this->assertSame(3, $usage['object_count']);
    }

    public function test_sftp_usage_walks_tree_and_closes_the_connection(): void
    {
        $destination = BackupDestination::create([
            'name' => 'SFTP',
            'provider' => BackupDestination::PROVIDER_SSH,
            'bucket' => 'backups',
            'access_key_id' => 'unused',
            'secret_access_key' => 'unused',
            'settings' => ['remote_path' => '/srv/backups'],
            'secrets' => ['user' => 'backup', 'password' => 'secret'],
        ]);

        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('lstat')->once()->with('/srv/backups')->andReturn(['type' => 2]);
        $sftp->shouldReceive('rawlist')
            ->once()
            ->with('/srv/backups')
            ->andReturn([
                '.' => ['type' => 2],
                '..' => ['type' => 2],
                'one.tar' => ['type' => 1, 'size' => 100, 'mtime' => 1700000000],
                'sub' => ['type' => 2],
                'two.tar' => ['type' => 1, 'size' => 200, 'mtime' => 1700000100],
            ]);
        $sftp->shouldReceive('rawlist')
            ->once()
            ->with('/srv/backups/sub')
            ->andReturn([
                'nested.tar' => ['type' => 1, 'size' => 50, 'mtime' => 1700000200],
            ]);
        // The rawlist type attribute must spare us a per-entry is_dir() network round-trip.
        $sftp->shouldNotReceive('is_dir');
        $sftp->shouldReceive('disconnect')->once();

        $storage = new class(app(S3ClientFactory::class), $sftp) extends DestinationStorage
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

        $usage = $storage->storageUsage($destination);

        $this->assertSame(350, $usage['used_bytes']);
        $this->assertSame(3, $usage['object_count']);
    }
}
