<?php

namespace App\Actions\Docker;

use App\Models\BackupRun;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CleanupBackupRunSecretFiles
{
    public function handle(BackupRun $run): void
    {
        $pattern = storage_path('app/docker-secrets/backup-'.$run->id.'-ssh-key-*');

        foreach (File::glob($pattern) as $path) {
            File::delete($path);
        }

        if (File::glob($pattern) !== []) {
            throw new RuntimeException("Unable to remove temporary secret files for backup run {$run->id}.");
        }
    }
}
