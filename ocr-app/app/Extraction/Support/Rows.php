<?php

namespace App\Extraction\Support;

/**
 * Builds visual rows from OCR lines. Engines often return a label and its
 * value as separate lines ("合計" … "¥1,280"), sometimes value first; their
 * boxes show they are on the same line of the page.
 */
final class Rows
{
    /**
     * @param  list<array{text: string, confidence: float, bbox: ?list<array{0: int, 1: int}>, page?: int}>  $lines
     * @return list<Row> normalized, top to bottom
     */
    public static function fromLines(array $lines): array
    {
        $rows = [];
        $current = [];

        foreach (self::sorted($lines) as $line) {
            if ($current !== [] && ! self::sameRow($current[0], $line)) {
                array_push($rows, ...self::split($current));
                $current = [];
            }
            $current[] = $line;
        }
        if ($current !== []) {
            array_push($rows, ...self::split($current));
        }

        return $rows;
    }

    /**
     * One visual line can hold two columns ("株式会社 御中 | 株式会社",
     * "ご請求金額 ¥1,001,404 | FAX:"). Reading left to right, a new row starts
     * at a label that follows another label, or that follows a label with its
     * value. 御中, 様 and bracketed qualifiers stay with the text before them,
     * and table rows ("1 2025/7/1 商品A 1 式 ¥1,000,000") stay whole.
     *
     * @return list<Row>
     */
    private static function split(array $lines): array
    {
        usort($lines, fn (array $a, array $b) => ($a['left'] ?? 0) <=> ($b['left'] ?? 0));

        $rows = [];
        $segment = [];
        $label = false;          // the segment has a text part
        $valueAfterLabel = false;
        $lastWasLabel = false;

        foreach ($lines as $line) {
            $text = JapaneseNormalizer::normalize(trim($line['text']));
            // Numbers, amounts and unit words ("式 ¥50,000", "個") continue a table row.
            $isValue = (bool) preg_match('/^[¥(]?\s?[\-]?\d|^(式|個|点|コ|本|枚|件|台|人|回|時間|円)(?!\p{L})/u', $text);
            $isQualifier = (bool) preg_match('/^(御中|様|殿|\(.*\))$/u', $text);

            if ($segment !== [] && ! $isValue && ! $isQualifier && ($lastWasLabel || $valueAfterLabel)) {
                $rows[] = self::row($segment);
                $segment = [];
                $label = $valueAfterLabel = false;
            }

            $segment[] = $line;
            if ($isValue) {
                $valueAfterLabel = $valueAfterLabel || $label;
                $lastWasLabel = false;
            } elseif (! $isQualifier) {
                $label = true;
                $lastWasLabel = true;
            }
        }
        $rows[] = self::row($segment);

        return $rows;
    }

    /**
     * Lines with geometry, sorted by page and vertical centre; lines without
     * a box (PDF text layer) keep their order.
     */
    private static function sorted(array $lines): array
    {
        $items = [];
        foreach (array_values($lines) as $i => $line) {
            $ys = $line['bbox'] ? array_column($line['bbox'], 1) : null;
            $xs = $line['bbox'] ? array_column($line['bbox'], 0) : null;
            $items[] = [
                'text' => (string) $line['text'],
                'confidence' => (float) $line['confidence'],
                'page' => (int) ($line['page'] ?? 1),
                'top' => $ys ? (float) min($ys) : null,
                'bottom' => $ys ? (float) max($ys) : null,
                'left' => $xs ? (float) min($xs) : null,
                'right' => $xs ? (float) max($xs) : null,
                'order' => $i,
            ];
        }

        usort($items, function (array $a, array $b) {
            if ($a['page'] !== $b['page']) {
                return $a['page'] <=> $b['page'];
            }
            if ($a['top'] === null || $b['top'] === null) {
                return $a['order'] <=> $b['order'];
            }

            return ($a['top'] + $a['bottom']) <=> ($b['top'] + $b['bottom']);
        });

        return $items;
    }

    /**
     * Same row when the line's vertical centre lies within the row's first
     * line and both are of similar height (a tall heading is its own row).
     */
    private static function sameRow(array $first, array $line): bool
    {
        if ($first['page'] !== $line['page'] || $first['top'] === null || $line['top'] === null) {
            return false;
        }

        $centre = ($line['top'] + $line['bottom']) / 2;
        $heights = [$first['bottom'] - $first['top'], $line['bottom'] - $line['top']];

        return $centre >= $first['top'] && $centre <= $first['bottom'] && max($heights) <= 1.6 * max(1, min($heights));
    }

    private static function row(array $lines): Row
    {
        usort($lines, fn (array $a, array $b) => ($a['left'] ?? 0) <=> ($b['left'] ?? 0));
        $first = $lines[0];

        return new Row(
            text: JapaneseNormalizer::normalize(implode(' ', array_column($lines, 'text'))),
            confidence: (float) min(array_column($lines, 'confidence')),
            page: $first['page'],
            top: $first['top'] === null ? null : (float) min(array_column($lines, 'top')),
            height: $first['top'] === null ? null : (float) max(array_map(fn ($l) => $l['bottom'] - $l['top'], $lines)),
            box: $first['top'] === null ? null : [
                (int) min(array_column($lines, 'left')), (int) min(array_column($lines, 'top')),
                (int) max(array_column($lines, 'right')), (int) max(array_column($lines, 'bottom')),
            ],
        );
    }
}
