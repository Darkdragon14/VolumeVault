<?php

namespace App\Services\InstallationSaves;

readonly class GeneratedInstallationSave
{
    public function __construct(
        public string $path,
        public string $filename,
        public int $size,
    ) {}
}
