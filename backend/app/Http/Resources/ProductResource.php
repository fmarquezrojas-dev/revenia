<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'description' => $this->description,
            'unit_code' => $this->unit_code, 'currency' => $this->currency,
            'unit_price_cents' => $this->unit_price_cents, 'tax_type' => $this->tax_type,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
