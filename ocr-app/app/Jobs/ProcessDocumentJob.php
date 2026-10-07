<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\OcrRun;
use App\Ocr\Data\OcrResult;
use App\Ocr\OcrException;
use App\Ocr\OcrManager;
use App\Processing\Analysis;
use App\Processing\DocumentAnalyzer;
use App\Processing\InputRouter;
use App\Processing\PdfRasterizer;
use App\Processing\PdfTextExtractor;
use App\Processing\ProcessingException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Processes one uploaded document (spec 2.4, 3.1): route, OCR, classify,
 * extract and validate, falling back to the next engine on critical failures (FR-09).
 */
class ProcessDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Engine retries happen inside HttpOcrEngine; a retried job would run every engine again.
    public int $tries = 1;

    // A queued document that was deleted before its job started: drop the job.
    public bool $deleteWhenMissingModels = true;

    // Must stay below REDIS_QUEUE_RETRY_AFTER (960 s); see config/ocr.php.
    public int $timeout = 900;

    /**
     * @param  ?string  $engine  run only this engine (re-run), instead of the fallback order
     */
    public function __construct(
        public Document $document,
        public ?string $engine = null,
    ) {}

    private DocumentAnalyzer $analyzer;

    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    public function handle(OcrManager $ocr, InputRouter $router, PdfTextExtractor $pdfText, PdfRasterizer $rasterizer, DocumentAnalyzer $analyzer): void
    {
        $this->analyzer = $analyzer;
        $document = $this->document;
        $document->transitionTo(Document::PROCESSING, ['error_message' => null]);
        $path = Storage::disk('local')->path($document->file_path);

        try {
            $route = $router->route($path, $document->mime_type);
            $document->update(['route' => $route]);

            match ($route) {
                // CSV / Excel are attendance exports: the user imports them with a profile (SC-06).
                Document::ROUTE_SPREADSHEET => $document->transitionTo(Document::EXTRACTED, ['type' => 'attendance']),
                Document::ROUTE_PDF_TEXT => $this->readTextLayer($document, $pdfText->extract($path) ?? ''),
                Document::ROUTE_OCR => $this->runOcr($document, $path, $ocr, $rasterizer),
            };
        } catch (ProcessingException $e) {
            $document->transitionTo(Document::FAILED, ['error_message' => "{$e->errorCode}: {$e->getMessage()}"]);
        }
    }

    /**
     * A system error (crash, timeout) must never leave the document stuck in processing.
     */
    public function failed(Throwable $e): void
    {
        $document = $this->document->fresh();

        if ($document?->canTransitionTo(Document::FAILED)) {
            $document->transitionTo(Document::FAILED, ['error_message' => $e->getMessage()]);
        }
    }

    private function readTextLayer(Document $document, string $text): void
    {
        $started = hrtime(true);
        $lines = [];
        foreach (explode("\f", $text) as $page => $pageText) {
            foreach (preg_split('/\R/u', $pageText) as $line) {
                if (trim($line) !== '') {
                    $lines[] = ['text' => trim($line), 'confidence' => 1.0, 'bbox' => null, 'page' => $page + 1];
                }
            }
        }

        $run = $document->ocrRuns()->create([
            'engine' => OcrRun::PDF_TEXT,
            'lines' => $lines,
            'avg_confidence' => 1,
            'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        // The text layer is exact, so there is no other engine to fall back to.
        $analysis = $this->analyzer->analyse($document, $run);
        $analysis->criticalFailures() === 0
            ? $this->analyzer->apply($document, $analysis, Document::EXTRACTED)
            : $this->needsReview($document, $analysis);
    }

    private function runOcr(Document $document, string $path, OcrManager $ocr, PdfRasterizer $rasterizer): void
    {
        $pages = $document->mime_type === 'application/pdf' ? $rasterizer->pages($path) : [$path];

        try {
            $lastError = null;
            $candidates = [];

            foreach ($this->engine ? [$this->engine] : $ocr->fallbackOrder() as $name) {
                try {
                    // The preprocessed page comes back too, so the review screen can draw the boxes on it.
                    $results = array_map(fn (string $page) => $ocr->driver($name)->recognize($page, ['return_image' => true]), $pages);
                } catch (OcrException $e) {
                    $document->ocrRuns()->create(['engine' => $name, 'error_code' => $e->errorCode]);
                    $lastError = $e;

                    if ($e->errorCode === 'invalid_image') {
                        break; // another engine would reject the same file
                    }

                    continue;
                }

                $run = $document->ocrRuns()->create($this->runAttributes($name, $results));
                $this->saveImages($document, $run, $results);
                $analysis = $this->analyzer->analyse($document, $run);

                if ($analysis->criticalFailures() === 0) {
                    $this->analyzer->apply($document, $analysis, Document::EXTRACTED);

                    return;
                }

                $candidates[] = $analysis; // critical failures: try the next engine (FR-09)
            }

            if ($candidates !== []) {
                // Every engine failed a critical check: keep the run with the fewest failures.
                usort($candidates, fn (Analysis $a, Analysis $b) => $a->criticalFailures() <=> $b->criticalFailures());
                $this->needsReview($document, $candidates[0]);

                return;
            }

            $code = $lastError?->errorCode === 'invalid_image' ? 'invalid_image' : OcrException::UNREACHABLE;
            $document->transitionTo(Document::FAILED, [
                'error_message' => "{$code}: ".($lastError?->getMessage() ?? __('no OCR engine is enabled')),
            ]);
        } finally {
            if ($pages !== [$path]) {
                $rasterizer->cleanup($pages);
            }
        }
    }

    /**
     * Merge the per-page results of one engine into one run.
     *
     * @param  list<OcrResult>  $results
     */
    private function runAttributes(string $engine, array $results): array
    {
        $lines = [];
        foreach ($results as $page => $result) {
            foreach ($result->lines as $line) {
                $lines[] = ['text' => $line->text, 'confidence' => $line->confidence, 'bbox' => $line->bbox, 'page' => $page + 1];
            }
        }

        return [
            'engine' => $engine,
            'engine_version' => $results[0]->engineVersion,
            'lines' => $lines,
            'avg_confidence' => $lines === [] ? null : array_sum(array_column($lines, 'confidence')) / count($lines),
            'elapsed_ms' => array_sum(array_map(fn (OcrResult $result) => $result->elapsedMs, $results)),
        ];
    }

    /**
     * Save the engine's preprocessed page images (the coordinate space of the OCR boxes).
     *
     * @param  list<OcrResult>  $results
     */
    private function saveImages(Document $document, OcrRun $run, array $results): void
    {
        $images = [];
        foreach ($results as $page => $result) {
            if ($result->preprocessedImage === null) {
                continue;
            }
            $path = "documents/{$document->id}/run-{$run->id}-p".($page + 1).'.jpg';
            Storage::disk('local')->put($path, base64_decode($result->preprocessedImage));
            $images[] = ['page' => $page + 1, 'path' => $path, 'width' => $result->image['width'], 'height' => $result->image['height']];
        }

        if ($images !== []) {
            $run->update(['images' => $images]);
        }
    }

    /**
     * Spec 2.4: critical checks failed on every engine; the user corrects manually.
     */
    private function needsReview(Document $document, Analysis $analysis): void
    {
        $this->analyzer->apply($document, $analysis, Document::NEEDS_REVIEW, [
            'error_message' => 'E-OCR-02: '.__('critical checks failed (:rules); best result from :engine', [
                'rules' => implode(', ', $analysis->criticalCodes()),
                'engine' => $analysis->run->engine,
            ]),
        ]);
    }
}
