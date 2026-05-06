<?php

namespace App\Services\BackupDestinations;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use Throwable;

class TestBackupDestination
{
    public function __construct(private readonly DestinationStorage $storage) {}

    public function handle(BackupDestination $destination): array
    {
        try {
            $this->storage->test($destination);

            $destination->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'success',
                'last_test_error' => null,
            ])->save();

            return ['ok' => true, 'message' => 'Connection successful.'];
        } catch (Throwable $exception) {
            $message = str(trim($exception->getMessage()) ?: 'Unable to connect to the backup destination.')->limit(500)->toString();

            $destination->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_test_error' => $message,
            ])->save();

            ActivityLog::record('destination_test_failed', 'Destination test failed.', $destination, [
                'destination_id' => $destination->id,
            ]);

            return ['ok' => false, 'message' => $message];
        }
    }
}
