<?php

namespace App\Enums;

/**
 * Lifecycle event a notification is sent for. Webhook channels map each case to a
 * different stored URL (Healthchecks-style start/success/fail pinging); the other
 * services ignore it and always use their single Shoutrrr URL.
 */
enum NotificationEvent: string
{
    case Start = 'start';
    case Success = 'success';
    case Fail = 'fail';
}
