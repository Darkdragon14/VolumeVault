<?php

namespace App\Services\BackupDestinations;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SftpEndpointHost implements ValidationRule
{
    public static function isValid(mixed $host): bool
    {
        if (! is_string($host) || $host === '' || strlen($host) > 255) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_contains($host, ':')) {
            return false;
        }

        return preg_match('/\A[a-zA-Z0-9_](?:[a-zA-Z0-9_-]{0,61}[a-zA-Z0-9_])?(?:\.[a-zA-Z0-9_](?:[a-zA-Z0-9_-]{0,61}[a-zA-Z0-9_])?)*\.?\z/', $host) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('The :attribute must be a bare IP address or DNS/container hostname.');
        }
    }
}
