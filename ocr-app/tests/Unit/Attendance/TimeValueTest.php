<?php

use App\Attendance\Support\HolidayCalendar;
use App\Attendance\Support\TimeValue;

// Spec 3.4 "Attendance values" and 3.7 conversions.
it('reads clock times', function (string $input, ?string $expected) {
    expect(TimeValue::clock($input))->toBe($expected);
})->with([
    ['9:00', '09:00'], ['09:00', '09:00'], ['900', '09:00'], ['1830', '18:30'], ['９：００', '09:00'],
    ['', null], ['-', null], ['25:00', null], ['abc', null],
]);

it('reads durations as minutes', function (string $input, ?int $expected) {
    expect(TimeValue::minutes($input))->toBe($expected);
})->with([
    ['1:30', 90], ['168:00', 10080], ['00:00', 0], ['1.5', 90], ['8', 480], ['', 0], ['-', 0], ['x', null],
]);

it('reads day counts', function (string $input, ?float $expected) {
    expect(TimeValue::days($input))->toBe($expected);
})->with([['0.0日', 0.0], ['26.0日', 26.0], ['19.5', 19.5], ['21', 21.0], ['', 0.0], ['x日', null]]);

it('reads dates with or without the year', function (string $input, ?string $expected) {
    expect(TimeValue::date($input, 2026))->toBe($expected);
})->with([
    ['10/1(木)', '2026-10-01'], ['10/12(月)', '2026-10-12'], ['2026/10/01', '2026-10-01'],
    ['2026-10-31', '2026-10-31'], ['2026年10月1日', '2026-10-01'], ['10/32', null], ['合計', null],
]);

it('formats minutes as hours', function () {
    expect(TimeValue::format(10080))->toBe('168:00')
        ->and(TimeValue::format(90))->toBe('1:30')
        ->and(TimeValue::format(0))->toBe('0:00');
});

it('computes the day type from the date and Japanese holidays, never from colours', function (string $date, string $type, ?string $holiday) {
    expect(HolidayCalendar::dayType($date))->toBe($type)
        ->and(HolidayCalendar::holidayName($date))->toBe($holiday);
})->with([
    ['2026-10-01', 'weekday', null],
    ['2026-10-03', 'saturday', null],
    ['2026-10-04', 'holiday', null],          // Sunday
    ['2026-10-12', 'holiday', 'スポーツの日'],
    ['2026-11-03', 'holiday', '文化の日'],
]);

it('counts scheduled work days: weekdays that are not public holidays (A-08)', function () {
    expect(HolidayCalendar::workingDays('2026-10-01', '2026-10-31'))->toBe(21);
});
