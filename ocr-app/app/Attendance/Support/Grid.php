<?php

namespace App\Attendance\Support;

use App\Attendance\Template;

/**
 * A sheet's cells as the file holds them: rows by number, cells by column letter,
 * with Excel number formats so times and durations can be told apart.
 */
final class Grid
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  row number => [column letter => value]
     * @param  array<string, string>  $formats  cell address => number format code
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $formats = [],
        public readonly string $sheetName = '',
    ) {}

    /** A cell by address ("B3"), or null. */
    public function cell(?string $address): mixed
    {
        if ($address === null || ! preg_match('/^([A-Z]{1,3})(\d+)$/', strtoupper(trim($address)), $m)) {
            return null;
        }

        return $this->rows[(int) $m[2]][$m[1]] ?? null;
    }

    public function format(string $address): string
    {
        return $this->formats[strtoupper($address)] ?? 'General';
    }

    /** @return array<string, mixed> */
    public function row(int $number): array
    {
        return $this->rows[$number] ?? [];
    }

    /** Column letters in use, in order. */
    public function letters(): array
    {
        $max = 0;
        foreach ($this->rows as $cells) {
            foreach (array_keys($cells) as $letter) {
                $max = max($max, self::index($letter) + 1);
            }
        }

        return array_map(fn ($i) => Template::letter($i), range(0, max(0, $max - 1)));
    }

    public static function index(string $letter): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letter)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /** A cell as display text (numbers as the file shows them, roughly). */
    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            default => trim((string) $value),
        };
    }
}
