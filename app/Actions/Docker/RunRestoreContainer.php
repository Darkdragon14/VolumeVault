<?php

namespace App\Actions\Docker;

use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Support\Str;

class RunRestoreContainer
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(RestoreRun $run, string $archivePath): DockerProcessResult
    {
        $containerName = 'volumevault-restore-'.$run->id.'-'.Str::lower(Str::random(8));

        $command = [
            'docker',
            'run',
            '--rm',
            '--name',
            $containerName,
            '-i',
            '-v',
            $run->target_volume_name.':/restore',
            '--entrypoint',
            'tar',
            RunBackupContainer::IMAGE,
            '-xzf',
            '-',
            '-C',
            '/restore',
            '--strip-components',
            '2',
        ];

        $run->forceFill(['docker_container_id' => $containerName])->save();

        return $this->dockerProcess->runWithInputFile($command, $archivePath, 0);
    }
}
