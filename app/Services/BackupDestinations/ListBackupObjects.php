<?php

namespace App\Services\BackupDestinations;

use App\Models\BackupDestination;

class ListBackupObjects
{
    public function __construct(private readonly DestinationStorage $storage) {}

    public function handle(BackupDestination $destination): array
    {
        return $this->storage->listBackupObjects($destination);
    }
}
