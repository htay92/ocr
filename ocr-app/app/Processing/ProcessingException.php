<?php

namespace App\Processing;

use RuntimeException;

/**
 * A document that cannot be processed, with an application error code (spec 3.6),
 * for example E-PDF-01.
 */
class ProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
