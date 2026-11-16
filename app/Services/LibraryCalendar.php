<?php

namespace App\Services;

use Carbon\CarbonInterface;

final class LibraryCalendar
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public const OVERDUE_DAILY_RATE = 3000;

    public static function daysOverdue(CarbonInterface $due, CarbonInterface $processedAt): int
    {
        if ($processedAt->lessThanOrEqualTo($due)) {
            return 0;
        }

        $dueDay = $due->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $processedDay = $processedAt->copy()->setTimezone(self::TIMEZONE)->startOfDay();

        return max(1, (int) $dueDay->diffInDays($processedDay));
    }
}
