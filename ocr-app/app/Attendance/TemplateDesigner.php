<?php

namespace App\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Attendance\Parsers\PdfTableParser;
use App\Attendance\Parsers\SpreadsheetReader;
use App\Attendance\Support\CellValue;
use App\Attendance\Support\Grid;
use App\Attendance\Support\TimeValue;
use App\Models\ImportProfile;
use Normalizer;

/**
 * The template setting page's helper: reads a sample file of a new format and
 * suggests a template (header row, the format's own columns with a meaning,
 * type and unit, year / month / name / summary cells, work-type words), and
 * turns the submitted form back into a template.
 */
class TemplateDesigner
{
    /** Title words → the standard field a column most likely stands for. */
    private const SYNONYMS = [
        'work_date' => ['日付', '年月日', '勤務日付'],
        'work_day' => ['日', '就業日', '勤務日', '出勤日'],
        'start_time' => ['開始', '出勤', '始業', '始業時間', '始業時刻', '出勤時刻', '出勤時間', '出社', '開始時刻'],
        'end_time' => ['終了', '退勤', '終業', '終業時間', '終業時刻', '退勤時刻', '退勤時間', '退社', '終了時刻'],
        'break_minutes' => ['休憩', '休憩時間'],
        'worked_minutes' => ['実働', '労働時間', '稼働時間', '実働時間', '勤務時間', '就業時間'],
        'overtime_minutes' => ['時間外', '残業', '残業時間', '時間外労働'],
        'late_night_minutes' => ['深夜', '深夜時間', '深夜労働'],
        'legal_holiday_minutes' => ['休日', '法定休日', '休日出勤', '休日労働'],
        'legal_holiday_late_night_minutes' => ['法定休日深夜', '休日深夜'],
        'work_type' => ['区分', '勤務区分', '勤怠区分', '出勤区分', '種別'],
        'memo' => ['備考', 'メモ', '摘要'],
        'confirmed' => ['確定', '承認', '確認'],
        'employee_name' => ['氏名', '名前', '社員名', '従業員名'],
        'employee_code' => ['社員番号', '社員コード', '従業員番号'],
    ];

    /** Summary labels → standard summary item. */
    private const SUMMARY_SYNONYMS = [
        'worked_days' => ['出勤日', '出勤日数'],
        'paid_leave_taken_days' => ['有給日', '有給日数', '有休取得日数', '有給取得日数'],
        'paid_leave_remaining_days' => ['有休残日数', '有給残日数'],
        'scheduled_days' => ['所定出勤日', '所定日数'],
        'worked_minutes' => ['稼働時間', '労働時間', '実働時間', '実働'],
        'overtime_minutes' => ['時間外', '残業時間'],
        'scheduled_minutes' => ['所定時間'],
    ];

