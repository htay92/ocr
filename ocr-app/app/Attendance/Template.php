<?php

namespace App\Attendance;

use App\Models\ImportProfile;

/**
 * How to read one company's attendance format. Columns are free per format:
 * each has the file's own title and a type; a column may also carry a role
 * (a standard field such as start_time or worked_minutes) that feeds the
 * A-01..A-08 checks and the standard totals. A column the format does not
 * have simply does not exist.
 */
final class Template
{
    /** Column types. */
    public const TYPES = ['date', 'day', 'text', 'time', 'duration', 'number', 'work_type', 'mark'];

    /** Standard fields a column can stand for (spec 3.7). */
    public const ROLES = [
        'work_date', 'work_day', 'work_type', 'start_time', 'end_time', 'break_minutes', 'worked_minutes',
        'overtime_minutes', 'late_night_minutes', 'legal_holiday_minutes', 'legal_holiday_late_night_minutes',
        'memo', 'confirmed', 'employee_name', 'employee_code',
    ];

    /** Standard summary items a summary cell or label can stand for. */
    public const SUMMARY_ROLES = [
        'scheduled_minutes', 'worked_minutes', 'overtime_minutes', 'late_night_minutes', 'legal_holiday_minutes',
        'legal_holiday_late_night_minutes', 'scheduled_days', 'worked_days', 'paid_leave_taken_days', 'paid_leave_remaining_days',
    ];

    /** The type that fits each role. */
    public const ROLE_TYPES = [
        'work_date' => 'date', 'work_day' => 'day', 'work_type' => 'work_type', 'start_time' => 'time', 'end_time' => 'time',
        'break_minutes' => 'duration', 'worked_minutes' => 'duration', 'overtime_minutes' => 'duration',
        'late_night_minutes' => 'duration', 'legal_holiday_minutes' => 'duration', 'legal_holiday_late_night_minutes' => 'duration',
        'memo' => 'text', 'confirmed' => 'mark', 'employee_name' => 'text', 'employee_code' => 'text',
    ];

    /**
     * @param  list<array{key: string, title: string, type: string, role: ?string, letter?: ?string, unit?: string}>  $columns
     * @param  list<array{title: string, cell?: ?string, type: string, unit?: string, role: ?string}>  $summary
     * @param  array<string, ?string>  $workTypes  value in the file => standard work type, or null for "no work type"
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $columns,
        public readonly int $headerRow = 1,
        public readonly string $sheet = 'first',
        public readonly ?string $yearCell = null,
        public readonly ?string $monthCell = null,
        public readonly string $nameSource = 'column',
        public readonly ?string $nameCell = null,
        public readonly ?string $codeCell = null,
        public readonly array $workTypes = [],
        public readonly ?int $breakDefault = null,
        public readonly array $summary = [],
        public readonly ?string $totalsLabel = '合計',
        public readonly ?string $titleMarker = null,
        public readonly string $encoding = 'auto',
        public readonly string $delimiter = ',',
        public readonly ?int $id = null,
        public readonly string $name = '',
    ) {}

    public static function fromProfile(ImportProfile $profile): self
    {
        $settings = $profile->settings ?? [];
        $columns = $settings['columns'] ?? self::columnsFromMap($profile->column_map ?? []);

        return new self(
            kind: $profile->kind ?? 'spreadsheet',
            columns: self::normalizeColumns($columns),
            headerRow: (int) ($settings['header_row'] ?? $profile->header_row ?? 1),
            sheet: $settings['sheet'] ?? 'first',
            yearCell: $settings['year_cell'] ?? null,
            monthCell: $settings['month_cell'] ?? null,
            nameSource: $settings['name_source'] ?? 'column',
            nameCell: $settings['name_cell'] ?? null,
            codeCell: $settings['code_cell'] ?? null,
            workTypes: $settings['work_types'] ?? [],
            breakDefault: isset($settings['break_default']) ? (int) $settings['break_default'] : null,
            summary: $settings['summary'] ?? [],
            totalsLabel: array_key_exists('totals_label', $settings) ? $settings['totals_label'] : '合計',
            titleMarker: $settings['title_marker'] ?? null,
            encoding: $profile->encoding ?: 'auto',
            delimiter: $profile->delimiter ?: ',',
            id: $profile->id,
            name: (string) $profile->name,
        );
    }

    /** The column that holds a role, if the format has one. */
    public function column(string $role): ?array
    {
        foreach ($this->columns as $column) {
            if (($column['role'] ?? null) === $role) {
                return $column;
            }
        }

        return null;
    }

