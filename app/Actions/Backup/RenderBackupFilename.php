<?php

namespace App\Actions\Backup;

use App\Models\BackupJob;
use App\Models\BackupRun;
use Illuminate\Support\Carbon;

class RenderBackupFilename
{
    public const DEFAULT_TEMPLATE = 'volumevault-{source}-run-{id}';

    /** @var list<string> */
    public const ALLOWED_TOKENS = ['name', 'source', 'id', 'run', 'year', 'month', 'day', 'time', 'hour', 'minute', 'second'];

    public function handle(BackupRun $run): string
    {
        $run->loadMissing('job');

        $template = trim((string) $run->job->backup_filename_template);

        if ($template === '') {
            return 'volumevault-'.$this->safeSource($run->job).'-run-'.$run->id.'.tar.gz';
        }

        return $this->renderTemplate($template, $run).'.tar.gz';
    }

    public function validationError(?string $template): ?string
    {
        $template = trim((string) $template);

        if ($template === '') {
            return null;
        }

        if (str_contains($template, '/') || str_contains($template, '\\') || str_contains($template, '..')) {
            return 'Archive name templates cannot contain path separators or parent directory segments.';
        }

        if (preg_match('/[\x00-\x1F\x7F%]/', $template) === 1) {
            return 'Archive name templates cannot contain control characters or percent signs.';
        }

        if ($this->containsArchiveExtension($template)) {
            return 'Archive name templates should not include an extension; .tar.gz is added automatically.';
        }

        preg_match_all('/\{([^}]+)\}/', $template, $matches);

        foreach ($matches[1] as $token) {
            if (! in_array($token, self::ALLOWED_TOKENS, true)) {
                return 'Unknown archive name token: {'.$token.'}.';
            }
        }

        $withoutTokens = preg_replace('/\{[^}]+\}/', '', $template) ?? '';

        if (str_contains($withoutTokens, '{') || str_contains($withoutTokens, '}')) {
            return 'Archive name templates contain an invalid token.';
        }

        return null;
    }

    public function preview(?string $template, BackupJob $job, ?int $runId = null, ?Carbon $time = null): string
    {
        $run = new BackupRun;
        $run->forceFill([
            'id' => $runId ?? 123,
            'started_at' => $time ?? now(),
        ]);
        $run->setRelation('job', $job);

        $template = trim((string) $template);

        return $this->renderTemplate($template === '' ? self::DEFAULT_TEMPLATE : $template, $run).'.tar.gz';
    }

    private function renderTemplate(string $template, BackupRun $run): string
    {
        $time = $this->filenameTime($run);
        $job = $run->job;

        return strtr($template, [
            '{name}' => $this->safePart((string) $job->name),
            '{source}' => $this->safeSource($job),
            '{id}' => (string) $run->id,
            '{run}' => (string) $run->id,
            '{year}' => $time->format('Y'),
            '{month}' => $time->format('m'),
            '{day}' => $time->format('d'),
            '{time}' => $time->format('H-i-s'),
            '{hour}' => $time->format('H'),
            '{minute}' => $time->format('i'),
            '{second}' => $time->format('s'),
        ]);
    }

    private function filenameTime(BackupRun $run): Carbon
    {
        $time = $run->started_at ?? $run->created_at ?? now();
        $timezone = $run->job->timezone ?: config('app.timezone');

        return $time->copy()->timezone($timezone);
    }

    private function safeSource(BackupJob $job): string
    {
        $sourceName = $job->isHostPathSource()
            ? trim($job->sourceName(), '/')
            : $job->sourceName();

        return $this->safePart($sourceName ?: 'source');
    }

    private function safePart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value) ?: 'backup';
    }

    private function containsArchiveExtension(string $template): bool
    {
        return preg_match('/\.(tar|tar\.gz|tgz|tar\.zst|gz|zst)(\.(gpg|age))?$/i', $template) === 1;
    }
}
