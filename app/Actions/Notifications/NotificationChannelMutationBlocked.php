<?php

namespace App\Actions\Notifications;

use RuntimeException;

class NotificationChannelMutationBlocked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This notification channel still has finished notifications pending. Wait for them to finish before changing or deleting it.');
    }
}
