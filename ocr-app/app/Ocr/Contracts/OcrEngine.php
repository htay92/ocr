<?php

namespace App\Ocr\Contracts;

use App\Ocr\Data\OcrResult;
use App\Ocr\OcrException;

interface OcrEngine
{
    /**
     * Read one page (image or single-page PDF).
     *
     * @param  array{preprocess?: bool, lang?: string, return_image?: bool}  $options
     *
     * @throws OcrException
     */
    public function recognize(string $path, array $options = []): OcrResult;
}
