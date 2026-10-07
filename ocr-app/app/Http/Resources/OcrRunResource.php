<?php

namespace App\Http\Resources;

use App\Models\OcrRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OcrRun
 */
class OcrRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'engine' => $this->engine,
            'engine_version' => $this->engine_version,
            'succeeded' => $this->succeeded(),
            'error_code' => $this->error_code,
            'avg_confidence' => $this->avg_confidence === null ? null : (float) $this->avg_confidence,
            'critical_failures' => $this->critical_failures,
            'elapsed_ms' => $this->elapsed_ms,
            'text' => $this->text(),
            'lines' => $this->lines ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
