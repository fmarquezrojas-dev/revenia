<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'doc_type' => $this->doc_type, 'code' => $this->code,
            'current_number' => $this->current_number, 'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
