<?php

namespace App\Actions\Docker;

use App\Models\RestoreRun;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Docker\LocalDockerExecution;
use Illuminate\Support\Str;
use RuntimeException;

class RunRestoreContainer
{
    public function __construct(private readonly DockerProcess $dockerProcess) {}

    public function handle(RestoreRun $run, string $archivePath, ?callable $heartbeat = null): DockerProcessResult
    {
        LocalDockerExecution::assertHost((int) $run->target_docker_host_id);
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

        $run->forceFill([
            'docker_container_id' => $containerName,
            'docker_container_cleanup_pending' => $run->mode === RestoreRun::MODE_NEW_VOLUME && ! $run->archiveRelay()->exists(),
        ])->save();

        if ($run->mode === RestoreRun::MODE_NEW_VOLUME) {
            return $this->runOwnedTarget($run, $command, $containerName, $archivePath, $heartbeat);
        }

        $execute = fn (): DockerProcessResult => $this->dockerProcess->runWithInputFile($command, $archivePath, 0);

        return $heartbeat === null
            ? $execute()
            : $this->dockerProcess->whileMonitoring($heartbeat, $execute);
    }

    /**
     * A created container pins its named volume even before it starts. Validate
     * ownership while holding that reference, then start the same container ID.
     */
    private function runOwnedTarget(RestoreRun $run, array $command, string $containerName, string $archivePath, ?callable $heartbeat): DockerProcessResult
    {
        $reference = $containerName;
        $command = array_merge(['docker', 'create'], array_slice($command, 3));
        $mountIndex = array_search('-v', $command, true);
        $command[$mountIndex] = '--mount';
        $command[$mountIndex + 1] = 'type=volume,source='.$run->target_volume_name.',target=/restore,volume-nocopy';

        $execute = function () use ($run, $command, $archivePath, &$reference): DockerProcessResult {
            $token = $run->target_volume_ownership_token;

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Target Docker volume ownership token is missing.');
            }

            $created = $this->dockerProcess->run($command, 60);

            if (! $created->successful()) {
                throw new RuntimeException($created->combinedOutput() ?: 'Unable to create restore helper.');
            }

            $id = trim($created->output);

            if (! preg_match('/^[a-f0-9]{64}$/', $id)) {
                throw new RuntimeException('Unable to determine created restore helper identity.');
            }

            $reference = $id;
            $run->forceFill(['docker_container_id' => $reference])->save();
            $volume = (new InspectDockerVolume($this->dockerProcess))->handle($run->target_volume_name);

            if (($volume['labels'][CreateDockerVolume::RESTORE_OWNERSHIP_LABEL] ?? null) !== $token) {
                throw new RuntimeException('Target Docker volume ownership could not be verified before extraction: '.$run->target_volume_name);
            }

            return $this->dockerProcess->runWithInputFile(['docker', 'start', '--attach', '--interactive', $reference], $archivePath, 0);
        };

        try {
            return $heartbeat === null
                ? $execute()
                : $this->dockerProcess->whileMonitoring($heartbeat, $execute);
        } finally {
            (new RemoveDockerContainer($this->dockerProcess))->handle($reference);
            $run->forceFill(['docker_container_id' => null, 'docker_container_cleanup_pending' => false])->save();
        }
    }
}
