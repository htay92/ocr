<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee and period (spec 3.3, 3.7). Hours are stored as minutes; a
 * standard field is null when the format has no such column. columns is the
 * template's column list at import time (the format's own titles), summary_values
 * the file's own summary items.
 */
class AttendanceSheet extends Model
{
    public const MINUTE_FIELDS = ['scheduled', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'];

    protected $fillable = [
        'import_profile_id', 'employee_name', 'employee_code', 'period_start', 'period_end',
        'scheduled_minutes', 'worked_minutes', 'overtime_minutes', 'late_night_minutes',
        'legal_holiday_minutes', 'legal_holiday_late_night_minutes',
        'scheduled_days', 'worked_days', 'paid_leave_taken_days', 'paid_leave_remaining_days',
        'work_type_counts', 'summary_source', 'columns', 'summary_values',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'worked_days' => 'decimal:1',
            'paid_leave_taken_days' => 'decimal:1',
            'paid_leave_remaining_days' => 'decimal:1',
            'work_type_counts' => 'array',
            'summary_source' => 'array',
            'columns' => 'array',
            'summary_values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class)->orderBy('work_date');
    }

    /**
     * @return HasMany<ValidationResult, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ValidationResult::class)->orderBy('rule_code');
    }
}
