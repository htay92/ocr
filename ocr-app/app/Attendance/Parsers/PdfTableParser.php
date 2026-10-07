<?php

namespace App\Attendance\Parsers;

use App\Attendance\Data\ParsedRecord;
use App\Attendance\Data\ParsedSheet;
use App\Attendance\Support\CellValue;
use App\Attendance\Support\TimeValue;
use App\Attendance\Template;
use App\Processing\ProcessingException;
use Illuminate\Support\Facades\Process;

/**
 * Attendance PDFs with a text layer (spec 3.4 "Attendance PDF parsing"), read
 * from words and their positions. The template gives the labels: the title
 * marker before the employee's name, the table header words (the format's own
 * columns), the summary labels and the totals label. The built-in template is
 * the 勤務データ layout.
 */
class PdfTableParser
{
    private const DAY_ROW = '/^\d{1,2}\/\d{1,2}(\((月|火|水|木|金|土|日)\))?$|^\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}$|^\d{1,2}日?$/u';

    private const DATES = '/(\d{4})\s?[年\/\-.]\s?(\d{1,2})\s?[月\/\-.]\s?(\d{1,2})日?/u';

    private readonly Template $template;

    public function __construct(?Template $template = null)
    {
        $this->template = $template ?? new Template('pdf', ...self::builtIn());
    }

    /**
     * @throws ProcessingException
     */
    public function parse(string $path): ParsedSheet
    {
        return $this->parseWords(self::words($path));
    }

    /**
     * Words of the first page: [text, xMin, yMin, xMax, yMax].
     *
     * @return list<array{0: string, 1: float, 2: float, 3: float, 4: float}>
     */
    public static function words(string $path): array
    {
        $result = Process::run(['pdftotext', '-bbox-layout', '-f', '1', '-l', '1', $path, '-']);
        if ($result->failed()) {
            throw new ProcessingException('E-PDF-01', 'PDF cannot be opened: '.trim($result->errorOutput()));
        }
        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<\/word>/u', $result->output(), $m, PREG_SET_ORDER);

        return array_map(fn ($w) => [html_entity_decode($w[5], ENT_QUOTES | ENT_XML1), (float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4]], $m);
    }

