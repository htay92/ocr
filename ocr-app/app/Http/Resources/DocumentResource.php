<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'route' => $this->route,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'sha256' => $this->sha256,
            'error_message' => $this->error_message,
            'selected_run' => new OcrRunResource($this->whenLoaded('selectedRun')),
            'file_url' => url("/api/documents/{$this->id}/file"),
            'confirmed_at' => $this->confirmed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
