<?php

namespace App\Actions\Docker;

use App\Models\BackupRun;
use App\Services\Docker\DockerProcess;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CleanupBackupRetentionHelper
{
    public static function name(BackupRun $run): string
    {
        $namespace = $run->execution_options_snapshot['archive_namespace'] ?? null;
        if (! is_string($namespace) || ! Str::isUuid($namespace)) {
            throw new RuntimeException('Invalid retention helper identity.');
        }

        return 'volumevault-retention-'.$namespace.'-run-'.$run->id;
    }

    public static function owns(BackupRun $run): bool
    {
        return str_starts_with((string) $run->docker_container_id, 'volumevault-retention-');
    }

    public function handle(BackupRun $run): bool
    {
        try {
            $name = self::name($run);
            if ($run->docker_container_id !== $name) {
                return false;
            }
            app(RemoveDockerContainer::class)->handle($name);
            $result = app(DockerProcess::class)->run(['docker', 'container', 'inspect', $name], 30);
            if ($result->successful() || preg_match('/no such (?:container|object)\s*:\s*'.preg_quote($name, '/').'(?:\s|$)/i', $result->combinedOutput()) !== 1) {
                return false;
            }

            $run->forceFill(['docker_container_id' => null, 'docker_container_cleanup_pending' => false])->save();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
