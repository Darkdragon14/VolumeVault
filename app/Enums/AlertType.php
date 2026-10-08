<?php

namespace App\Enums;

enum AlertType: string
{
    case BackupTooOld = 'backup_too_old';
    case JobNeverSucceeded = 'job_never_succeeded';
    case JobInErrorTooLong = 'job_in_error_too_long';
    case BackupSizeOutOfRange = 'backup_size_out_of_range';
    case DestinationStorageLimit = 'destination_storage_limit';
    case AgentOffline = 'agent_offline';
}
