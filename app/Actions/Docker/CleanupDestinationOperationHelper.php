<?php

namespace App\Actions\Docker;

use App\Services\Docker\DockerProcess;
use Illuminate\Support\Str;

class CleanupDestinationOperationHelper
{
    public static function name(string $operationId): string
    {
        if (! Str::isUuid($operationId)) {
            throw new \RuntimeException('Invalid destination operation identity.');
        }

        return 'volumevault-destination-'.$operationId;
    }

    public function handle(string $operationId): bool
    {
        $name = self::name($operationId);
        try {
            app(RemoveDockerContainer::class)->handle($name);
            $result = app(DockerProcess::class)->run(['docker', 'container', 'inspect', $name], 30);

            return ! $result->successful() && preg_match('/no such (?:container|object)\s*:\s*'.preg_quote($name, '/').'(?:\s|$)/i', $result->combinedOutput()) === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
