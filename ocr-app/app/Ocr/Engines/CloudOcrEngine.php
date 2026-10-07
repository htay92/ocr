<?php

namespace App\Ocr\Engines;

use App\Ocr\Contracts\OcrEngine;
use App\Ocr\Data\OcrResult;
use App\Ocr\OcrException;

/**
 * Adapter slot for a paid cloud OCR API (spec 3.1). Disabled by default
 * (OCR_CLOUD_ENABLED=false, NFR-09); the provider adapters arrive in milestone 7.
 */
final class CloudOcrEngine implements OcrEngine
{
    public function recognize(string $path, array $options = []): OcrResult
    {
        throw new OcrException('engine_error', 'cloud: no cloud OCR provider is implemented yet');
    }
}
