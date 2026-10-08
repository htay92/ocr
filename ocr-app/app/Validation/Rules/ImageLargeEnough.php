<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-11: the uploaded image is large enough to read (warning). A thumbnail-sized
 * page (text a few pixels tall) cannot be read reliably by any engine; the user
 * is told to upload the original PDF or a larger image.
 */
class ImageLargeEnough extends Rule
{
    protected function code(): string
    {
        return 'V-11';
    }

    public function check(ValidationContext $context): RuleResult
    {
        if ($context->imageSize === null) {
            return $this->skip(__('No image (read without OCR)'));
        }

        [$width, $height] = $context->imageSize;
        $minimum = (int) config('ocr.min_image_side', 1000);

        return max($width, $height) >= $minimum
            ? $this->pass(__('Image size :width×:height px', ['width' => $width, 'height' => $height]))
            : $this->fail(RuleResult::WARNING, __('The image is too small (:width×:height px). If text is misread, upload the original PDF or an image with a long side of at least :min px.', [
                'width' => $width, 'height' => $height, 'min' => number_format($minimum),
            ]));
    }
}
