<?php

namespace App\Support;

use RuntimeException;

class DeploymentMode
{
    public static function mode(): string
    {
        $mode = (string) config('volumevault.mode', 'hybrid');
        if (! in_array($mode, ['hybrid', 'orchestrator'], true)) {
            throw new RuntimeException('VOLUMEVAULT_MODE must be hybrid or orchestrator.');
        }

        return $mode;
    }

    public static function isOrchestrator(): bool
    {
        return self::mode() === 'orchestrator';
    }

    public static function localExecutionEnabled(): bool
    {
        return ! self::isOrchestrator();
    }

    public static function assertLocalExecution(): void
    {
        if (! self::localExecutionEnabled()) {
            throw new RuntimeException('Local Docker execution is disabled in orchestrator-only mode.');
        }
    }
}
