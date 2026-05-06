<?php

namespace App\Services\Logging;

use Illuminate\Database\Eloquent\Model;

class AppendRunLog
{
    public function handle(Model $run, ?string $message): void
    {
        if (! filled($message)) {
            return;
        }

        $existing = (string) ($run->logs ?? '');
        $run->forceFill([
            'logs' => trim($existing."\n".trim($message)),
        ])->save();
    }
}
