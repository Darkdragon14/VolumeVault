<?php

namespace Tests\Unit;

use App\Models\BackupJob;
use App\Services\Scheduling\BackupScheduleCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BackupScheduleCalculatorTest extends TestCase
{
    public function test_hourly_every_six_hours(): void
    {
        $calculator = app(BackupScheduleCalculator::class);
        $from = CarbonImmutable::parse('2026-05-01 01:10:00', 'UTC');

        $this->assertSame('0 */6 * * *', $calculator->cronExpression(BackupJob::SCHEDULE_HOURLY, ['everyHours' => 6]));
        $this->assertSame('Every 6 hours', $calculator->summary(BackupJob::SCHEDULE_HOURLY, ['everyHours' => 6]));
        $this->assertSame('2026-05-01 06:00:00', $calculator->nextRunAt(BackupJob::SCHEDULE_HOURLY, ['everyHours' => 6], $from)->format('Y-m-d H:i:s'));
    }

    public function test_daily_at_two(): void
    {
        $calculator = app(BackupScheduleCalculator::class);
        $from = CarbonImmutable::parse('2026-05-01 01:10:00', 'UTC');

        $this->assertSame('0 2 * * *', $calculator->cronExpression(BackupJob::SCHEDULE_DAILY, ['time' => '02:00']));
        $this->assertSame('Every day at 02:00', $calculator->summary(BackupJob::SCHEDULE_DAILY, ['time' => '02:00']));
        $this->assertSame('2026-05-01 02:00:00', $calculator->nextRunAt(BackupJob::SCHEDULE_DAILY, ['time' => '02:00'], $from)->format('Y-m-d H:i:s'));
    }

    public function test_daily_schedule_uses_application_timezone(): void
    {
        Config::set('app.timezone', 'Europe/Paris');

        $calculator = app(BackupScheduleCalculator::class);
        $from = CarbonImmutable::parse('2026-05-01 01:10:00', 'Europe/Paris');
        $next = $calculator->nextRunAt(BackupJob::SCHEDULE_DAILY, ['time' => '02:00'], $from);

        $this->assertSame('Europe/Paris', $next->timezoneName);
        $this->assertSame('2026-05-01 02:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_weekly_sunday_at_three(): void
    {
        $calculator = app(BackupScheduleCalculator::class);
        $from = CarbonImmutable::parse('2026-05-01 01:10:00', 'UTC');

        $this->assertSame('0 3 * * 0', $calculator->cronExpression(BackupJob::SCHEDULE_WEEKLY, ['dayOfWeek' => 'sunday', 'time' => '03:00']));
        $this->assertSame('Every Sunday at 03:00', $calculator->summary(BackupJob::SCHEDULE_WEEKLY, ['dayOfWeek' => 'sunday', 'time' => '03:00']));
        $this->assertSame('2026-05-03 03:00:00', $calculator->nextRunAt(BackupJob::SCHEDULE_WEEKLY, ['dayOfWeek' => 'sunday', 'time' => '03:00'], $from)->format('Y-m-d H:i:s'));
    }

    public function test_cron_expression_validation_and_next_run(): void
    {
        $calculator = app(BackupScheduleCalculator::class);
        $from = CarbonImmutable::parse('2026-05-01 01:10:00', 'UTC');

        $this->assertSame('0 2 * * *', $calculator->cronExpression(BackupJob::SCHEDULE_CRON, ['expression' => '0 2 * * *']));
        $this->assertSame('Cron: 0 2 * * *', $calculator->summary(BackupJob::SCHEDULE_CRON, ['expression' => '0 2 * * *']));
        $this->assertSame('2026-05-01 02:00:00', $calculator->nextRunAt(BackupJob::SCHEDULE_CRON, ['expression' => '0 2 * * *'], $from)->format('Y-m-d H:i:s'));
    }
}
