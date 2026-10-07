<?php

namespace App\Attendance\Support;

use Normalizer;

/**
 * Attendance values (spec 3.4, 3.7): clock times, durations as minutes, day counts and dates.
 */
final class TimeValue
{
    /** "9:00", "09:00" or "900" → "09:00"; empty → null. */
    public static function clock(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === '' || ! preg_match('/^(\d{1,2}):?(\d{2})$/', $value, $m)) {
            return null;
        }
        [$hours, $minutes] = [(int) $m[1], (int) $m[2]];

        return $hours < 24 && $minutes < 60 ? sprintf('%02d:%02d', $hours, $minutes) : null;
    }

    /** "1:30" → 90, "168:00" → 10080 (hours can exceed 24), "1.5" → 90; empty → 0; unreadable → null. */
    public static function minutes(?string $value): ?int
    {
        $value = self::clean($value);
        if ($value === '' || $value === '-') {
            return 0;
        }
        if (preg_match('/^(\d+):(\d{2})$/', $value, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (is_numeric($value)) {
            return (int) round((float) $value * 60);
        }

        return null;
    }

    /** "0.0日" → 0.0, "21" → 21.0; empty → 0.0; unreadable → null. */
    public static function days(?string $value): ?float
    {
        $value = preg_replace('/日$/u', '', self::clean($value));

        return $value === '' ? 0.0 : (is_numeric($value) ? (float) $value : null);
    }

    /** "10/1(木)" with the year of the period, "2026/10/01", "2026-10-01" or "2026年10月1日" → "2026-10-01". */
    public static function date(?string $value, ?int $year): ?string
    {
        $value = self::clean($value);
        if (preg_match('/^(\d{4})[\/\-年](\d{1,2})[\/\-月](\d{1,2})日?/u', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif ($year !== null && preg_match('/^(\d{1,2})\/(\d{1,2})(?:\(.\))?$/u', $value, $m)) {
            [$y, $mo, $d] = [$year, (int) $m[1], (int) $m[2]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** The weekday printed in "10/1(木)" or "1 木", or null. */
    public static function printedWeekday(?string $value): ?string
    {
        return preg_match('/\((月|火|水|木|金|土|日)\)|\s(月|火|水|木|金|土|日)$/u', self::clean($value), $m) ? ($m[1] ?: $m[2]) : null;
    }

    public static function format(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    private static function clean(?string $value): string
    {
        return trim(Normalizer::normalize((string) $value, Normalizer::FORM_KC) ?: (string) $value);
    }
}
