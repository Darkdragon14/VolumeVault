<?php

namespace App\Services\Scheduling;

use Cron\CronExpression;

class CronExpressionValidator
{
    public function valid(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }
}
