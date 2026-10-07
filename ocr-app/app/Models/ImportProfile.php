<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An attendance template (spec 3.3 import_profiles, extended): how to read one
 * company's format. kind: spreadsheet (CSV / Excel) or pdf. settings: columns
 * (free per format), header row, cells for year / month / name / summary,
 * units and work-type words (see App\Attendance\Template). match: header
 * titles and anchor texts that identify files of this format. Older profiles
 * only have column_map (system field => column title or number).
 */
class ImportProfile extends Model
{
    /** System fields a profile can map (spec 3.7). work_date is required. */
    public const FIELDS = [
        'employee_name', 'employee_code', 'work_date', 'work_type', 'start_time', 'end_time',
        'break_minutes', 'worked_minutes', 'overtime_minutes', 'late_night_minutes',
        'legal_holiday_minutes', 'legal_holiday_late_night_minutes', 'memo', 'confirmed',
    ];

    protected $fillable = ['name', 'kind', 'encoding', 'delimiter', 'header_row', 'column_map', 'date_format', 'time_format', 'settings', 'match'];

    protected $attributes = ['kind' => 'spreadsheet'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'settings' => 'array', 'match' => 'array', 'header_row' => 'integer'];
    }

    /**
     * @return HasMany<AttendanceSheet, $this>
     */
    public function attendanceSheets(): HasMany
    {
        return $this->hasMany(AttendanceSheet::class);
    }

    public static function default(): self
    {
        return self::firstWhere('name', 'kintai-standard') ?? self::query()->firstOrFail();
    }
}