    public function has(string $role): bool
    {
        return $this->column($role) !== null;
    }

    /** Columns shown in the month table: all but the date / day number, which is shown as the date. */
    public function displayColumns(): array
    {
        return array_values(array_filter($this->columns, fn ($c) => ! in_array($c['role'] ?? null, ['work_date', 'work_day', 'employee_name', 'employee_code'], true)));
    }

    /**
     * Columns from a legacy column_map (system field => column title or number).
     */
    public static function columnsFromMap(array $map): array
    {
        $titles = self::STANDARD_TITLES;
        $columns = [];
        foreach ($map as $role => $wanted) {
            if ($wanted === null || $wanted === '') {
                continue;
            }
            $isNumber = is_int($wanted) || ctype_digit((string) $wanted);
            $columns[] = [
                'key' => $role,
                'title' => $isNumber ? ($titles[$role] ?? $role) : (string) $wanted,
                'letter' => $isNumber ? self::letter((int) $wanted - 1) : null,
                'type' => self::ROLE_TYPES[$role] ?? 'text',
                'role' => $role,
            ];
        }

        return $columns;
    }

    /** Spec 3.7 titles of the standard fields. */
    public const STANDARD_TITLES = [
        'employee_name' => '氏名', 'employee_code' => '社員番号', 'work_date' => '日付', 'work_type' => '勤務区分',
        'start_time' => '開始', 'end_time' => '終了', 'break_minutes' => '休憩時間', 'worked_minutes' => '労働時間',
        'overtime_minutes' => '時間外', 'late_night_minutes' => '深夜', 'legal_holiday_minutes' => '法定休日',
        'legal_holiday_late_night_minutes' => '法定休日深夜', 'memo' => 'メモ', 'confirmed' => '確定',
    ];

    /**
     * The 勤務データ PDF layout (spec 3.4): summary blocks, daily table, 合計 row.
     */
    public static function kintaiPdfSettings(): array
    {
        $columns = [];
        foreach (['work_date', 'work_type', 'start_time', 'end_time', 'break_minutes', 'worked_minutes', 'overtime_minutes',
            'late_night_minutes', 'legal_holiday_minutes', 'legal_holiday_late_night_minutes', 'memo', 'confirmed'] as $role) {
            $columns[] = ['key' => $role, 'title' => self::STANDARD_TITLES[$role], 'type' => self::ROLE_TYPES[$role], 'role' => $role];
        }

        $summary = [];
        foreach (['所定時間' => 'scheduled_minutes', '労働時間' => 'worked_minutes', '時間外' => 'overtime_minutes', '深夜' => 'late_night_minutes',
            '法定休日' => 'legal_holiday_minutes', '法定休日深夜' => 'legal_holiday_late_night_minutes'] as $label => $role) {
            $summary[] = ['title' => $label, 'type' => 'duration', 'role' => $role];
        }
        foreach (['所定出勤日' => 'scheduled_days', '出勤日数' => 'worked_days', '有休取得日数' => 'paid_leave_taken_days', '有休残日数' => 'paid_leave_remaining_days'] as $label => $role) {
            $summary[] = ['title' => $label, 'type' => 'days', 'role' => $role];
        }
        foreach (['出勤', '欠勤', '有給休暇', '午前半休', '午後半休', '遅刻', '早退', '代休', '夏季休暇'] as $type) {
            $summary[] = ['title' => $type, 'type' => 'number', 'role' => "count:{$type}"];
        }

        return ['title_marker' => '【勤務データ】', 'columns' => $columns, 'summary' => $summary, 'totals_label' => '合計'];
    }

    public static function letter(int $index): string
    {
        $letter = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letter = chr(65 + ($index - 1) % 26).$letter;
        }

        return $letter;
    }

    private static function normalizeColumns(array $columns): array
    {
        return array_values(array_map(fn (array $c, int $i) => [
            'key' => (string) ($c['key'] ?? ($c['role'] ?? 'c'.($i + 1))),
            'title' => (string) ($c['title'] ?? ''),
            'letter' => $c['letter'] ?? null,
            'type' => in_array($c['type'] ?? null, self::TYPES, true) ? $c['type'] : (self::ROLE_TYPES[$c['role'] ?? ''] ?? 'text'),
            'role' => in_array($c['role'] ?? null, self::ROLES, true) ? $c['role'] : null,
            'unit' => $c['unit'] ?? 'auto',
        ], $columns, array_keys($columns)));
    }
}
