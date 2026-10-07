<?php

namespace App\Attendance;

use App\Attendance\Parsers\PdfTableParser;
use App\Attendance\Parsers\SpreadsheetReader;
use App\Attendance\Parsers\TemplateMapper;
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
        private readonly SpreadsheetReader $reader,
        private readonly TemplateMapper $mapper,
        private readonly TemplateMatcher $matcher,
        private readonly AttendanceValidator $validator,
    ) {}

    /**
     * Read a file with a template: the one given, else the best match among the
     * saved templates (auto-pick). The employee's name can be given when the
     * format does not hold it.
     *
     * @throws NoTemplateMatches when no template fits the file
     * @throws ProcessingException E-IMP-01 / E-IMP-02 / E-PDF-01
     */
    public function preview(string $path, string $fileName, ?ImportProfile $profile = null, ?string $name = null, ?string $code = null): ImportPreview
    {
        $profile ??= $this->matcher->best($path, $fileName) ?? throw new NoTemplateMatches(__('No template fits this file.'));
        $template = Template::fromProfile($profile);

        if ($template->kind === 'pdf') {
            $sheet = (new PdfTableParser($template))->parse($path);
            $sheets = [$name ? $sheet->withEmployee($name, $code) : $sheet];
            [$unknown, $route] = [[], Document::ROUTE_PDF_TEXT];
        } else {
            $mapped = $this->mapper->map($this->reader->read($path, $template, $fileName), $template, $fileName, $name, $code);
            [$sheets, $unknown, $route] = [$mapped['sheets'], $mapped['unknown_columns'], Document::ROUTE_SPREADSHEET];
        }

        if ($sheets === []) {
            throw new ProcessingException('E-IMP-02', __('No attendance rows were found in the file.'));
        }

        return new ImportPreview(
            sheets: array_map(fn ($sheet) => [
                'sheet' => $sheet,
                'results' => $this->validator->validate($sheet),
                'existing' => $sheet->employeeName === '' ? null
                    : AttendanceSheet::where('employee_name', $sheet->employeeName)->whereDate('period_start', $sheet->periodStart)->first(),
            ], $sheets),
            unknownColumns: $unknown,
            route: $route,
            profileId: $profile->id,
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
        if ($preview->needsName()) {
            throw ValidationException::withMessages(['employee_name' => __('Enter the employee\'s name: this file does not contain it.')]);
        }
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
