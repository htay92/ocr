<?php

namespace App\Validation\Rules;

use App\Models\Receipt;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** V-10: no other document with the same vendor, date and total (warning). */
class NotDuplicate extends Rule
{
    protected function code(): string
    {
        return 'V-10';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $doc = $context->document;
        [$vendor, $date, $total] = [$doc->get('vendor_name'), $doc->get('issue_date'), $doc->get('total')];

        if ($vendor === null || $date === null || $total === null) {
            return $this->skip('Vendor, date or total missing');
        }

        $duplicate = Receipt::query()
            ->where('vendor_name', $vendor)
            ->whereDate('issue_date', $date)
            ->where('total', $total)
            ->when($context->documentId, fn ($query, $id) => $query->where('document_id', '!=', $id))
            ->value('document_id');

        return $duplicate === null
            ? $this->pass('No duplicate')
            : $this->fail(RuleResult::WARNING, "Same vendor, date and total as document #{$duplicate}");
    }
}
