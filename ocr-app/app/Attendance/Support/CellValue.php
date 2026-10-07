<?php

namespace App\Attendance\Support;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Converts one cell to the column's type. Excel stores times as fractions of a
 * day (0.333 = 8:00, 1.0 = 24:00) and dates as serial numbers; files also use
 * "9:00" text and decimal hours (8.25 = 8 h 15 min).
 */
final class CellValue
{
    /** Clock time "HH:MM"; 24:00 (end at midnight) is "00:00" and reads as overnight. */
    public static function time(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if ($value < 0 || $value > 2) {
                return null;
            }
            $minutes = (int) round(fmod((float) $value, 1.0) * 1440) % 1440;

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        $text = trim((string) $value);
        if ($text === '24:00') {
            return '00:00';
        }

        return TimeValue::clock($text);
    }

    /**
     * Minutes. unit: auto (Excel time format or "h:mm" text; plain numbers are hours),
     * hours (decimal hours), minutes.
     */
    public static function duration(mixed $value, string $unit = 'auto', string $format = 'General'): ?int
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return match (true) {
                $unit === 'minutes' => (int) round($value),
                $unit === 'hours' => (int) round($value * 60),
                (bool) preg_match('/h|\[h\]/i', $format) => (int) round($value * 1440),
                default => (int) round($value * 60),
            };
        }

        $text = trim((string) $value);
        if ($unit === 'hours' && is_numeric($text)) {
            return (int) round((float) $text * 60);
        }
        if ($unit === 'minutes' && is_numeric($text)) {
            return (int) round((float) $text);
        }

        return TimeValue::minutes($text);
    }

    public static function date(mixed $value, ?int $year): ?string
    {
        if ((is_int($value) || is_float($value)) && $value > 20000 && $value < 80000) {
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        }

        return TimeValue::date(Grid::text($value), $year);
    }

    /** Day of the month, 1–31, or null. */
    public static function day(mixed $value): ?int
    {
        $text = Grid::text($value);

        return preg_match('/^(\d{1,2})日?$/u', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 31 ? (int) $m[1] : null;
    }

    public static function number(mixed $value): ?float
    {
        $text = preg_replace('/[日時間,\s]/u', '', Grid::text($value));

        return is_numeric($text) ? (float) $text : null;
    }

    public static function mark(mixed $value): bool
    {
        return in_array(Grid::text($value), ['✓', '✔', '済', '〇', '○', '◯', '1', 'true', 'TRUE', 'yes', 'Y'], true);
    }

    /** A value for display and storage in attendance_records.values. */
    public static function normalized(mixed $value, string $type, string $unit = 'auto', string $format = 'General', ?int $year = null): mixed
    {
        return match ($type) {
            'time' => self::time($value),
            'duration' => self::duration($value, $unit, $format),
            'date' => self::date($value, $year),
            'day' => self::day($value),
            'number' => self::number($value),
            'mark' => self::mark($value),
            default => ($text = Grid::text($value)) === '' ? null : $text,
        };
    }
}
