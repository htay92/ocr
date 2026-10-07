<?php

use App\Attendance\AttendanceImporter;
use App\Attendance\TemplateDesigner;

it('suggests a template for the 出勤簿 layout', function () {
    $analysis = app(TemplateDesigner::class)->analyse(shukkinboXlsx(), '出勤簿.xlsx');
    $s = $analysis['settings'];
    $roles = array_column($s['columns'], 'role', 'title');

    expect($analysis['header_row'])->toBe(7)
        ->and($roles)->toMatchArray(['日' => 'work_day', '出勤' => 'start_time', '退勤' => 'end_time', '休憩' => 'break_minutes',
            '実働' => 'worked_minutes', '時間外' => 'overtime_minutes', '深夜' => 'late_night_minutes', '休日' => 'legal_holiday_minutes', '区分' => 'work_type'])
        ->and(collect($s['columns'])->firstWhere('title', '曜日')['include'])->toBeFalse()   // the date column already shows the weekday
        ->and(collect($s['columns'])->firstWhere('title', '出勤')['samples'][0])->toBe('10:00')
        ->and([$s['year_cell'], $s['month_cell']])->toBe(['B3', 'B4'])
        ->and([$s['name_source'], $s['name_cell']])->toBe(['cell', 'I4'])
        ->and($s['work_types'])->toBe(['-' => null, '出勤' => '出勤', '有給' => '有給休暇'])
        ->and(array_column($s['summary'], 'role', 'title'))->toMatchArray(['出勤日' => 'worked_days', '有給日' => 'paid_leave_taken_days'])
        ->and($s['totals_label'])->toBe('合計')
        ->and($analysis['anchors'])->toBe(['出勤簿(自動計算)']);
});

it('suggests a template for the 作業報告書 layout, with decimal hours and its own columns', function () {
    $analysis = app(TemplateDesigner::class)->analyse(sagyouHoukokushoXlsx(), '作業報告書.xlsx');
    $s = $analysis['settings'];
    $worked = collect($s['columns'])->firstWhere('title', '稼働時間');

    expect($analysis['header_row'])->toBe(6)
        ->and(array_column($s['columns'], 'role', 'title'))->toMatchArray(['就業日' => 'work_day', '区分' => 'work_type', '始業時間' => 'start_time',
            '終業時間' => 'end_time', '稼働時間' => 'worked_minutes', '備考' => 'memo', '作業内容' => null])
        ->and([$worked['type'], $worked['unit']])->toBe(['duration', 'hours'])
        ->and([$s['year_cell'], $s['month_cell']])->toBe(['A1', 'C1'])
        ->and([$s['name_source'], $s['name_cell']])->toBe(['cell', 'A4'])
        ->and($s['work_types'])->toBe(['休日' => null, '稼働' => '出勤'])
        ->and(collect($s['summary'])->firstWhere('cell', 'G4'))->toMatchArray(['role' => 'worked_minutes', 'type' => 'duration', 'unit' => 'hours'])
        ->and($s['totals_label'])->toBeNull();
});

it('suggests a template for a 勤務データ PDF', function () {
    $analysis = app(TemplateDesigner::class)->analyse(base_path('tests/fixtures/attendance/kintai-2026-10-sample.pdf'), 'kintai.pdf');
    $s = $analysis['settings'];

    expect($s['title_marker'])->toBe('【勤務データ】')
        ->and(array_column($s['columns'], 'role', 'title'))->toMatchArray(['日付' => 'work_date', '勤務区分' => 'work_type', '開始' => 'start_time', '労働時間' => 'worked_minutes'])
        ->and(array_column($s['summary'], 'role', 'title'))->toMatchArray(['所定時間' => 'scheduled_minutes', '有休残日数' => 'paid_leave_remaining_days', '出勤' => 'count:出勤'])
        ->and($s['totals_label'])->toBe('合計');
})->skip(fn () => ! hasPoppler() || ! is_file(base_path('tests/fixtures/attendance/kintai-2026-10-sample.pdf')), 'needs poppler and the sample PDF');

it('turns the submitted form into a template that reads the file', function () {
    $designer = app(TemplateDesigner::class);
    $analysis = $designer->analyse(sagyouHoukokushoXlsx(), '作業報告書.xlsx');
    $form = [...$analysis['settings'], 'name' => '作業報告書 (A社)', 'break_default' => '1:00',
        'columns' => array_map(fn ($c) => [...$c, 'include' => $c['include'] ? '1' : null], $analysis['settings']['columns']),
        'summary' => array_map(fn ($i) => [...$i, 'include' => '1'], $analysis['settings']['summary']),
        'work_types' => array_map(fn ($v) => $v ?? '', $analysis['settings']['work_types'])];

    $profile = $designer->fromForm($form, 'spreadsheet', $analysis['anchors']);
    $profile->save();

    $preview = app(AttendanceImporter::class)->preview(sagyouHoukokushoXlsx(), 'x.xlsx', $profile);
    $sheet = $preview->sheets[0]['sheet'];
    expect($profile->settings['break_default'])->toBe(60)
        ->and($sheet->employeeName)->toBe('田中')
        ->and($sheet->summary['worked_minutes'])->toBe(8655)
        ->and(collect($preview->sheets[0]['results'])->pluck('level', 'code')->only(['A-02', 'A-05', 'A-07'])->all())
        ->toBe(['A-02' => 'pass', 'A-05' => 'pass', 'A-07' => 'pass'])
        ->and($profile->match['headers'])->toContain('作業内容');
});
