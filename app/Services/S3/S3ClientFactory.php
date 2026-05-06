<?php

namespace App\Services\S3;

use App\Models\BackupDestination;
use Aws\S3\S3Client;

class S3ClientFactory
{
    public function make(BackupDestination $destination): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => $destination->setting('region') ?: 'us-east-1',
            'credentials' => [
                'key' => $destination->secret('access_key_id'),
                'secret' => $destination->secret('secret_access_key'),
            ],
            'use_path_style_endpoint' => (bool) $destination->setting('use_path_style_endpoint'),
        ];

        if (filled($destination->setting('endpoint'))) {
            $config['endpoint'] = $destination->setting('endpoint');
        }

        return new S3Client($config);
    }
}
