<?php

namespace Tests\Feature;

use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Restore\GenerateRestoreVolumeName;
use App\Models\DockerVolume;
use App\Models\Host;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RestoreVolumeNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_name_starts_with_source_volume_and_timestamp(): void
    {
        $generator = new GenerateRestoreVolumeName($this->missingVolumeInspector());

        $name = $generator->handle('my-app_data', CarbonImmutable::parse('2026-05-01 14:30:00', 'UTC'));

        $this->assertSame('my-app_data_restored_20260501_143000', $name);
    }

    public function test_invalid_characters_are_sanitized(): void
    {
        $generator = new GenerateRestoreVolumeName($this->missingVolumeInspector());

        $name = $generator->handle('my app/data', CarbonImmutable::parse('2026-05-01 14:30:00', 'UTC'));

        $this->assertSame('my_app_data_restored_20260501_143000', $name);
    }

    public function test_collisions_generate_unique_names(): void
    {
        DockerVolume::create([
            'name' => 'my-app_data_restored_20260501_143000',
            'exists' => true,
        ]);

        $generator = new GenerateRestoreVolumeName($this->missingVolumeInspector());

        $name = $generator->handle('my-app_data', CarbonImmutable::parse('2026-05-01 14:30:00', 'UTC'));

        $this->assertSame('my-app_data_restored_20260501_143000_2', $name);
    }

    public function test_database_collisions_are_scoped_to_host_when_host_is_provided(): void
    {
        $localHost = Host::localHost();
        $agentHost = Host::factory()->agent()->create();
        DockerVolume::create([
            'host_id' => $agentHost->id,
            'name' => 'my-app_data_restored_20260501_143000',
            'exists' => true,
        ]);
        $generator = new GenerateRestoreVolumeName($this->missingVolumeInspector());

        $name = $generator->handle('my-app_data', CarbonImmutable::parse('2026-05-01 14:30:00', 'UTC'), $localHost->id);

        $this->assertSame('my-app_data_restored_20260501_143000', $name);
    }

    private function missingVolumeInspector(): InspectDockerVolume
    {
        $mock = Mockery::mock(InspectDockerVolume::class);
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('missing'));

        return $mock;
    }
}
