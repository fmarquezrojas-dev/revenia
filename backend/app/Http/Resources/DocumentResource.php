<?php

namespace App\Http\Resources;

use App\Actions\Delivery\DocumentDelivery;
use App\Actions\Documents\CreditNotes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['source_document_id' => $this->source_document_id, 'source_number' => $this->fiscal_snapshot['source_number'] ?? null,
            'credit_reason' => $this->credit_reason, 'credit' => $request->isMethod('get') && $request->route('documentId') && $this->status === 'accepted' && $this->doc_type !== '07'
                ? CreditNotes::detail($this->resource, $request->user()) : null, 'delivery' => $request->route('documentId') ? DocumentDelivery::summary($this->resource, $request->user()) : null, 'id' => $this->id, 'doc_type' => $this->doc_type, 'currency' => $this->currency, 'status' => $this->status,
            'payment_terms' => $this->payment_terms, 'due_date' => $this->due_date?->toDateString(),
            'issue_date' => $this->issue_date->format('Y-m-d'), 'number' => $this->number, 'issued_at' => $this->issued_at?->toISOString(),
            'formatted_number' => $this->number ? $this->series_code.'-'.str_pad((string) $this->number, 8, '0', STR_PAD_LEFT) : null, 'issuer' => $this->fiscal_snapshot['issuer'] ?? null, 'can_retry' => $this->can_retry, 'next_attempt_at' => $this->next_attempt_at?->toISOString(), 'result_code' => $this->result_code, 'result_message' => $this->result_message, 'failure_kind' => $this->failure_kind, 'attempt_count' => $this->attempt_count, 'version' => $this->version, 'customer_id' => $this->customer_id, 'series_id' => $this->series_id,
            'customer' => $this->fiscal_snapshot ? ($this->fiscal_snapshot['customer'] ? Arr::except($this->fiscal_snapshot['customer'], ['email']) : null) : ($this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name, 'doc_type' => $this->customer->doc_type, 'doc_number' => $this->customer->doc_number, 'is_active' => $this->customer->is_active] : null),
            'series' => $this->fiscal_snapshot['series'] ?? ['id' => $this->series->id, 'code' => $this->series->code, 'doc_type' => $this->series->doc_type, 'is_active' => $this->series->is_active],
            'totals' => array_intersect_key($this->resource->attributesToArray(), array_flip(['taxable_cents', 'exempt_cents', 'unaffected_cents', 'igv_cents', 'total_cents'])),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id, 'product_id' => $item->product_id, 'line_number' => $item->line_number, 'description' => $item->description,
                'unit_code' => $item->unit_code, 'unit_price_cents' => $item->unit_price_cents, 'tax_type' => $item->tax_type,
                'quantity' => intdiv($item->quantity_millis, 1000).'.'.str_pad((string) ($item->quantity_millis % 1000), 3, '0', STR_PAD_LEFT),
                'base_cents' => $item->base_cents, 'igv_cents' => $item->igv_cents, 'total_cents' => $item->total_cents,
                'currency' => $this->currency, 'product_is_active' => $this->number ? true : $item->product->is_active, 'product_currency' => $this->number ? $this->currency : $item->product->currency,
            ])->all(), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