    /**
     * Words grouped into lines (same baseline), left to right.
     *
     * @return list<list<array>>
     */
    public static function lines(array $words): array
    {
        usort($words, fn ($a, $b) => [$a[2], $a[1]] <=> [$b[2], $b[1]]);
        $lines = [];
        foreach ($words as $word) {
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last][0][2] - $word[2]) <= 2.0) {
                $lines[$last][] = $word;
            } else {
                $lines[] = [$word];
            }
        }

        return array_map(function (array $line) {
            usort($line, fn ($a, $b) => $a[1] <=> $b[1]);

            return $line;
        }, $lines);
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float, 3: float, 4: float}>  $words
     *
     * @throws ProcessingException when the title or the table header is not found
     */
    public function parseWords(array $words): ParsedSheet
    {
        $t = $this->template;
        $lines = self::lines($words);

        // Title: the line with the marker → employee name (text after the marker, up to "_" or the first date) and period.
        $title = collect($lines)->map(fn ($line) => implode(' ', array_column($line, 0)))
            ->first(fn ($text) => $t->titleMarker !== null && $t->titleMarker !== '' && str_contains($text, $t->titleMarker));
        if ($title === null || preg_match_all(self::DATES, $title, $dates, PREG_SET_ORDER) < 2) {
            throw new ProcessingException('E-IMP-02', __('The title ":marker" with the employee name and period was not found.', ['marker' => $t->titleMarker]));
        }
        $start = sprintf('%04d-%02d-%02d', $dates[0][1], $dates[0][2], $dates[0][3]);
        $end = sprintf('%04d-%02d-%02d', $dates[1][1], $dates[1][2], $dates[1][3]);
        $year = (int) $dates[0][1];
        $afterMarker = substr($title, strpos($title, $t->titleMarker) + strlen($t->titleMarker));
        $name = trim(preg_replace('/\s+/u', ' ', preg_split('/_|'.trim(self::DATES, '/u').'/u', $afterMarker)[0]));

        // Table header: the line holding most of the template's column titles.
        $titles = array_column($t->columns, 'title');
        $header = null;
        $best = 1;
        foreach ($lines as $i => $line) {
            $hits = count(array_intersect(array_column($line, 0), $titles));
            if ($hits > $best) {
                [$header, $best] = [$i, $hits];
            }
        }
        if ($header === null) {
            throw new ProcessingException('E-IMP-02', __('The table header (:titles) was not found.', ['titles' => implode(', ', array_slice($titles, 0, 4))]));
        }
        $totalsLine = $t->totalsLabel ? self::findLine($lines, fn ($texts) => in_array($t->totalsLabel, $texts, true), $header) : null;

        // Summary blocks above the table: label row → value row.
        $summary = [];
        $summaryValues = [];
        $counts = [];
        foreach ($this->labelValuePairs(array_slice($lines, 0, $header), array_column($t->summary, 'title')) as $label => $raw) {
            $item = collect($t->summary)->firstWhere('title', $label);
            $role = $item['role'] ?? null;
            $value = ($item['type'] ?? 'number') === 'duration' ? TimeValue::minutes($raw) : TimeValue::days($raw);
            $summaryValues[] = ['title' => $label, 'value' => $value, 'type' => $item['type'] ?? 'number', 'role' => $role];
            match (true) {
                $role === null || $value === null => null,
                str_starts_with($role, 'count:') => $counts[substr($role, 6)] = (int) $value,
                $role === 'scheduled_days' => $summary[$role] = (int) $value,
                default => $summary[$role] = $value,
            };
        }
        if ($counts !== []) {
            $summary['work_type_counts'] = array_merge(array_fill_keys(ParsedSheet::WORK_TYPES, 0), $counts);
        }

        // Daily rows: words go to the column whose x-range holds their centre.
        $columns = $this->columns($lines[$header]);
        $records = [];
        $warnings = [];
        $last = $totalsLine ?? count($lines);
        $dateColumn = $t->column('work_date') ?? $t->column('work_day');
        for ($i = $header + 1; $i < $last; $i++) {
            $cells = [];
            foreach ($lines[$i] as [$text, $x1, , $x2]) {
                $key = self::columnAt($columns, ($x1 + $x2) / 2);
                $cells[$key] = isset($cells[$key]) ? $cells[$key].' '.$text : $text;
            }
            $dateText = $cells[$dateColumn['key'] ?? ''] ?? '';
            if (! preg_match(self::DAY_ROW, $dateText)) {
                continue;
            }
            $date = ($dateColumn['role'] ?? null) === 'work_day' && ($day = CellValue::day($dateText)) !== null
                ? sprintf('%s-%02d', substr($start, 0, 7), $day)
                : TimeValue::date($dateText, $year);
            if ($date === null) {
                $warnings[] = __('Row :row: the date :value cannot be read.', ['row' => count($records) + 1, 'value' => $dateText]);

                continue;
            }
            $records[] = $this->record($date, $cells, count($records) + 1, $dateText);
        }

        // The totals row, kept only for A-05.
        $totals = null;
        if ($totalsLine !== null) {
            $totals = [];
            $durationTitles = collect($t->summary)->where('type', 'duration')->pluck('role', 'title')->all()
                + collect($t->columns)->where('type', 'duration')->pluck('role', 'title')->all();
            foreach ($this->labelValuePairs(array_slice($lines, $totalsLine), array_merge(array_keys($durationTitles), ['休憩時間'])) as $label => $raw) {
                $role = $durationTitles[$label] ?? ($label === '休憩時間' ? 'break_minutes' : null);
                if ($role !== null) {
                    $totals[str_replace('_minutes', '', $role)] = TimeValue::minutes($raw) ?? 0;
                }
            }
        }

        return new ParsedSheet(
            employeeName: $name,
            employeeCode: null,
            periodStart: $start,
            periodEnd: $end,
            summary: $summary,
            records: $records,
            totals: $totals,
            warnings: $warnings,
            columns: array_map(fn ($c) => array_intersect_key($c, array_flip(['key', 'title', 'type', 'role', 'unit'])), $t->columns),
            summaryValues: $summaryValues,
            needsName: $name === '',
        );
    }

    private function record(string $date, array $cells, int $row, string $dateText): ParsedRecord
    {
        $values = [];
        foreach ($this->template->columns as $column) {
            $raw = $cells[$column['key']] ?? null;
            $values[$column['key']] = $column['type'] === 'work_type'
                ? (($raw ?? '') === '' ? null : $raw)
                : CellValue::normalized($raw, $column['type'], $column['unit'] ?? 'auto');
        }
        $get = function (string $role) use ($values) {
            $column = $this->template->column($role);

            return $column === null ? null : $values[$column['key']];
        };
        $minutes = fn (string $role) => $this->template->has($role) ? ($get($role) ?? 0) : null;

        $word = $get('work_type');
        $workType = $word === null ? null : (array_key_exists($word, $this->template->workTypes) ? $this->template->workTypes[$word] : $word);

        return new ParsedRecord(
            date: $date,
            workType: $workType,
            start: $get('start_time'),
            end: $get('end_time'),
            breakMinutes: $minutes('break_minutes'),
            workedMinutes: $minutes('worked_minutes'),
            overtimeMinutes: $minutes('overtime_minutes'),
            lateNightMinutes: $minutes('late_night_minutes'),
            legalHolidayMinutes: $minutes('legal_holiday_minutes'),
            legalHolidayLateNightMinutes: $minutes('legal_holiday_late_night_minutes'),
            memo: $get('memo'),
            confirmed: (bool) $get('confirmed'),
            sourceRow: $row,
            printedWeekday: TimeValue::printedWeekday($dateText),
            values: $values,
        );
    }

    private static function findLine(array $lines, callable $test, int $after = -1): ?int
    {
        foreach ($lines as $i => $line) {
            if ($i > $after && $test(array_column($line, 0))) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Label rows followed by value rows: each value belongs to the label with the
     * nearest x-position above it.
     *
     * @param  list<string>  $known
     * @return array<string, string> label => value
     */
    private function labelValuePairs(array $lines, array $known): array
    {
        $totals = $this->template->totalsLabel;
        $pairs = [];
        foreach ($lines as $i => $line) {
            $words = array_values(array_filter($line, fn ($w) => $w[0] !== $totals));
            $labels = array_values(array_filter($words, fn ($w) => in_array($w[0], $known, true)));
            if ($labels === [] || count($labels) !== count($words) || ! isset($lines[$i + 1])) {
                continue;
            }
            foreach ($lines[$i + 1] as [$value, $x]) {
                $nearest = collect($labels)->sortBy(fn ($label) => abs($label[1] - $x))->first();
                if ($nearest !== null && abs($nearest[1] - $x) < 30) {
                    $pairs[$nearest[0]] = $value;
                }
            }
        }

        return $pairs;
    }

    /**
     * Column x-ranges from the header row: boundaries halfway between title centres.
     *
     * @return list<array{0: string, 1: float}> [column key, right boundary]
     */
    private function columns(array $header): array
    {
        $byTitle = collect($this->template->columns)->keyBy('title');
        $centres = [];
        foreach ($header as [$text, $x1, , $x2]) {
            if ($byTitle->has($text)) {
                $centres[] = [$byTitle[$text]['key'], ($x1 + $x2) / 2];
            }
        }
        $columns = [];
        foreach ($centres as $i => [$key, $centre]) {
            $columns[] = [$key, isset($centres[$i + 1]) ? ($centre + $centres[$i + 1][1]) / 2 : INF];
        }

        return $columns;
    }

    private static function columnAt(array $columns, float $x): string
    {
        foreach ($columns as [$key, $right]) {
            if ($x < $right) {
                return $key;
            }
        }

        return end($columns)[0];
    }

    /** Named arguments for the built-in 勤務データ template. */
    private static function builtIn(): array
    {
        $settings = Template::kintaiPdfSettings();

        return [
            'columns' => array_map(fn ($c) => $c + ['letter' => null, 'unit' => 'auto'], $settings['columns']),
            'summary' => $settings['summary'],
            'totalsLabel' => $settings['totals_label'],
            'titleMarker' => $settings['title_marker'],
        ];
    }
}
