<?php

namespace Tests\Feature;

use App\Actions\Docker\ClearDockerVolume;
use App\Actions\Docker\RunBackupContainer;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use RuntimeException;
use Tests\TestCase;

class ClearDockerVolumeTest extends TestCase
{
    public function test_it_deletes_the_volume_contents_in_a_throwaway_container(): void
    {
        $docker = $this->recordingDocker(successful: true);

        (new ClearDockerVolume($docker))->handle('app_data');

        $command = $docker->command;
        $this->assertSame(['docker', 'run', '--rm'], array_slice($command, 0, 3));
        $this->assertContains('app_data:/target', $command);
        $this->assertSame(RunBackupContainer::IMAGE, $command[array_search('--entrypoint', $command, true) + 2]);

        // find /target -mindepth 1 -delete empties the volume without removing
        // the mount point itself.
        $this->assertContains('find', $command);
        $this->assertContains('/target', $command);
        $this->assertContains('-mindepth', $command);
        $this->assertSame('1', $command[array_search('-mindepth', $command, true) + 1]);
        $this->assertContains('-delete', $command);
    }

    public function test_it_throws_when_the_clear_command_fails(): void
    {
        $docker = $this->recordingDocker(successful: false);

        $this->expectException(RuntimeException::class);

        (new ClearDockerVolume($docker))->handle('app_data');
    }

    private function recordingDocker(bool $successful): DockerProcess
    {
        return new class($successful) extends DockerProcess
        {
            public array $command = [];

            public function __construct(private readonly bool $successful) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                $this->command = $command;

                return new DockerProcessResult($command, $this->successful ? 0 : 1, '', $this->successful ? '' : 'boom');
            }
        };
    }
}
