<?php

namespace App\Services\Docker;

class DockerProcessResult
{
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0;
    }

    public function combinedOutput(): string
    {
        return trim(implode("\n", array_filter([$this->output, $this->errorOutput], fn (?string $value) => filled($value))));
    }
}
