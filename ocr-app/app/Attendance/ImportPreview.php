<?php

namespace App\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Models\AttendanceSheet;
use App\Validation\RuleResult;

/**
 * What a file would import (spec 3.7 save rule 2): sheets with their A-xx
 * results and any sheet already stored for the same employee and period.
 */
final class ImportPreview
{
    /**
     * @param  list<array{sheet: ParsedSheet, results: list<RuleResult>, existing: ?AttendanceSheet}>  $sheets
     * @param  list<string>  $unknownColumns
     */
    public function __construct(
        public readonly array $sheets,
        public readonly array $unknownColumns,
        public readonly string $route,
        public readonly ?int $profileId,
    ) {}

    public function hasCritical(): bool
    {
        return collect($this->sheets)->contains(fn ($item) => RuleResult::criticalCount($item['results']) > 0);
    }

    public function needsName(): bool
    {
        return collect($this->sheets)->contains(fn ($item) => $item['sheet']->needsName || $item['sheet']->employeeName === '');
    }

    public function hasExisting(): bool
    {
        return collect($this->sheets)->contains(fn ($item) => $item['existing'] !== null);
    }

    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'profile_id' => $this->profileId,
            'unknown_columns' => $this->unknownColumns,
            'sheets' => array_map(fn ($item) => [
                ...SheetView::fromParsed($item['sheet']),
                'checks' => array_map(fn (RuleResult $r) => ['rule_code' => $r->code, 'level' => $r->level, 'message' => $r->message], $item['results']),
                'existing_document_id' => $item['existing']?->document_id,
            ], $this->sheets),
        ];
    }
}
