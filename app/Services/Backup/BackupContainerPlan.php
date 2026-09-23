<?php

namespace App\Services\Backup;

final readonly class BackupContainerPlan
{
    /**
     * Resolved, trusted runtime inputs; contains plaintext environment secrets.
     * Do not persist or log this plan. Validation belongs to its producer.
     *
     * @param  list<string>  $sourceMountArguments
     * @param  list<string>  $mounts
     * @param  array<string, string>  $environment
     * @param  array<string, string>  $copies  Local source path => container destination path.
     */
    public function __construct(
        public string $containerName,
        public string $image,
        public string $dockerHost,
        public string $dockerNetwork = '',
        public array $sourceMountArguments = [],
        public array $mounts = [],
        public array $environment = [],
        public array $copies = [],
    ) {}
}
