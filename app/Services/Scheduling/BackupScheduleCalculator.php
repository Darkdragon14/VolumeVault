<?php

namespace App\Services\Scheduling;

use App\Models\BackupJob;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use InvalidArgumentException;

class BackupScheduleCalculator
{
    private const DAYS = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];

    public function normalize(string $type, array $config): array
    {
        return match ($type) {
            BackupJob::SCHEDULE_HOURLY => ['everyHours' => $this->hourInterval($config['everyHours'] ?? 1)],
            BackupJob::SCHEDULE_DAILY => ['time' => $this->time($config['time'] ?? '02:00')],
            BackupJob::SCHEDULE_WEEKLY => [
                'dayOfWeek' => $this->dayOfWeek($config['dayOfWeek'] ?? 'sunday'),
                'time' => $this->time($config['time'] ?? '03:00'),
            ],
            BackupJob::SCHEDULE_CRON => ['expression' => $this->cron($config['expression'] ?? '')],
            default => throw new InvalidArgumentException('Unsupported schedule type.'),
        };
    }

    public function cronExpression(string $type, array $config): string
    {
        $config = $this->normalize($type, $config);

        return match ($type) {
            BackupJob::SCHEDULE_HOURLY => $config['everyHours'] === 1 ? '0 * * * *' : '0 */'.$config['everyHours'].' * * *',
            BackupJob::SCHEDULE_DAILY => $this->timeToCron($config['time']).' * * *',
            BackupJob::SCHEDULE_WEEKLY => $this->timeToCron($config['time']).' * * '.self::DAYS[$config['dayOfWeek']],
            BackupJob::SCHEDULE_CRON => $config['expression'],
        };
    }

    public function nextRunAt(string $type, array $config, ?CarbonInterface $from = null): CarbonInterface
    {
        $timezone = config('app.timezone');
        $expression = new CronExpression($this->cronExpression($type, $config));
        $current = ($from ?: now($timezone))->setTimezone($timezone)->toDateTimeImmutable();
        $next = $expression->getNextRunDate($current, 0, false, $timezone);

        return now($timezone)->setTimestamp($next->getTimestamp())->setTimezone($timezone);
    }

    public function summary(string $type, array $config): string
    {
        $config = $this->normalize($type, $config);

        return match ($type) {
            BackupJob::SCHEDULE_HOURLY => 'Every '.$config['everyHours'].' hour'.($config['everyHours'] === 1 ? '' : 's'),
            BackupJob::SCHEDULE_DAILY => 'Every day at '.$config['time'],
            BackupJob::SCHEDULE_WEEKLY => 'Every '.ucfirst($config['dayOfWeek']).' at '.$config['time'],
            BackupJob::SCHEDULE_CRON => 'Cron: '.$config['expression'],
        };
    }

    private function hourInterval(mixed $value): int
    {
        $hours = (int) $value;

        if ($hours < 1 || $hours > 24) {
            throw new InvalidArgumentException('Hourly schedule must be between 1 and 24 hours.');
        }

        return $hours;
    }

    private function time(mixed $value): string
    {
        $time = is_string($value) ? $value : '';

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidArgumentException('Time must use HH:mm format.');
        }

        return $time;
    }

    private function dayOfWeek(mixed $value): string
    {
        $day = strtolower((string) $value);

        if (! array_key_exists($day, self::DAYS)) {
            throw new InvalidArgumentException('Invalid day of week.');
        }

        return $day;
    }

    private function cron(mixed $value): string
    {
        $expression = trim((string) $value);

        if (! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException('Invalid cron expression.');
        }

        return $expression;
    }

    private function timeToCron(string $time): string
    {
        [$hour, $minute] = explode(':', $time);

        return ((int) $minute).' '.((int) $hour);
    }
}
