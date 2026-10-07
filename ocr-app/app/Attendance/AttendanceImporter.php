<?php

namespace App\Attendance;

use App\Attendance\Parsers\KintaiPdfParser;
use App\Attendance\Parsers\ProfileMapper;
use App\Attendance\Parsers\SpreadsheetReader;
use App\Models\AttendanceSheet;
use App\Models\Document;
use App\Models\ImportProfile;
use App\Models\User;
use App\Processing\ProcessingException;
use App\Validation\AttendanceValidator;
use App\Validation\RuleResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attendance import (UC-07, FR-12, spec 3.7): PDF text layer, CSV and Excel,
 * previewed with A-01..A-08 before anything is saved.
 */
class AttendanceImporter
{
    public function __construct(
        private readonly KintaiPdfParser $pdf,
        private readonly SpreadsheetReader $reader,
        private readonly ProfileMapper $mapper,
        private readonly AttendanceValidator $validator,
    ) {}

    /**
     * @throws ProcessingException E-IMP-01 / E-IMP-02 / E-PDF-01
     */
    public function preview(string $path, string $fileName, ?ImportProfile $profile = null): ImportPreview
    {
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf') {
            $sheets = [$this->pdf->parse($path)];
            [$unknown, $route, $profile] = [[], Document::ROUTE_PDF_TEXT, null];
        } else {
            $profile ??= ImportProfile::default();
            $mapped = $this->mapper->map($this->reader->read($path, $profile), $profile, $fileName);
            [$sheets, $unknown, $route] = [$mapped['sheets'], $mapped['unknown_columns'], Document::ROUTE_SPREADSHEET];
        }

        if ($sheets === []) {
            throw new ProcessingException('E-IMP-02', __('No attendance rows were found in the file.'));
        }

        return new ImportPreview(
            sheets: array_map(fn ($sheet) => [
                'sheet' => $sheet,
                'results' => $this->validator->validate($sheet),
                'existing' => AttendanceSheet::where('employee_name', $sheet->employeeName)->whereDate('period_start', $sheet->periodStart)->first(),
            ], $sheets),
            unknownColumns: $unknown,
            route: $route,
            profileId: $profile?->id,
        );
    }

    /**
     * Save the previewed file in one transaction (save rule 1): the document, its
     * sheets and all records. A sheet already stored for the same employee and
     * period is replaced only when asked (rule 3); its old document is kept.
     *
     * @param  ?Document  $document  an uploaded document to import into; otherwise a new one is created
     *
     * @throws ValidationException when replacing or failing critical checks was not confirmed
     */
    public function import(ImportPreview $preview, string $path, string $fileName, User $user, bool $replace, bool $acknowledged, ?Document $document = null): Document
    {
        if ($preview->hasExisting() && ! $replace) {
            throw ValidationException::withMessages(['replace' => __('This employee and period were imported before. Choose Replace to import again.')]);
        }
        if ($preview->hasCritical() && ! $acknowledged) {
            throw ValidationException::withMessages(['acknowledged' => __('Some checks still fail. Tick the box to import anyway.')]);
        }

        return DB::transaction(function () use ($preview, $path, $fileName, $user, $document) {
            if ($document === null) {
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $stored = 'documents/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
                Storage::disk('local')->put($stored, file_get_contents($path));
                $document = $user->documents()->create([
                    'original_name' => $fileName,
                    'file_path' => $stored,
                    'mime_type' => mime_content_type($path) ?: 'application/octet-stream',
                    'sha256' => hash_file('sha256', $path),
                    'status' => Document::CONFIRMED,
                    'confirmed_at' => now(),
                ]);
            } elseif ($document->status !== Document::CONFIRMED) {
                $document->transitionTo(Document::CONFIRMED);
            }
            $document->update(['type' => 'attendance', 'route' => $preview->route, 'error_message' => null]);

            foreach ($preview->sheets as ['sheet' => $parsed, 'results' => $results, 'existing' => $existing]) {
                $existing?->delete();

                $sheet = $document->attendanceSheets()->create([...$parsed->toArray(), 'import_profile_id' => $preview->profileId]);
                $sheet->records()->createMany(array_map(fn ($record) => $record->toArray(), $parsed->records));
                foreach ($results as $result) {
                    if ($result->level !== RuleResult::SKIPPED) {
                        $sheet->checks()->create([
                            'document_id' => $document->id,
                            'rule_code' => $result->code,
                            'level' => $result->level,
                            'message' => mb_strimwidth($result->message, 0, 255, '…'),
                        ]);
                    }
                }
            }

            return $document;
        });
    }
}
