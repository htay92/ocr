<?php

namespace App\Attendance\Support;

use DateTimeImmutable;
use Yasumi\Provider\AbstractProvider;
use Yasumi\Yasumi;

/**
 * Day types from the date and Japanese public holidays (spec 3.4 step 6).
 * Never from row colours, which are not in the text layer.
 */
final class HolidayCalendar
{
    public const WEEKDAY = 'weekday';

    public const SATURDAY = 'saturday';

    public const HOLIDAY = 'holiday';

    /** @var array<int, AbstractProvider> */
    private static array $years = [];

    public static function dayType(string $date): string
    {
        $day = new DateTimeImmutable($date);

        return match (true) {
            self::holidayName($date) !== null, $day->format('N') === '7' => self::HOLIDAY,
            $day->format('N') === '6' => self::SATURDAY,
            default => self::WEEKDAY,
        };
    }

    /** Japanese name of the public holiday on this date, or null. */
    public static function holidayName(string $date): ?string
    {
        $day = new DateTimeImmutable($date);
        $year = (int) $day->format('Y');
        self::$years[$year] ??= Yasumi::create('Japan', $year, 'ja_JP');

        foreach (self::$years[$year]->getHolidays() as $holiday) {
            if ($holiday->format('Y-m-d') === $day->format('Y-m-d')) {
                return $holiday->getName(['ja_JP', 'ja']);
            }
        }

        return null;
    }

    /** Weekdays in the period that are not public holidays (A-08). */
    public static function workingDays(string $from, string $to): int
    {
        $count = 0;
        for ($day = new DateTimeImmutable($from); $day <= new DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            $count += self::dayType($day->format('Y-m-d')) === self::WEEKDAY ? 1 : 0;
        }

        return $count;
    }
}
