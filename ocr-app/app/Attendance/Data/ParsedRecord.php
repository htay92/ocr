<?php

namespace App\Attendance\Data;

use App\Attendance\Support\HolidayCalendar;

/**
 * One day as read from a file, with its day type computed from the date.
 */
final class ParsedRecord
{
    public readonly string $dayType;

    public readonly ?string $holidayName;

    public function __construct(
        public readonly string $date,
        public readonly ?string $workType = null,
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly int $breakMinutes = 0,
        public readonly int $workedMinutes = 0,
        public readonly int $overtimeMinutes = 0,
        public readonly int $lateNightMinutes = 0,
        public readonly int $legalHolidayMinutes = 0,
        public readonly int $legalHolidayLateNightMinutes = 0,
        public readonly ?string $memo = null,
        public readonly bool $confirmed = false,
        public readonly ?int $sourceRow = null,
        public readonly ?string $printedWeekday = null,
    ) {
        $this->dayType = HolidayCalendar::dayType($date);
        $this->holidayName = HolidayCalendar::holidayName($date);
    }

    /** Clock-out earlier than clock-in means past midnight (spec 3.4). */
    public function overnight(): bool
    {
        return $this->start !== null && $this->end !== null && $this->end < $this->start;
    }

    public function minutes(string $kind): int
    {
        return match ($kind) {
            'worked' => $this->workedMinutes,
            'overtime' => $this->overtimeMinutes,
            'late_night' => $this->lateNightMinutes,
            'legal_holiday' => $this->legalHolidayMinutes,
            'legal_holiday_late_night' => $this->legalHolidayLateNightMinutes,
            'break' => $this->breakMinutes,
            default => 0,
        };
    }

    /** Attributes for attendance_records. */
    public function toArray(): array
    {
        return [
            'work_date' => $this->date, 'day_type' => $this->dayType, 'holiday_name' => $this->holidayName,
            'work_type' => $this->workType, 'start_time' => $this->start, 'end_time' => $this->end,
            'break_minutes' => $this->breakMinutes, 'worked_minutes' => $this->workedMinutes,
            'overtime_minutes' => $this->overtimeMinutes, 'late_night_minutes' => $this->lateNightMinutes,
            'legal_holiday_minutes' => $this->legalHolidayMinutes, 'legal_holiday_late_night_minutes' => $this->legalHolidayLateNightMinutes,
            'memo' => $this->memo, 'confirmed' => $this->confirmed, 'source_row' => $this->sourceRow,
        ];
    }
}
