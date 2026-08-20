<?php

namespace App\Support;

class RunHeartbeatLock
{
    public static function backup(int $runId): string
    {
        return 'backup-run-heartbeat-'.$runId;
    }

    public static function restore(int $runId): string
    {
        return 'restore-run-heartbeat-'.$runId;
    }
}
