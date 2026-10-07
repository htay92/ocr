<?php

use App\Attendance\Parsers\KintaiPdfParser;
use App\Attendance\Parsers\ProfileMapper;
use App\Attendance\Parsers\SpreadsheetReader;
use App\Models\ImportProfile;
use App\Validation\AttendanceValidator;

/** Level of one A-rule for the synthetic month, after changing some cells. */
function attendanceLevel(string $code, ?Closure $change = null, string $source = 'csv'): string
{
    if ($source === 'pdf') {
        $words = kintaiPdfWords();
        if ($change) {
            $words = $change($words);
        }
        $sheet = (new KintaiPdfParser)->parseWords($words);
    } else {
        $rows = kintaiRows();
        if ($change) {
            $rows = $change($rows);
        }
        $profile = ImportProfile::default();
        $sheet = (new ProfileMapper)->map((new SpreadsheetReader)->read(kintaiCsv($rows), $profile), $profile, 'kintai-2026-10.csv')['sheets'][0];
    }

    return collect((new AttendanceValidator)->validate($sheet))->firstWhere('code', $code)->level;
}

/** Row of 10/n in kintaiRows() (row 0 is the header). */
function day(int $n): int
{
    return $n;
}

it('passes every rule for a consistent month', function (string $source) {
    foreach (['A-01', 'A-02', 'A-03', 'A-04', 'A-05', 'A-06'] as $code) {
        expect(attendanceLevel($code, source: $source))->toBe('pass', $code);
    }
})->with(['csv', 'pdf']);

it('A-01: clock-out after clock-in, or overnight (critical)', function () {
    expect(attendanceLevel('A-01', function ($rows) {
        $rows[day(2)][5] = '';

        return $rows;
    }))->toBe('critical')     // no clock-out
        ->and(attendanceLevel('A-01', function ($rows) {
            $rows[day(2)][5] = '09:00';

            return $rows;
        }))->toBe('critical') // same time
        ->and(attendanceLevel('A-01', function ($rows) {                                                                  // night shift 22:00–06:00
            [$rows[day(2)][4], $rows[day(2)][5], $rows[day(2)][6], $rows[day(2)][7]] = ['22:00', '06:00', '01:00', '07:00'];

            return $rows;
        }))->toBe('pass');
});

it('A-02: worked time is clock-out minus clock-in minus break (warning)', function () {
    expect(attendanceLevel('A-02', function ($rows) {
        $rows[day(2)][7] = '07:00';

        return $rows;
    }))->toBe('warning');
});

it('A-03: dates inside the period, weekday as printed (critical)', function () {
    $wrongWeekday = function ($words) {
        foreach ($words as &$word) {
            if ($word[0] === '10/2(金)') {
                $word[0] = '10/2(月)';
            }
        }

        return $words;
    };
    expect(attendanceLevel('A-03', $wrongWeekday, 'pdf'))->toBe('critical');
});

it('A-04: no duplicate dates (critical)', function () {
    expect(attendanceLevel('A-04', function ($rows) {
        $rows[day(3)][2] = '2026/10/02';

        return $rows;
    }))->toBe('critical');
});

it('A-05: daily sums match the 合計 row and the summary (critical)', function () {
    expect(attendanceLevel('A-05', function ($rows) {
        $rows[day(2)][7] = '09:00';

        return $rows;
    }))->toBe('critical')
        ->and(attendanceLevel('A-05', fn ($rows) => array_slice($rows, 0, -1)))->toBe('skipped');     // no 合計 row, no summary
});

it('A-06: every date of the period exactly once (critical)', function () {
    expect(attendanceLevel('A-06', function ($rows) {
        unset($rows[day(15)]);

        return array_values($rows);
    }))->toBe('critical');
});

it('A-07 and A-08: day counts and scheduled days match the summary (warning)', function () {
    $edit = function (string $label, string $value) {
        return function ($words) use ($label, $value) {
            foreach ($words as $i => $word) {
                if ($word[0] === $label) {
                    // The value sits on the next line at the same x.
                    foreach ($words as $j => $other) {
                        if (abs($other[1] - $word[1]) < 1 && $other[2] > $word[2] && $other[2] - $word[2] < 15) {
                            $words[$j][0] = $value;
                        }
                    }
                }
            }

            return $words;
        };
    };

    expect(attendanceLevel('A-07', source: 'pdf'))->toBe('pass')
        ->and(attendanceLevel('A-07', $edit('出勤日数', '20.0日'), 'pdf'))->toBe('warning')
        ->and(attendanceLevel('A-07', $edit('有給休暇', '2'), 'pdf'))->toBe('warning')
        ->and(attendanceLevel('A-08', source: 'pdf'))->toBe('pass')
        ->and(attendanceLevel('A-08', $edit('所定出勤日', '22'), 'pdf'))->toBe('warning')
        ->and(attendanceLevel('A-07'))->toBe('skipped');                     // CSV: counts computed, nothing to compare
});
