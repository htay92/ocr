<?php

namespace App\Processing;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Renders the pages of an image-only PDF to PNG at 300 dpi, because the OCR
 * contract takes one page per call (spec 3.2).
 */
class PdfRasterizer
{
    private const DPI = 300;

    /**
     * @return list<string> page image paths, in page order; remove them with cleanup()
     *
     * @throws ProcessingException E-PDF-01 when the PDF cannot be rendered
     */
    public function pages(string $path): array
    {
        $directory = sys_get_temp_dir().'/pdf-pages-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);

        $result = Process::timeout(120)->run(['pdftoppm', '-r', (string) self::DPI, '-png', $path, "{$directory}/page"]);
        $pages = glob("{$directory}/page-*.png") ?: [];

        if ($result->failed() || $pages === []) {
            File::deleteDirectory($directory);
            throw new ProcessingException('E-PDF-01', 'PDF cannot be rendered: '.trim($result->errorOutput()));
        }

        // pdftoppm zero-pads page numbers to the same width, so a plain sort keeps page order.
        sort($pages);

        return $pages;
    }

    /**
     * @param  list<string>  $pages
     */
    public function cleanup(array $pages): void
    {
        if ($pages !== []) {
            File::deleteDirectory(dirname($pages[0]));
        }
    }
}
