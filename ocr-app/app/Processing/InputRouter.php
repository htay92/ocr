<?php

namespace App\Processing;

use App\Models\Document;

/**
 * Decides how a file is read (spec 2.4 step 2, FR-02): spreadsheets go to the
 * attendance importer, PDFs with a text layer are read directly, and only
 * images and image-only PDFs are OCR'd.
 */
class InputRouter
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/avif'];

    private const SPREADSHEET_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    public function __construct(private readonly PdfTextExtractor $pdfText) {}

    /**
     * @throws ProcessingException
     */
    public function route(string $path, string $mimeType): string
    {
        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SPREADSHEET_EXTENSIONS, true)) {
            return Document::ROUTE_SPREADSHEET;
        }

        if ($mimeType === 'application/pdf') {
            return $this->pdfText->extract($path) === null ? Document::ROUTE_OCR : Document::ROUTE_PDF_TEXT;
        }

        if (in_array($mimeType, self::IMAGE_TYPES, true)) {
            return Document::ROUTE_OCR;
        }

        throw new ProcessingException('E-UPLOAD-01', "Unsupported file type: {$mimeType}");
    }
}
