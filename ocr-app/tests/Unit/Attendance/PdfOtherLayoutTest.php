<?php

use App\Attendance\Parsers\PdfTableParser;
use App\Attendance\Template;
use App\Attendance\TemplateDesigner;
use App\Models\ImportProfile;

/**
 * A 勤務表 exported from a spreadsheet to PDF: no title marker, "2025 年 11 月度" title,
 * 名前 in a block above the table, days "1)" with the weekday in the date column,
 * hours "8.0)" (accounting format) and times a few points above the other words of the row.
 */
function sheetPdfWords(): array
{
    $words = [];
    $word = function (string $text, float $x, float $y, float $width = 16, float $height = 9.7) use (&$words) {
        $words[] = [$text, $x, $y, $x + $width, $y + $height];
    };
    foreach ([['2025', 75.7], ['年', 109.7], ['11', 142.7], ['月度', 177.2], ['勤務表', 208.6], ['兼', 253.7], ['作業報告書', 271.5]] as [$text, $x]) {
        $word($text, $x, 60.6, 30, 13.7);
    }
    foreach ([['名前', 77.4, 'テイ'], ['出勤日数（日）', 141.9, '3'], ['休日日数（日）', 233.4, '2'], ['稼働時間（時間）', 318.1, '24.3)'], ['精算時間（時間）', 400.0, '～']] as [$label, $x, $value]) {
        $word($label, $x, 100.5, 50);
        $word($value, $x + 5, 117.0);
    }
    foreach ([['就業日', 72.9], ['区分', 140.4], ['始業時間', 177.2], ['終業時間', 222.2], ['稼働時間', 267.1], ['備考', 312.1]] as [$title, $x]) {
        $word($title, $x, 154.5, $title === '区分' ? 19.5 : 39);
    }
    $weekdays = ['土', '日', '月', '火', '水', '木', '金'];
    for ($day = 1; $day <= 30; $day++) {
        $y = 172.5 + ($day - 1) * 18;
        $weekday = $weekdays[($day - 1) % 7];
        $work = in_array($weekday, ['土', '日'], true) ? '休日' : '稼働';
        $word("{$day})", 96.6, $y, 8);
        $word($weekday, 111.9, $y, 10);
        $word($work, 140.4, $y, 19.5);
        if ($work === '稼働' && $day <= 5) {                                 // three working days: 3, 4, 5
            $word('10:00', 190.1, $y - 3, 27, 10.1);
            $word($day === 4 ? '19:15' : '19:00', 235.1, $y - 3, 27, 10.1);
            $word($day === 4 ? '8.3)' : '8.0)', 292.2, $y, 15);
        }
    }

    return $words;
}

it('suggests a template for a PDF without a title marker', function () {
    $analysis = app(TemplateDesigner::class)->analysePdfWords(sheetPdfWords());

    expect(array_column($analysis['settings']['columns'], 'role', 'title'))->toBe([
        '就業日' => 'work_day', '区分' => 'work_type', '始業時間' => 'start_time', '終業時間' => 'end_time', '稼働時間' => 'worked_minutes', '備考' => 'memo',
    ])
        ->and($analysis['settings']['work_types'])->toBe(['休日' => null, '稼働' => '出勤'])
        ->and(collect($analysis['settings']['summary'])->pluck('type', 'title')->all())
        ->toBe(['出勤日数（日）' => 'days', '休日日数（日）' => 'days', '稼働時間（時間）' => 'duration'])
        ->and($analysis['anchors'])->toBe(['作業報告書']);
});

it('reads the PDF with that template: month from the title, name from 名前, rows with offset times', function () {
    $analysis = app(TemplateDesigner::class)->analysePdfWords(sheetPdfWords());
    $profile = app(TemplateDesigner::class)->fromForm([...$analysis['settings'], 'name' => 'x', 'break_default' => '1:00'], 'pdf', $analysis['anchors']);

    $sheet = (new PdfTableParser(Template::fromProfile($profile)))->parseWords(sheetPdfWords());

    expect($sheet->employeeName)->toBe('テイ')
        ->and([$sheet->periodStart, $sheet->periodEnd])->toBe(['2025-11-01', '2025-11-30'])
        ->and($sheet->records)->toHaveCount(30)
        ->and($sheet->summary['worked_minutes'])->toBe(1458)                 // 24.3 h
        ->and($sheet->summary['worked_days'])->toBe(3.0);

    $day4 = $sheet->records[3];
    expect([$day4->date, $day4->printedWeekday, $day4->workType, $day4->start, $day4->end, $day4->breakMinutes, $day4->workedMinutes])
        ->toBe(['2025-11-04', '火', '出勤', '10:00', '19:15', 60, 498])
        ->and($sheet->records[0]->workType)->toBeNull();                    // 休日
});

it('finds the template again for the next PDF of this format', function () {
    $analysis = app(TemplateDesigner::class)->analysePdfWords(sheetPdfWords());
    $profile = app(TemplateDesigner::class)->fromForm([...$analysis['settings'], 'name' => '作業報告書 PDF'], 'pdf', $analysis['anchors']);

    expect($profile->match['anchors'])->toBe(['作業報告書'])
        ->and(collect($profile->settings['columns'])->contains('role', 'work_day'))->toBeTrue();
    ImportProfile::create($profile->only(['name', 'kind', 'header_row', 'settings', 'match']));
    expect(ImportProfile::where('name', '作業報告書 PDF')->exists())->toBeTrue();
});
