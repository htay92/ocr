<?php

namespace App\Processing;

use Illuminate\Support\Facades\Process;

/**
 * Reads the text layer of a born-digital PDF with pdftotext (spec 2.4, 3.1).
 */
class PdfTextExtractor
{
    /**
     * @return ?string the text with pages separated by form feeds, or null when the PDF has no text layer
     *
     * @throws ProcessingException E-PDF-01 when the PDF is encrypted or broken
     */
    public function extract(string $path): ?string
    {
        $result = Process::run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);

        if ($result->failed()) {
            throw new ProcessingException('E-PDF-01', 'PDF cannot be opened: '.trim($result->errorOutput()));
        }

        return trim(str_replace("\f", '', $result->output())) === '' ? null : $result->output();
    }
}
