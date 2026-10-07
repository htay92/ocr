<?php

use App\Attendance\Parsers\SpreadsheetReader;
use App\Attendance\Parsers\TemplateMapper;
use App\Attendance\Template;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;

function mapFile(string $path, ?ImportProfile $profile = null): array
{
    $profile ??= ImportProfile::default();

    $template = Template::fromProfile($profile);

    return (new TemplateMapper)->map((new SpreadsheetReader)->read($path, $template), $template, basename($path));
}

it('reads the standard export as CSV (UTF-8 with BOM, Shift-JIS) and Excel alike', function (string $file) {
    $result = mapFile($file);
    $sheet = $result['sheets'][0];

    expect($result['sheets'])->toHaveCount(1)
        ->and($sheet->employeeName)->toBe('山田 花子')
        ->and($sheet->employeeCode)->toBe('E001')
        ->and([$sheet->periodStart, $sheet->periodEnd])->toBe(['2026-10-01', '2026-10-31'])
        ->and($sheet->records)->toHaveCount(31)
        ->and($sheet->records[13]->toArray())->toMatchArray(['start_time' => '09:00', 'end_time' => '22:30', 'worked_minutes' => 750, 'late_night_minutes' => 30, 'source_row' => 15])
        ->and($sheet->records[11]->dayType)->toBe('holiday')
        ->and($sheet->summary)->toMatchArray(['worked_minutes' => 9720, 'overtime_minutes' => 360, 'worked_days' => 19.5, 'paid_leave_taken_days' => 1.5, 'scheduled_days' => 21])
        ->and($sheet->summarySource)->toBe([])                          // nothing in the file: all computed
        ->and($sheet->totals)->toMatchArray(['worked' => 9720, 'break' => 1140])
        ->and($result['unknown_columns'])->toBe([]);
})->with([
    'UTF-8 CSV' => fn () => kintaiCsv(kintaiRows()),
    'Shift-JIS CSV' => fn () => kintaiCsv(kintaiRows(), 'SJIS-win'),
    'Excel' => fn () => kintaiXlsx(kintaiRows()),
]);

it('creates one sheet per employee, grouped by employee code', function () {
    $rows = [...kintaiRows(), ...array_slice(kintaiRows('佐藤 一郎', 'E002'), 1)];

    $sheets = mapFile(kintaiCsv($rows))['sheets'];

    expect(array_map(fn ($sheet) => [$sheet->employeeCode, $sheet->employeeName, count($sheet->records)], $sheets))
        ->toBe([['E001', '山田 花子', 31], ['E002', '佐藤 一郎', 31]]);
});

it('maps columns by number and lists columns it does not know', function () {
    $profile = new ImportProfile(['name' => 'by-number', 'encoding' => 'auto', 'delimiter' => ',', 'header_row' => 1,
        'column_map' => ['employee_name' => 1, 'work_date' => 3, 'work_type' => 4, 'worked_minutes' => 8]]);

    $result = mapFile(kintaiCsv(kintaiRows()), $profile);

    expect($result['sheets'][0]->records[0]->workedMinutes)->toBe(480)
        ->and($result['unknown_columns'])->toBe(['社員番号', '開始', '終了', '休憩時間', '時間外', '深夜', '法定休日', '法定休日深夜', 'メモ', '確定']);
});

it('stops with E-IMP-02 when the date column is missing', function () {
    $rows = kintaiRows();
    $rows[0][2] = '年月日';

    mapFile(kintaiCsv($rows));
})->throws(ProcessingException::class, '日付');

it('stops with E-IMP-01 when the encoding cannot be detected', function () {
    $path = tempnam(sys_get_temp_dir(), 'bad').'.csv';
    file_put_contents($path, "\xFF\xFE\x00\x81\x82\xFC\xFD,\x80\x80");

    try {
        mapFile($path);
    } catch (ProcessingException $e) {
        expect($e->errorCode)->toBe('E-IMP-01');

        return;
    }
    $this->fail('No exception');
});

it('reports unreadable values with their row number and keeps importing', function () {
    $rows = kintaiRows();
    $rows[3][4] = '25:00';      // 10/3 start
    $rows[4][3] = '在宅勤務';    // unknown work type on 10/4

    $sheet = mapFile(kintaiCsv($rows))['sheets'][0];

    expect($sheet->warnings)->toContain('Row 4: 開始 "25:00" cannot be read.')
        ->and($sheet->warnings)->toContain('Row 5: unknown work type "在宅勤務".')
        ->and($sheet->records)->toHaveCount(31);
});

// Rewrites the committed example files: WRITE_FIXTURES=1 php artisan test --filter=fixtures
it('writes the example fixtures', function () {
    $dir = base_path('tests/fixtures/attendance');
    kintaiCsv(kintaiRows(), 'UTF-8', "{$dir}/kintai-2026-10-filled.csv");
    kintaiCsv(kintaiRows(), 'SJIS-win', "{$dir}/kintai-2026-10-filled-sjis.csv");
    kintaiXlsx(kintaiRows(), "{$dir}/kintai-2026-10-filled.xlsx");
    kintaiCsv([...kintaiRows(), ...array_slice(kintaiRows('佐藤 一郎', 'E002'), 1)], 'UTF-8', "{$dir}/kintai-2026-10-two-employees.csv");
    expect(glob("{$dir}/kintai-2026-10-*"))->not->toBeEmpty();
})->skip(fn () => ! env('WRITE_FIXTURES'), 'set WRITE_FIXTURES=1 to rewrite the example files');
