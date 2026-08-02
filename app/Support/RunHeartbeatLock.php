<?php

namespace App\Support;

class RunHeartbeatLock
{
    public static function backup(int $runId): string
    {
        return 'backup-run-heartbeat-'.$runId;
    }
}
