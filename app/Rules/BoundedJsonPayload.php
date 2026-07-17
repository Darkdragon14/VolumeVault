<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BoundedJsonPayload implements ValidationRule
{
    public function __construct(
        private readonly int $maxDepth = 4,
        private readonly int $maxNodes = 500,
        private readonly int $maxKeyLength = 128,
        private readonly int $maxStringLength = 2048,
        private readonly int $maxBytes = 65536,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $encoded = json_encode($value);

        if ($encoded === false || strlen($encoded) > $this->maxBytes) {
            $fail("The {$attribute} field is too large.");

            return;
        }

        $nodes = 0;

        if (! $this->isValid($value, 1, $nodes)) {
            $fail("The {$attribute} field is too complex or contains oversized values.");
        }
    }

    private function isValid(mixed $value, int $depth, int &$nodes): bool
    {
        $nodes++;

        if ($nodes > $this->maxNodes || $depth > $this->maxDepth) {
            return false;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $this->maxStringLength;
        }

        if (! is_array($value)) {
            return is_null($value) || is_bool($value) || is_int($value) || is_float($value);
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && mb_strlen($key) > $this->maxKeyLength) {
                return false;
            }

            if (! $this->isValid($child, $depth + 1, $nodes)) {
                return false;
            }
        }

        return true;
    }
}
