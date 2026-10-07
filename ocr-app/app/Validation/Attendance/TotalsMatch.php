<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Attendance\Support\TimeValue;
use App\Validation\RuleResult;

/**
 * A-05: the sums of daily worked, overtime, late-night and legal-holiday minutes
 * equal the summary and the 合計 row (critical). Skipped when the file has neither.
 */
class TotalsMatch extends AttendanceRule
{
    private const LABELS = ['worked' => '労働時間', 'overtime' => '時間外', 'late_night' => '深夜', 'legal_holiday' => '法定休日', 'legal_holiday_late_night' => '法定休日深夜'];

    public function code(): string
    {
        return 'A-05';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        $compared = false;
        foreach (self::LABELS as $kind => $label) {
            $sum = $sheet->sum($kind);
            if ($sum === null) {
                continue;   // the format has no such column
            }
            foreach ([
                __('summary') => $sheet->fromFile("{$kind}_minutes") ? $sheet->summary["{$kind}_minutes"] : null,
                __('合計 row') => $sheet->totals[$kind] ?? null,
            ] as $where => $expected) {
                if ($expected === null) {
                    continue;
                }
                $compared = true;
                if ($expected !== $sum) {
                    return $this->fail(RuleResult::CRITICAL, __(':label: daily rows add up to :sum, the :where says :expected', [
                        'label' => $label, 'sum' => TimeValue::format($sum), 'where' => $where, 'expected' => TimeValue::format($expected),
                    ]));
                }
            }
        }

        return $compared
            ? $this->pass(__('Daily hours add up to the totals (worked :worked)', ['worked' => TimeValue::format($sheet->sum('worked'))]))
            : $this->skip(__('No totals in the file'));
    }
}