    /** The format's work-type words → standard types (null = no work type, e.g. a day off). */
    private const WORK_TYPE_WORDS = [
        '出勤' => '出勤', '稼働' => '出勤', '出' => '出勤', '出社' => '出勤', '在宅' => '出勤', 'テレワーク' => '出勤',
        '有給' => '有給休暇', '有休' => '有給休暇', '有給休暇' => '有給休暇', '年休' => '有給休暇',
        '欠勤' => '欠勤', '欠' => '欠勤', '遅刻' => '遅刻', '早退' => '早退', '代休' => '代休', '夏休' => '夏季休暇', '夏季休暇' => '夏季休暇',
        '午前半休' => '午前半休', '午後半休' => '午後半休', 'AM半休' => '午前半休', 'PM半休' => '午後半休',
        '休日' => null, '休' => null, '公休' => null, '-' => null, '－' => null, '休み' => null,
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public static function isPdf(string $fileName): bool
    {
        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * A sample file's structure and the suggested template settings.
     *
     * @return array{kind: string, grid?: Grid, lines?: list<string>, header_row?: int, settings: array, anchors: list<string>, pairs: list<array>, work_type_values: list<string>}
     */
    public function analyse(string $path, string $fileName, ?int $headerRow = null): array
    {
        return self::isPdf($fileName) ? $this->analysePdf($path) : $this->analyseSpreadsheet($path, $fileName, $headerRow);
    }

    private function analyseSpreadsheet(string $path, string $fileName, ?int $headerRow): array
    {
        $grid = $this->reader->read($path, new Template('spreadsheet', []), $fileName);
        $headerRow ??= $this->guessHeaderRow($grid);
        $header = $grid->row($headerRow);

        $columns = [];
        foreach ($header as $letter => $title) {
            $title = Grid::text($title);
            if ($title === '') {
                continue;
            }
            $samples = $this->samples($grid, $letter, $headerRow);
            $role = $this->guessRole($title, $samples);
            $type = $role !== null ? Template::ROLE_TYPES[$role] : $this->guessType($samples, $grid, $letter, $headerRow);
            $columns[] = [
                'key' => 'c'.(Grid::index($letter) + 1),
                'letter' => $letter,
                'title' => $title,
                'type' => $type,
                'role' => $role,
                'unit' => $type === 'duration' ? $this->guessUnit($grid, $letter, $headerRow) : 'auto',
                'include' => ! in_array(self::clean($title), ['曜日', '曜'], true),
                'samples' => array_values(array_map(fn ($row) => self::display($samples[$row], $grid->format($letter.$row)), array_slice(array_keys($samples), 0, 3))),
            ];
        }

        $pairs = $this->pairs($grid, $headerRow);
        [$yearCell, $monthCell] = $this->yearMonthCells($grid, $pairs, $headerRow);
        $nameCell = collect($pairs)->first(fn ($p) => in_array(self::clean($p['label']), self::SYNONYMS['employee_name'], true))['cell'] ?? null;
        $hasNameColumn = collect($columns)->contains('role', 'employee_name');

        $summary = [];
        foreach ($pairs as $pair) {
            if (in_array($pair['cell'], [$yearCell, $monthCell, $nameCell], true) || ! is_numeric($pair['value'])) {
                continue;
            }
            $role = $this->summaryRole($pair['label']);
            $isTime = (bool) preg_match('/h/i', $grid->format($pair['cell']));
            $type = str_ends_with($role ?? '', '_minutes') || $isTime ? 'duration' : 'days';
            $summary[] = [
                'title' => $pair['label'], 'cell' => $pair['cell'], 'type' => $type,
                'unit' => $type === 'duration' && ! $isTime ? 'hours' : 'auto', 'role' => $role, 'include' => true, 'value' => $pair['value'],
            ];
        }

        $workTypeColumn = collect($columns)->firstWhere('role', 'work_type');
        $workTypeValues = $workTypeColumn ? $this->distinct($grid, $workTypeColumn['letter'], $headerRow) : [];

        return [
            'kind' => 'spreadsheet',
            'grid' => $grid,
            'header_row' => $headerRow,
            'pairs' => $pairs,
            'anchors' => $this->anchors($grid, $headerRow),
            'work_type_values' => $workTypeValues,
            'settings' => [
                'header_row' => $headerRow,
                'columns' => $columns,
                'year_cell' => $yearCell,
                'month_cell' => $monthCell,
                'name_source' => $hasNameColumn ? 'column' : ($nameCell ? 'cell' : 'ask'),
                'name_cell' => $nameCell,
                'work_types' => array_combine($workTypeValues, array_map(fn ($v) => $this->workTypeFor($v), $workTypeValues)) ?: [],
                'summary' => $summary,
                'totals_label' => $this->hasTotalsRow($grid, $headerRow) ? '合計' : null,
                'break_default' => null,
            ],
        ];
    }

    private function analysePdf(string $path): array
    {
        $lines = PdfTableParser::lines(PdfTableParser::words($path));
        $texts = array_map(fn ($line) => array_column($line, 0), $lines);

        $titleIndex = collect($texts)->search(fn ($words) => preg_match_all('/\d{4}\s?[年\/\-]\s?\d{1,2}/u', implode(' ', $words)) >= 2);
        $title = $titleIndex === false ? '' : implode(' ', $texts[$titleIndex]);
        $marker = preg_match('/^(.*?】)/u', $title, $m) ? $m[1] : (explode(' ', $title)[0] ?? '');

        $header = null;
        foreach ($texts as $i => $words) {
            // Day rows start with "10/1(木)" or "1日", or count 1, 2, 3.
            $firsts = array_map(fn ($w) => $w[0] ?? '', array_slice($texts, $i + 1, 5));
            $isDay = fn ($w) => (bool) preg_match('/^\d{1,2}\/\d{1,2}|^\d{1,2}日$/u', $w);
            $dayRows = isset($firsts[1]) && $isDay($firsts[0]) && $isDay($firsts[1]);   // directly under the header
            $counting = array_slice($firsts, 0, 3) === ['1', '2', '3'];
            if (count($words) >= 3 && ($dayRows || $counting)) {
                $header = $i;
                break;
            }
        }

        $columns = [];
        foreach ($header === null ? [] : $texts[$header] as $i => $title) {
            $role = $this->guessRole($title, $i === 0 ? ['10/1(木)'] : []);
            $columns[] = ['key' => 'c'.($i + 1), 'title' => $title, 'letter' => null,
                'type' => $role ? Template::ROLE_TYPES[$role] : 'text', 'role' => $role, 'unit' => 'auto', 'include' => true, 'samples' => []];
        }

        $summary = [];
        foreach (array_slice($lines, 0, $header ?? 0) as $i => $line) {
            $next = $lines[$i + 1] ?? null;
            $words = array_column($line, 0);
            if ($next === null || array_filter($words, fn ($w) => preg_match('/\d/u', $w)) !== [] || $i === $titleIndex) {
                continue;
            }
            foreach ($line as [$label, $x]) {
                $value = collect($next)->sortBy(fn ($w) => abs($w[1] - $x))->first();
                if ($value === null || abs($value[1] - $x) > 30 || ! preg_match('/\d/u', $value[0])) {
                    continue;
                }
                $role = $this->summaryRole($label);
                $type = str_contains($value[0], ':') ? 'duration' : (str_contains($value[0], '日') ? 'days' : 'number');
                $summary[] = ['title' => $label, 'type' => $type, 'unit' => 'auto', 'role' => $role
                    ?? (in_array($label, ParsedSheet::WORK_TYPES, true) ? "count:{$label}" : null), 'include' => true, 'value' => $value[0]];
            }
        }

        $hasTotals = $header !== null && collect(array_slice($texts, $header))->contains(fn ($w) => in_array('合計', $w, true));

        return [
            'kind' => 'pdf',
            'lines' => array_map(fn ($w) => implode(' ', $w), $texts),
            'pairs' => [],
            'anchors' => array_values(array_filter([$marker])),
            'work_type_values' => [],
            'settings' => [
                'title_marker' => $marker,
                'columns' => $columns,
                'summary' => $summary,
                'totals_label' => $hasTotals ? '合計' : null,
                'work_types' => [],
            ],
        ];
    }

    /**
     * A template made from a file's own suggestions, ready to save (not saved),
     * for a file that no saved template fits. Null when no column holds the
     * date or the day of the month: then the format has to be set up by hand.
     */
    public function suggest(string $path, string $fileName): ?ImportProfile
    {
        $analysis = $this->analyse($path, $fileName);
        $profile = $this->fromForm([...$analysis['settings'], 'name' => self::uniqueName(self::suggestName($fileName))], $analysis['kind'], $analysis['anchors']);

        return collect($profile->settings['columns'])->contains(fn ($c) => in_array($c['role'], ['work_date', 'work_day'], true)) ? $profile : null;
    }

    /** A template name from the file name: "20260126_出勤簿_自動計算.xlsx" → "出勤簿". */
    public static function suggestName(string $fileName): string
    {
        $name = preg_replace('/^\d{6,8}_|[_（(].*$|【.*?】/u', '', pathinfo($fileName, PATHINFO_FILENAME));

        return trim($name) ?: pathinfo($fileName, PATHINFO_FILENAME);
    }

    /** "出勤簿", or "出勤簿 (2)" when that name is taken. */
    public static function uniqueName(string $name): string
    {
        $name = mb_substr($name, 0, 90);
        for ($candidate = $name, $n = 2; ImportProfile::where('name', $candidate)->exists(); $n++) {
            $candidate = "{$name} ({$n})";
        }

        return $candidate;
    }

    /**
     * The submitted form → template attributes (an ImportProfile, not saved yet).
     */
    public function fromForm(array $input, string $kind, array $anchors): ImportProfile
    {
        $columns = [];
        foreach ($input['columns'] ?? [] as $column) {
            if (empty($column['include'])) {
                continue;
            }
            $columns[] = [
                'key' => $column['key'],
                'title' => (string) $column['title'],
                'letter' => $column['letter'] ?? null,
                'type' => in_array($column['type'] ?? '', Template::TYPES, true) ? $column['type'] : 'text',
                'role' => ($column['role'] ?? '') !== '' ? $column['role'] : null,
                'unit' => $column['unit'] ?? 'auto',
            ];
        }
        $summary = [];
        foreach ($input['summary'] ?? [] as $item) {
            if (! empty($item['include'])) {
                $summary[] = array_intersect_key($item, array_flip(['title', 'cell', 'type', 'unit'])) + ['role' => ($item['role'] ?? '') !== '' ? $item['role'] : null];
            }
        }
        $workTypes = [];
        foreach ($input['work_types'] ?? [] as $value => $type) {
            $workTypes[(string) $value] = $type !== '' ? $type : null;
        }

        $settings = [
            'columns' => $columns,
            'summary' => $summary,
            'work_types' => $workTypes,
            'totals_label' => ($input['totals_label'] ?? '') !== '' ? $input['totals_label'] : null,
        ];
        if ($kind === 'pdf') {
            $settings['title_marker'] = (string) ($input['title_marker'] ?? '');
        } else {
            $settings += [
                'header_row' => (int) ($input['header_row'] ?? 1),
                'year_cell' => self::cell($input['year_cell'] ?? null),
                'month_cell' => self::cell($input['month_cell'] ?? null),
                'name_source' => $input['name_source'] ?? 'ask',
                'name_cell' => self::cell($input['name_cell'] ?? null),
                'break_default' => ($input['break_default'] ?? '') !== '' ? TimeValue::minutes($input['break_default']) : null,
            ];
        }

        return new ImportProfile([
            'name' => (string) ($input['name'] ?? ''),
            'kind' => $kind,
            'header_row' => (int) ($input['header_row'] ?? 1),
            'settings' => $settings,
            'match' => ['anchors' => $anchors, 'headers' => array_column($columns, 'title')],
        ]);
    }

    private function guessHeaderRow(Grid $grid): int
    {
        $best = 1;
        $bestScore = 0;
        foreach (array_keys($grid->rows) as $row) {
            if ($row > 20) {
                break;
            }
            $texts = array_filter($grid->row($row), fn ($v) => is_string($v) && ! is_numeric($v) && mb_strlen($v) <= 12 && ! str_contains($v, ':'));
            if (count($texts) < 3) {
                continue;
            }
            // A table header is followed by many rows with values in its columns;
            // a summary label row has one row of values below it.
            $filled = 0;
            for ($offset = 1; $offset <= 10; $offset++) {
                $filled += count(array_intersect_key($grid->row($row + $offset), $texts)) >= 2 ? 1 : 0;
            }
            $score = count($texts) + 2 * $filled;
            if ($score > $bestScore) {
                [$best, $bestScore] = [$row, $score];
            }
        }

        return $best;
    }

    /** Non-empty values below the header (up to 40 rows). */
    private function samples(Grid $grid, string $letter, int $headerRow): array
    {
        $values = [];
        for ($row = $headerRow + 1; $row <= $headerRow + 40; $row++) {
            $value = $grid->rows[$row][$letter] ?? null;
            if ($value !== null && Grid::text($value) !== '') {
                $values[$row] = $value;
            }
        }

        return $values;
    }

    private function guessRole(string $title, array $samples): ?string
    {
        $clean = self::clean($title);
        foreach (self::SYNONYMS as $role => $words) {
            if (in_array($clean, $words, true)) {
                // 日 / 勤務日 hold a day number; 日付 a full date.
                if ($role === 'work_day' && collect($samples)->contains(fn ($v) => CellValue::date($v, null) !== null && CellValue::day($v) === null)) {
                    return 'work_date';
                }

                return $role;
            }
        }

        return null;
    }

    private function guessType(array $samples, Grid $grid, string $letter, int $headerRow): string
    {
        $first = array_key_first($samples);
        if ($first === null) {
            return 'text';
        }
        $format = $grid->format($letter.$first);
        $value = $samples[$first];

        return match (true) {
            is_float($value) && (bool) preg_match('/h/i', $format) => $value >= 1 || str_contains($format, '[h]') ? 'duration' : 'time',
            is_string($value) && (bool) preg_match('/^\d{1,2}:\d{2}$/', $value) => 'time',
            is_numeric($value) => 'number',
            default => 'text',
        };
    }

    private function guessUnit(Grid $grid, string $letter, int $headerRow): string
    {
        foreach ($this->samples($grid, $letter, $headerRow) as $row => $value) {
            if (is_float($value) || is_int($value)) {
                return preg_match('/h/i', $grid->format($letter.$row)) ? 'auto' : 'hours';
            }
            if (is_string($value) && is_numeric($value)) {
                return 'hours';
            }
        }

        return 'auto';
    }

    /** A sample as the sheet shows it: Excel times as "8:00", durations over a day as "29:00". */
    private static function display(mixed $value, string $format): string
    {
        if ((is_float($value) || is_int($value)) && preg_match('/h/i', $format)) {
            return TimeValue::format((int) round($value * 1440));
        }

        return Grid::text($value);
    }

    /**
     * Label → value pairs above the table: a text cell with a value to its right
     * (within 3 columns) or directly below.
     *
     * @return list<array{label: string, cell: string, value: string}>
     */
    private function pairs(Grid $grid, int $headerRow): array
    {
        $pairs = [];
        $letters = $grid->letters();
        for ($row = 1; $row < $headerRow; $row++) {
            foreach ($grid->row($row) as $letter => $value) {
                if (! is_string($value) || is_numeric($value) || mb_strlen($value) > 15) {
                    continue;
                }
                $index = Grid::index($letter);
                $found = null;
                for ($i = $index + 1; $i <= $index + 3 && isset($letters[$i]); $i++) {
                    $right = $grid->rows[$row][$letters[$i]] ?? null;
                    if ($right !== null && Grid::text($right) !== '') {
                        $found = is_string($right) && ! is_numeric($right) && mb_strlen($right) > 1 && ! str_contains($right, ':') ? null : $letters[$i].$row;
                        break;
                    }
                }
                $found ??= ($below = $grid->rows[$row + 1][$letter] ?? null) !== null && $row + 1 < $headerRow ? $letter.($row + 1) : null;
                if ($found === null && in_array(self::clean($value), self::SYNONYMS['employee_name'], true)) {
                    $found = $letters[$index + 1].$row;   // an empty name cell next to 氏名
                }
                if ($found !== null) {
                    $pairs[] = ['label' => $value, 'cell' => $found, 'value' => Grid::text($grid->cell($found))];
                }
            }
        }

        return $pairs;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function yearMonthCells(Grid $grid, array $pairs, int $headerRow): array
    {
        $year = collect($pairs)->first(fn ($p) => self::clean($p['label']) === '年' && is_numeric($p['value']) && $p['value'] >= 2000 && $p['value'] <= 2100)['cell'] ?? null;
        $month = collect($pairs)->first(fn ($p) => self::clean($p['label']) === '月' && is_numeric($p['value']) && $p['value'] >= 1 && $p['value'] <= 12)['cell'] ?? null;

        // "2025 年 11 月度…": the year number, then a month number in the same row.
        if ($year === null || $month === null) {
            for ($row = 1; $row < $headerRow; $row++) {
                foreach ($grid->row($row) as $letter => $value) {
                    if (is_numeric($value) && $value >= 2000 && $value <= 2100) {
                        $year ??= $letter.$row;
                        foreach ($grid->row($row) as $other => $v) {
                            if (Grid::index($other) > Grid::index($letter) && is_numeric($v) && $v >= 1 && $v <= 12) {
                                $month ??= $other.$row;
                                break;
                            }
                        }
                    }
                }
            }
        }

        return [$year, $month];
    }

    private function summaryRole(string $label): ?string
    {
        $clean = preg_replace('/[（(].*?[）)]/u', '', self::clean($label));
        foreach (self::SUMMARY_SYNONYMS as $role => $words) {
            if (in_array($clean, $words, true)) {
                return $role;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function distinct(Grid $grid, string $letter, int $headerRow): array
    {
        $values = [];
        foreach ($this->samples($grid, $letter, $headerRow) as $value) {
            $text = Grid::text($value);
            if ($text !== '' && $text !== '合計') {
                $values[$text] = true;
            }
        }

        return array_keys($values);
    }

    private function workTypeFor(string $value): ?string
    {
        return array_key_exists($value, self::WORK_TYPE_WORDS) ? self::WORK_TYPE_WORDS[$value]
            : (in_array($value, ParsedSheet::WORK_TYPES, true) ? $value : null);
    }

    private function hasTotalsRow(Grid $grid, int $headerRow): bool
    {
        foreach ($grid->rows as $row => $cells) {
            if ($row > $headerRow && in_array('合計', array_map(fn ($v) => self::clean(Grid::text($v)), $cells), true)) {
                return true;
            }
        }

        return false;
    }

    /** Texts that identify this format: the longest text in the first rows (the sheet's title). */
    private function anchors(Grid $grid, int $headerRow): array
    {
        $texts = [];
        for ($row = 1; $row <= min(2, $headerRow - 1); $row++) {
            foreach ($grid->row($row) as $value) {
                if (is_string($value) && mb_strlen($value) >= 4 && ! is_numeric($value)) {
                    $texts[] = self::clean($value);
                }
            }
        }
        usort($texts, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($texts, 0, 1);
    }

    private static function cell(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{1,3}\d+$/', $value) ? $value : null;
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', '', Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value), "\u{FEFF}");
    }
}
