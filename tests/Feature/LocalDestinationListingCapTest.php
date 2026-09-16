<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Services\BackupDestinations\DestinationStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalDestinationListingCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['volumevault.host_path_allowlist' => [sys_get_temp_dir()]]);
    }

    public function test_local_listing_is_capped_at_1000_entries(): void
    {
        $base = sys_get_temp_dir().'/volumevault-listing-cap-'.uniqid();
        File::ensureDirectoryExists($base);

        for ($i = 0; $i < 1010; $i++) {
            File::put($base.'/backup-'.$i.'.tar.gz', 'x');
        }

        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $base],
        ]);

        $objects = app(DestinationStorage::class)->listBackupObjects($destination);

        $this->assertCount(1000, $objects);

        File::deleteDirectory($base);
    }

    public function test_local_listing_excludes_symlinks_and_keys_download_would_reject_without_consuming_the_cap(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The invalid POSIX filenames and symbolic link are not portable to Windows.');
        }

        $base = sys_get_temp_dir().'/volumevault-listing-filter-'.uniqid();
        File::ensureDirectoryExists($base);
        File::put($base.'/source.tar.gz', 'x');
        symlink($base.'/source.tar.gz', $base.'/linked.tar.gz');
        $destination = BackupDestination::create([
            'name' => 'Local',
            'provider' => BackupDestination::PROVIDER_LOCAL,
            'bucket' => 'local',
            'access_key_id' => '',
            'secret_access_key' => '',
            'settings' => ['archive_path' => $base],
        ]);

        $this->assertSame(
            ['source.tar.gz'],
            collect(app(DestinationStorage::class)->listBackupObjects($destination))->pluck('key')->all(),
        );

        for ($i = 0; $i < 10; $i++) {
            File::put($base.'/C:invalid-'.$i.'.tar.gz', 'x');
        }

        for ($i = 0; $i < 1000; $i++) {
            File::put($base.'/valid-'.$i.'.tar.gz', 'x');
        }

        $objects = app(DestinationStorage::class)->listBackupObjects($destination);

        $this->assertCount(1000, $objects);
        $this->assertNotContains('linked.tar.gz', collect($objects)->pluck('key')->all());
        $this->assertEmpty(collect($objects)->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'C:')));

        File::deleteDirectory($base);
    }
}
