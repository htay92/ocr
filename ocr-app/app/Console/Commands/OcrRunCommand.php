<?php

namespace App\Console\Commands;

use App\Ocr\OcrException;
use App\Ocr\OcrManager;
use Illuminate\Console\Command;

class OcrRunCommand extends Command
{
    protected $signature = 'ocr:run
        {path : Image or single-page PDF}
        {--engine= : Engine name (default: OCR_ENGINE)}
        {--no-preprocess : Skip perspective, deskew, contrast and resize steps}
        {--json : Print the contract response as JSON}';

    protected $description = 'Run one OCR engine on a file and print what it read';

    public function handle(OcrManager $ocr): int
    {
        $engine = $this->option('engine') ?: $ocr->getDefaultDriver();

        try {
            $result = $ocr->driver($engine)->recognize($this->argument('path'), [
                'preprocess' => ! $this->option('no-preprocess'),
            ]);
        } catch (OcrException $e) {
            $this->error("[{$e->errorCode}] {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %s · %s ms · %d lines · avg confidence %s · %d×%d px',
            $result->engine,
            $result->engineVersion,
            number_format($result->elapsedMs),
            count($result->lines),
            $result->avgConfidence() ?? '-',
            $result->image['width'],
            $result->image['height'],
        ));

        $this->table(['#', 'conf', 'text'], array_map(
            fn ($line, $i) => [$i + 1, number_format($line->confidence, 2), $line->text],
            $result->lines,
            array_keys($result->lines),
        ));

        return self::SUCCESS;
    }
}
