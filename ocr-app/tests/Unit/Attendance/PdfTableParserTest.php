<?php

use App\Attendance\Parsers\PdfTableParser;
use App\Processing\ProcessingException;

it('reads Tei\'s real 勤務データ PDF (an empty month)', function () {
    $sheet = (new PdfTableParser)->parse(base_path('tests/fixtures/attendance/kintai-2026-10-sample.pdf'));

    expect($sheet->employeeName)->toBe('Htay Hlaing Kyaw')
        ->and([$sheet->periodStart, $sheet->periodEnd])->toBe(['2026-10-01', '2026-10-31'])
        ->and($sheet->records)->toHaveCount(31)
        ->and($sheet->records[11]->date)->toBe('2026-10-12')
        ->and($sheet->records[11]->holidayName)->toBe('スポーツの日')
        ->and($sheet->summary)->toMatchArray([
            'scheduled_minutes' => 10080, 'worked_minutes' => 0, 'scheduled_days' => 21,
            'worked_days' => 0.0, 'paid_leave_taken_days' => 0.0, 'paid_leave_remaining_days' => 26.0,
        ])
        ->and($sheet->summary['work_type_counts'])->toMatchArray(['出勤' => 0, '有給休暇' => 0, '夏季休暇' => 0])
        ->and($sheet->fromFile('paid_leave_remaining_days'))->toBeTrue()
        ->and($sheet->totals)->toMatchArray(['scheduled' => 10080, 'worked' => 0, 'break' => 0]);
})->skip(fn () => ! hasPoppler() || ! is_file(base_path('tests/fixtures/attendance/kintai-2026-10-sample.pdf')), 'needs poppler and the sample PDF');

it('reads a filled month from word positions', function () {
    $sheet = (new PdfTableParser)->parseWords(kintaiPdfWords());

    $first = $sheet->records[0];
    expect($sheet->employeeName)->toBe('山田 花子')
        ->and($sheet->records)->toHaveCount(31)
        ->and([$first->workType, $first->start, $first->end, $first->breakMinutes, $first->workedMinutes])->toBe(['出勤', '09:00', '18:00', 60, 480])
        ->and($sheet->records[13]->lateNightMinutes)->toBe(30)                       // 10/14 until 22:30
        ->and($sheet->records[19]->workType)->toBe('有給休暇')                        // 10/20
        ->and($sheet->records[0]->memo)->toBe('定例 会議')
        ->and($sheet->records[0]->confirmed)->toBeTrue()
        ->and($sheet->summary)->toMatchArray(['worked_minutes' => 9720, 'overtime_minutes' => 360, 'worked_days' => 19.5, 'paid_leave_taken_days' => 1.5])
        ->and($sheet->totals)->toMatchArray(['worked' => 9720, 'overtime' => 360, 'late_night' => 30]);
});

it('rejects a PDF that is not a 勤務データ sheet', function () {
    (new PdfTableParser)->parseWords([['請求書', 50, 30, 90, 38]]);
})->throws(ProcessingException::class);
