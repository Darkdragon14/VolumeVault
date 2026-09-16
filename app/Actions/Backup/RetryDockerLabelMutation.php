<?php

namespace App\Actions\Backup;

use RuntimeException;

class RetryDockerLabelMutation extends RuntimeException
{
    // The mutation will be retried with references reloaded from the database.
}
