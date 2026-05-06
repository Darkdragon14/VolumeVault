<?php

namespace App\Services\S3;

use App\Models\ActivityLog;
use App\Models\BackupDestination;
use Throwable;

class TestS3Destination
{
    public function __construct(private readonly S3ClientFactory $clientFactory) {}

    public function handle(BackupDestination $destination): array
    {
        try {
            $this->clientFactory->make($destination)->listObjectsV2([
                'Bucket' => $destination->bucket,
                'Prefix' => trim((string) $destination->path_prefix, '/'),
                'MaxKeys' => 1,
            ]);

            $destination->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'success',
                'last_test_error' => null,
            ])->save();

            return ['ok' => true, 'message' => 'Connection successful.'];
        } catch (Throwable $exception) {
            $message = $this->friendlyMessage($exception);

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

    private function friendlyMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if (! filled($message)) {
            return 'Unable to connect to the S3-compatible destination.';
        }

        return str($message)->limit(500)->toString();
    }
}
