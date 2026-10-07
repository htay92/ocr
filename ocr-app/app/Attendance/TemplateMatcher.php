<?php

namespace App\Attendance;

use App\Attendance\Parsers\PdfTableParser;
use App\Attendance\Parsers\SpreadsheetReader;
use App\Attendance\Support\Grid;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Normalizer;

/**
 * Picks the template for a file (auto-pick on import): the share of the
 * template's column titles found in the file's header row (or PDF table
 * header), plus its anchor texts (sheet title, PDF title marker). A template
 * is only picked when its date or day column is found and most titles match.
 */
class TemplateMatcher
{
    public const THRESHOLD = 0.6;

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function best(string $path, string $fileName): ?ImportProfile
    {
        $scores = $this->scores($path, $fileName);
        arsort($scores);
        $id = array_key_first($scores);

        return $id !== null && $scores[$id] >= self::THRESHOLD ? ImportProfile::find($id) : null;
    }

    /**
     * @return array<int, float> template id => score
     */
    public function scores(string $path, string $fileName): array
    {
        $isPdf = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';
        $scores = [];
        $grids = [];
        $pdfLines = null;

        foreach (ImportProfile::where('kind', $isPdf ? 'pdf' : 'spreadsheet')->get() as $profile) {
            $template = Template::fromProfile($profile);
            $titles = array_values(array_filter(array_map(fn ($c) => self::clean($c['title']), $template->columns)));
            $dateTitle = self::clean(($template->column('work_date') ?? $template->column('work_day'))['title'] ?? '');
            if ($titles === []) {
                continue;
            }

            try {
                if ($isPdf) {
                    $pdfLines ??= array_map(fn ($line) => array_map(fn ($w) => self::clean($w[0]), $line), PdfTableParser::lines(PdfTableParser::words($path)));
                    $header = collect($pdfLines)->sortByDesc(fn ($line) => count(array_intersect($line, $titles)))->first() ?? [];
                    $top = implode(' ', array_merge(...array_slice($pdfLines, 0, 5)));
                } else {
                    $key = $template->sheet.'|'.$template->encoding.'|'.$template->delimiter;
                    $grids[$key] ??= $this->reader->read($path, $template, $fileName);
                    $grid = $grids[$key];
                    $header = array_values(array_map(fn ($v) => self::clean(Grid::text($v)), $grid->row($template->headerRow)));
                    $top = implode(' ', array_map(fn ($row) => implode(' ', array_map(fn ($v) => self::clean(Grid::text($v)), $row)),
                        array_filter($grid->rows, fn ($n) => $n <= 10, ARRAY_FILTER_USE_KEY)));
                }
            } catch (ProcessingException) {
                continue;
            }

            if ($dateTitle !== '' && ! in_array($dateTitle, $header, true)) {
                $scores[$profile->id] = 0.0;

                continue;
            }

            $score = count(array_intersect($titles, $header)) / count($titles);
            foreach (array_merge($profile->match['anchors'] ?? [], array_filter([$template->titleMarker])) as $anchor) {
                if (str_contains($top, self::clean($anchor))) {
                    $score += 0.5;
                }
            }
            $scores[$profile->id] = round($score, 3);
        }

        return $scores;
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', '', Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value), "\u{FEFF}");
    }
}
