<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One calendar day of an attendance sheet (spec 3.3).
 */
class AttendanceRecord extends Model
{
    protected $fillable = [
        'work_date', 'day_type', 'holiday_name', 'work_type', 'start_time', 'end_time',
        'break_minutes', 'worked_minutes', 'overtime_minutes', 'late_night_minutes',
        'legal_holiday_minutes', 'legal_holiday_late_night_minutes', 'memo', 'confirmed', 'source_row',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'confirmed' => 'boolean'];
    }
}
