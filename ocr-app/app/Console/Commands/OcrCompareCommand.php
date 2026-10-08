<?php

namespace App\Console\Commands;

use App\Models\OcrRun;
use App\Ocr\OcrException;
use App\Ocr\OcrManager;
use App\Processing\Analysis;
use App\Processing\DocumentAnalyzer;
use App\Processing\PdfRasterizer;
use Illuminate\Console\Command;
use Normalizer;

/**
 * Engine comparison on sample files (FR-18 evaluation, NFR-01): every engine
 * reads every file, then the job's classify / extract / validate steps run on
 * each reading. Nothing is saved.
 */
class OcrCompareCommand extends Command
{
    /** The key fields of NFR-01. */
    public const FIELDS = ['vendor_name', 'issue_date', 'total', 'registration_number'];

    protected $signature = 'ocr:compare
        {paths* : Image or PDF files, or folders of them}
        {--engines= : Comma-separated engines (default: the fallback order)}
        {--expected= : Ground-truth JSON (file name => {type, fields}); default: expected.json next to the files}';

    protected $description = 'Compare OCR engines on sample files: time, confidence, critical checks and key fields';

    public function handle(OcrManager $ocr, DocumentAnalyzer $analyzer, PdfRasterizer $rasterizer): int
    {
        $engines = $this->option('engines')
            ? array_values(array_filter(array_map('trim', explode(',', $this->option('engines')))))
            : $ocr->fallbackOrder();
        $files = $this->files($this->argument('paths'));
        if ($files === [] || $engines === []) {
            $this->error($files === [] ? 'No image or PDF files found.' : 'No OCR engine is enabled.');

            return self::FAILURE;
        }
        $expected = $this->expected($files);

        $rows = [];
        $stats = array_fill_keys($engines, ['files' => 0, 'clean' => 0, 'hits' => 0, 'checked' => 0, 'ms' => 0, 'errors' => 0]);
        $rescued = [];

        foreach ($files as $file) {
            $name = Normalizer::normalize(basename($file), Normalizer::FORM_C);
            $this->line("<comment>{$name}</comment>");
            $pages = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf' ? $rasterizer->pages($file) : [$file];
            $firstFailed = false;

            foreach ($engines as $i => $engine) {
                try {
                    $results = array_map(fn (string $page) => $ocr->driver($engine)->recognize($page), $pages);
                } catch (OcrException $e) {
                    $stats[$engine]['errors']++;
                    $rows[] = [$name, $engine, '-', '-', '-', '-', "[{$e->errorCode}]", '', '', '', ''];
                    $firstFailed = $firstFailed || $i === 0;

                    continue;
                }

                $analysis = $analyzer->evaluate(new OcrRun(OcrRun::attributesFrom($engine, $results)));
                $marks = $this->marks($analysis, $expected[$name]['fields'] ?? null);
                $critical = $analysis->criticalCodes();

                $stats[$engine]['files']++;
                $stats[$engine]['ms'] += $analysis->run->elapsed_ms;
                $stats[$engine]['clean'] += $critical === [] ? 1 : 0;
                foreach ($marks as $mark) {
                    $stats[$engine]['checked'] += $mark === null ? 0 : 1;
                    $stats[$engine]['hits'] += $mark ? 1 : 0;
                }
                if ($i === 0) {
                    $firstFailed = $critical !== [];
                } elseif ($firstFailed && $critical === [] && ! in_array($name, $rescued, true)) {
                    $rescued[] = $name;
                }

                $rows[] = [
                    $name, $engine,
                    number_format($analysis->run->elapsed_ms / 1000, 1),
                    count($analysis->run->lines),
                    $analysis->run->avg_confidence === null ? '-' : number_format((float) $analysis->run->avg_confidence, 2),
                    $analysis->type,
                    $critical === [] ? '✓' : implode(' ', $critical),
                    ...array_map(fn (?bool $mark) => match ($mark) {
                        true => '✓', false => '✗', null => ''
                    }, $marks),
                ];
            }
        }

        $this->newLine();
        $this->table(['file', 'engine', 's', 'lines', 'conf', 'type', 'critical', 'vendor', 'date', 'total', 'T-no.'], $rows);

        $this->table(['engine', 'files', 'no critical', 'key fields (NFR-01)', 'avg s', 'errors'], array_map(fn (string $engine) => [
            $engine,
            $stats[$engine]['files'],
            $stats[$engine]['clean'].'/'.$stats[$engine]['files'],
            $stats[$engine]['checked'] === 0 ? '-'
                : sprintf('%d/%d (%.0f%%)', $stats[$engine]['hits'], $stats[$engine]['checked'], 100 * $stats[$engine]['hits'] / $stats[$engine]['checked']),
            $stats[$engine]['files'] === 0 ? '-' : number_format($stats[$engine]['ms'] / $stats[$engine]['files'] / 1000, 1),
            $stats[$engine]['errors'],
        ], $engines));

        if (count($engines) > 1) {
            $this->info(sprintf('Fallback: %d file(s) with critical failures on %s pass on a later engine%s',
                count($rescued), $engines[0], $rescued === [] ? '.' : ': '.implode(', ', $rescued)));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function files(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            foreach (is_dir($path) ? glob(rtrim($path, '/').'/*') : [$path] as $file) {
                if (is_file($file) && preg_match('/\.(jpe?g|png|webp|heic|heif|tiff?|bmp|pdf)$/i', $file)) {
                    $files[] = $file;
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }

    private function expected(array $files): array
    {
        $path = $this->option('expected') ?: dirname($files[0]).'/expected.json';
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true) ?: [];

        return collect($data)->mapWithKeys(fn ($item, $key) => [Normalizer::normalize((string) $key, Normalizer::FORM_C) => $item])->all();
    }

    /**
     * Key fields against the ground truth: true / false, or null when there is none.
     *
     * @return list<?bool>
     */
    private function marks(Analysis $analysis, ?array $expected): array
    {
        return array_map(function (string $field) use ($analysis, $expected) {
            if ($expected === null || ! array_key_exists($field, $expected)) {
                return null;
            }
            $want = $expected[$field];
            $got = $analysis->extracted?->get($field);

            if ($field === 'vendor_name' && $want !== null && $got !== null) {
                [$want, $got] = [self::plain($want), self::plain($got)];

                return $got !== '' && (str_contains($got, $want) || str_contains($want, $got));
            }

            return $want === null ? $got === null : (string) $want === (string) $got;
        }, self::FIELDS);
    }

    private static function plain(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text));
    }
}
