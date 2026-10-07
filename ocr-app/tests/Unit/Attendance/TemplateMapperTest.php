<?php

use App\Attendance\Data\ParsedSheet;
use App\Attendance\Parsers\SpreadsheetReader;
use App\Attendance\Parsers\TemplateMapper;
use App\Attendance\Template;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use App\Validation\AttendanceValidator;

function readWith(ImportProfile $profile, string $path, string $fileName, ?string $name = null): array
{
    $template = Template::fromProfile($profile);

    return (new TemplateMapper)->map((new SpreadsheetReader)->read($path, $template, $fileName), $template, $fileName, $name);
}

function levels(ParsedSheet $sheet): array
{
    return collect((new AttendanceValidator)->validate($sheet))->pluck('level', 'code')->all();
}

it('reads the 出勤簿 layout: day numbers, Excel times, an overnight shift and saved formula results', function () {
    $result = readWith(shukkinboTemplate(), shukkinboXlsx(), '20260126_出勤簿_自動計算.xlsx');
    $sheet = $result['sheets'][0];

    expect($result['needs_name'])->toBeTrue()                                  // I4 is empty: asked on import
        ->and([$sheet->periodStart, $sheet->periodEnd])->toBe(['2026-01-01', '2026-01-31'])
        ->and($sheet->records)->toHaveCount(31)                                // notes and 合計 rows skipped
        ->and($sheet->records[0]->holidayName)->toBe('元日')
        ->and($sheet->records[1]->toArray())->toMatchArray([
            'start_time' => '10:00', 'end_time' => '00:00', 'break_minutes' => 60, 'worked_minutes' => 780,
            'overtime_minutes' => 300, 'late_night_minutes' => 120, 'work_type' => '出勤',
        ])
        ->and($sheet->records[1]->overnight())->toBeTrue()
        ->and($sheet->records[5]->workType)->toBe('有給休暇')                     // 有給 → 有給休暇
        ->and($sheet->records[0]->workType)->toBeNull()                         // "-" → no work type
        ->and($sheet->summary)->toMatchArray(['worked_days' => 3.0, 'paid_leave_taken_days' => 1.0, 'worked_minutes' => 1740])
        ->and($sheet->totals)->toMatchArray(['worked' => 1740, 'overtime' => 300, 'late_night' => 120])
        ->and(array_column($sheet->columns, 'title'))->toBe(['日', '出勤', '退勤', '休憩', '実働', '時間外', '深夜', '休日', '区分'])
        ->and(levels($sheet))->toMatchArray(['A-01' => 'pass', 'A-02' => 'pass', 'A-05' => 'pass', 'A-06' => 'pass', 'A-07' => 'pass']);

    $named = readWith(ImportProfile::find(ImportProfile::max('id')), shukkinboXlsx(), '出勤簿.xlsx', '山田 花子');
    expect($named['needs_name'])->toBeFalse()->and($named['sheets'][0]->employeeName)->toBe('山田 花子');
});

it('reads the 作業報告書 layout with its own columns only: decimal hours, 稼働/休日, no break column', function () {
    $sheet = readWith(sagyouTemplate(), sagyouHoukokushoXlsx(), '作業報告書_田中(11月).xlsx')['sheets'][0];
    $day12 = $sheet->records[11];

    expect($sheet->employeeName)->toBe('田中')
        ->and([$sheet->periodStart, $sheet->periodEnd])->toBe(['2025-11-01', '2025-11-30'])
        ->and($sheet->records)->toHaveCount(30)
        ->and($sheet->records[2]->holidayName)->toBe('文化の日')
        ->and($day12->toArray())->toMatchArray(['start_time' => '10:00', 'end_time' => '19:15', 'worked_minutes' => 495, 'work_type' => '出勤', 'memo' => '客先作業'])
        // Columns the format does not have stay empty, not 0.
        ->and([$day12->overtimeMinutes, $day12->lateNightMinutes, $day12->legalHolidayMinutes])->toBe([null, null, null])
        ->and($day12->breakMinutes)->toBe(60)                                   // the template's fixed break
        ->and($day12->values)->toMatchArray(['c3' => '稼働', 'c6' => 495, 'c8' => 'システム開発'])  // the file's own word; a free column is kept
        ->and(array_column($sheet->columns, 'title'))->toBe(['就業日', '区分', '始業時間', '終業時間', '稼働時間', '備考', '作業内容'])
        ->and($sheet->summary)->toMatchArray(['worked_days' => 18.0, 'worked_minutes' => 8655, 'overtime_minutes' => null])
        ->and(array_column($sheet->summaryValues, 'value', 'title'))->toMatchArray(['出勤日数（日）' => 18.0, '休日日数（日）' => 12.0, '稼働時間（時間）' => 8655])
        ->and(levels($sheet))->toMatchArray(['A-01' => 'pass', 'A-02' => 'pass', 'A-05' => 'pass', 'A-06' => 'pass', 'A-07' => 'pass']);
});

it('reads the values Excel saved, not a recalculation', function () {
    // G3 / G4 are formulas; their saved results are used.
    $sheet = readWith(shukkinboTemplate(), shukkinboXlsx(), 'x.xlsx', 'x')['sheets'][0];

    expect($sheet->fromFile('worked_days'))->toBeTrue()
        ->and($sheet->summary['worked_days'])->toBe(3.0);
});

it('falls back to the sheet or file name for the year and month, else asks to set them', function () {
    $profile = sagyouTemplate();
    $profile->update(['settings' => [...$profile->settings, 'year_cell' => 'Z1', 'month_cell' => 'Z2']]);

    // The sheet is named "田中202511".
    expect(readWith($profile, sagyouHoukokushoXlsx(), 'report.xlsx')['sheets'][0]->periodStart)->toBe('2025-11-01');

    $csv = kintaiCsv([['日', '区分'], ['1', '出勤'], ['2', '出勤']]);
    $profile->update(['settings' => [...$profile->settings, 'header_row' => 1, 'columns' => [
        ['key' => 'd', 'title' => '日', 'type' => 'day', 'role' => 'work_day'],
        ['key' => 't', 'title' => '区分', 'type' => 'work_type', 'role' => 'work_type'],
    ]]]);
    expect(fn () => readWith($profile->fresh(), $csv, 'attendance.csv'))
        ->toThrow(ProcessingException::class, 'year and month');
});
