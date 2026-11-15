<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class BackupArchiveFilename implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 255
            || in_array($value, ['.', '..'], true) || preg_match('/\A[A-Za-z]:/', $value) === 1
            || preg_match('/[\/\\\\\x00-\x1F\x7F%]/u', $value) !== 0) {
            $fail('The :attribute must be a safe archive basename of at most 255 bytes.');
        }
    }
}
