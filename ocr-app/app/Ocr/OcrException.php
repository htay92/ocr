<?php

namespace App\Ocr;

use RuntimeException;
use Throwable;

/**
 * An OCR failure. errorCode is a contract code (invalid_image, too_large,
 * engine_error, not_ready; spec 3.2) or an application code (spec 3.6).
 */
class OcrException extends RuntimeException
{
    /** Engine unreachable or timed out after retries. */
    public const UNREACHABLE = 'E-OCR-01';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
