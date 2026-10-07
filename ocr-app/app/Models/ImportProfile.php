<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How to read one attendance source's CSV or Excel export (spec 3.3, 3.7).
 * column_map: system field => column name, or column number (1 = first).
 */
class ImportProfile extends Model
{
    /** System fields a profile can map (spec 3.7). work_date is required. */
    public const FIELDS = [
        'employee_name', 'employee_code', 'work_date', 'work_type', 'start_time', 'end_time',
        'break_minutes', 'worked_minutes', 'overtime_minutes', 'late_night_minutes',
        'legal_holiday_minutes', 'legal_holiday_late_night_minutes', 'memo', 'confirmed',
    ];

    protected $fillable = ['name', 'encoding', 'delimiter', 'header_row', 'column_map', 'date_format', 'time_format'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'header_row' => 'integer'];
    }

    public static function default(): self
    {
        return self::firstWhere('name', 'kintai-standard') ?? self::query()->firstOrFail();
    }
}
