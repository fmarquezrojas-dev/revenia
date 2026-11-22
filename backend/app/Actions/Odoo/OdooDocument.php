<?php

namespace App\Actions\Odoo;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

final class OdooDocument
{
    public static function payload(Document $doc, object $connection): array
    {
        if ($doc->status !== 'accepted' || ! in_array($doc->doc_type, ['01', '03', '07'], true)) {
            throw new \DomainException('odoo_document');
        }
        $snapshot = $doc->fiscal_snapshot ?? [];
        $sourceType = $doc->doc_type === '07' ? ($snapshot['source_doc_type'] ?? null) : $doc->doc_type;
        $anonymous = $sourceType === '03' && $doc->customer_id === null && array_key_exists('customer', $snapshot) && $snapshot['customer'] === null;
        $customerKey = $anonymous ? 'anonymous' : ($snapshot['customer']['id'] ?? '');
        $maps = DB::table('odoo_mappings')->where('company_id', $doc->company_id)->where('destination', $connection->destination)
            ->where(fn ($q) => $q->where('kind', '<>', 'customer')->orWhere('source', $customerKey))->get();
        $ids = [];
        foreach ($maps as $map) {
            $ids[$map->kind][$map->source] = (int) $map->remote_id;
        }
        $map = static fn (string $kind, string $source): int => $ids[$kind][$source] ?? throw new \DomainException('odoo_map_'.$kind);
        $original = 0;
        $originalKey = '';
        if ($doc->doc_type === '07') {
            $original = DB::table('odoo_transfers')->where('company_id', $doc->company_id)->where('document_id', $doc->source_document_id)
                ->where('destination', $connection->destination)->where('status', 'done')->value('external_id');
            if (! $original) {
                throw new \DomainException('odoo_original');
            }
            $originalKey = $doc->company_id.'/'.$doc->source_document_id;
        }
        $lines = [];
        foreach ($doc->items as $line) {
            $lines[] = ['source_line' => $line->id, 'source_product' => $line->product_id, 'source_unit' => $line->unit_code, 'number' => $line->line_number, 'description' => $line->description, 'quantity' => $line->quantity_millis,
                'unit_price' => $line->unit_price_cents, 'unit' => $map('unit', $line->unit_code), 'category' => $line->tax_type,
                'tax' => $map('tax', $line->tax_type), 'account' => $map('account', 'income'), 'discount' => 0,
                'base' => $line->base_cents, 'total' => $line->total_cents];
        }

        return ['company' => (int) $connection->remote_company, 'key' => $doc->company_id.'/'.$doc->id, 'type' => $doc->doc_type,
            'simulated' => $doc->result_code === 'simulated_acceptance', 'credit_reason' => (string) $doc->credit_reason, 'number' => $doc->series_code.'-'.str_pad((string) $doc->number, 8, '0', STR_PAD_LEFT),
            'move_type' => $doc->doc_type === '07' ? 'out_refund' : 'out_invoice',
            'customer_key' => $customerKey, 'customer_name' => $doc->fiscal_snapshot['customer']['name'] ?? '',
            'customer_number' => $doc->fiscal_snapshot['customer']['doc_number'] ?? '', 'partner' => $map($anonymous ? 'anonymous' : 'customer', $customerKey), 'currency' => $map('currency', $doc->currency),
            'iso' => $doc->currency, 'journal' => $map('journal', 'sales'), 'original' => (int) $original, 'original_key' => $originalKey,
            'date' => $doc->issue_date->toDateString(), 'due' => ($doc->due_date ?? $doc->issue_date)->toDateString(),
            'lines' => $lines, 'base' => $doc->total_cents - $doc->igv_cents, 'tax' => $doc->igv_cents, 'total' => $doc->total_cents];
    }

    public static function confirm(array $payload, mixed $receipt): int
    {
        if (! is_array($receipt) || ! is_int($receipt['id'] ?? null) || $receipt['id'] < 1 || ($receipt['state'] ?? null) !== 'draft') {
            throw new \DomainException('odoo_receipt');
        }
        unset($payload['original_key']);
        $lines = $receipt['lines'] ?? [];
        if (! is_array($lines) || ! array_is_list($lines) || count($payload['lines']) !== count($lines)) {
            throw new \DomainException('odoo_amounts');
        }
        foreach ($payload['lines'] as $index => $expected) {
            foreach ($expected as $key => $value) {
                $actual = $lines[$index][$key] ?? null;
                if ($key === 'discount' && $actual === 0.0) {
                    $actual = 0;
                }
                if ($actual !== $value) {
                    throw new \DomainException('odoo_amounts');
                }
            }
        }
        unset($payload['lines']);
        foreach ($payload as $key => $value) {
            if (($receipt[$key] ?? null) !== $value) {
                throw new \DomainException('odoo_receipt');
            }
        }

        return $receipt['id'];
    }
}
