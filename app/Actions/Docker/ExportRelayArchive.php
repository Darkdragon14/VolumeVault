<?php

namespace App\Actions\Docker;

use App\Models\BackupDestination;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerVolumeName;
use App\Services\Docker\LocalDockerExecution;
use RuntimeException;

class ExportRelayArchive
{
    public function handle(BackupDestination $destination, string $key, string $path, string $id, int $maxBytes): void
    {
        LocalDockerExecution::assertHost((int) $destination->docker_host_id);
        $volume = (string) $destination->setting('volume_name');
        if (! DockerVolumeName::isValidName($volume) || $maxBytes <= 0) {
            throw new RuntimeException('Invalid relay source.');
        }
        $key = DockerVolumeName::assertKey($key);
        $prefix = trim((string) $destination->setting('path_prefix', ''), '/');
        if ($prefix !== '') {
            $prefix = DockerVolumeName::assertKey($prefix);
        }
        $root = '/archive'.($prefix === '' ? '' : '/'.$prefix);
        $docker = app(DockerProcess::class);
        $name = CleanupDestinationOperationHelper::name($id);
        if (! $docker->run(['docker', 'volume', 'inspect', $volume], 30)->successful()) {
            throw new RuntimeException('Archive source Docker volume is missing.');
        }
        $result = $docker->run(['docker', 'create', '--name', $name, '--network', 'none',
            '-v', $volume.':/archive:ro', '--entrypoint', 'sh', RunBackupContainer::IMAGE,
            '-ec', 'dev=$(stat -c %d "$1"); ino=$(stat -c %i "$1"); exec /tmp/relay-reader "$1" "$2" /tmp/unused-target "$dev" "$ino" "$3"',
            'relay', $root, $key, (string) $maxBytes], 120);
        if (! $result->successful()) {
            throw new RuntimeException('Unable to create archive export helper.');
        }
        $result = $docker->run(['docker', 'cp', '/usr/local/bin/volumevault-local-archive-reader', $name.':/tmp/relay-reader'], 120);
        if (! $result->successful()) {
            throw new RuntimeException('Unable to prepare archive export helper.');
        }
        $result = $docker->runWithOutputFile(['docker', 'start', '--attach', $name], $path, 0);
        if (! $result->successful()) {
            throw new RuntimeException('Archive export failed source validation or size checks.');
        }
    }
}
