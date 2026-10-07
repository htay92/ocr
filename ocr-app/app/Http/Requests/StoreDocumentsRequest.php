<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * FR-01: JPEG, PNG, HEIC, PDF, CSV, XLSX and XLS, up to 20 MB each, several at once.
 * AVIF is accepted too: images saved from the web are often AVIF behind a .png name.
 */
class StoreDocumentsRequest extends FormRequest
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'avif', 'pdf', 'csv', 'xlsx', 'xls'];

    // CSV is often detected as text/plain, and some XLSX files as a plain ZIP archive.
    public const MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/avif', 'application/pdf',
        'text/csv', 'text/plain', 'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip',
        'application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2',
    ];

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'required',
                'file',
                'extensions:'.implode(',', self::EXTENSIONS),
                'mimetypes:'.implode(',', self::MIME_TYPES),
                'max:'.(config('ocr.upload.max_mb') * 1024),
            ],
        ];
    }

    /**
     * Adds the application error code (spec 3.6): E-UPLOAD-02 when a file is too
     * large, otherwise E-UPLOAD-01 (unsupported or missing file). The API gets
     * 422 JSON with "code"; the upload screen gets the code as the "code" error.
     */
    protected function failedValidation(Validator $validator): void
    {
        $tooLarge = collect($validator->failed())
            ->filter(fn (array $rules, string $field) => str_starts_with($field, 'files.') && isset($rules['Max']))
            ->isNotEmpty();
        $code = $tooLarge ? 'E-UPLOAD-02' : 'E-UPLOAD-01';

        if (! $this->expectsJson()) {
            $validator->errors()->add('code', $code);
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'code' => $code,
            'errors' => $validator->errors(),
        ], 422));
    }

    public function attributes(): array
    {
        return ['files.*' => __('file')];
    }
}
