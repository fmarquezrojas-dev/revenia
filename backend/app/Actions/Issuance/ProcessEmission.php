<?php

namespace App\Actions\Issuance;

use App\Actions\Delivery\DocumentDelivery;
use App\Actions\Documents\CreditNotes;
use App\Actions\Odoo\OdooTransfers;
use App\Contracts\TaxProvider;
use App\Models\Document;
use App\Services\SimulatedTaxProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcessEmission
{
    public function __construct(private TaxProvider $provider) {}

    public function claim(string $companyId, string $id, int $generation): ?Document
    {
        return DB::transaction(function () use ($companyId, $id, $generation): ?Document {
            $doc = Document::where('company_id', $companyId)->whereKey($id)->lockForUpdate()->first();
            if (! $doc || $doc->status !== 'queued' || $doc->version !== $generation || $doc->attempt_count >= 5) {
                return null;
            }
            $doc->status = 'sending';
            $doc->version++;
            $doc->attempt_count++;
            $doc->active_attempt_id = (string) Str::uuid();
            $doc->lease_until = now()->addSeconds(90);
            $doc->save();
            DB::table('emission_attempts')->insert(['id' => $doc->active_attempt_id, 'company_id' => $companyId, 'document_id' => $id, 'sequence' => $doc->attempt_count, 'created_at' => now()]);
            RequestIssuance::event($doc, 'attempt_started', 'queued', null, $doc->active_attempt_id);

            return $doc->load('items');
        });
    }

    public function handle(string $companyId, string $id, int $generation): void
    {
        $doc = $this->claim($companyId, $id, $generation);
        if (! $doc) {
            return;
        }
        $payload = ['document_id' => $doc->id, 'company_id' => $doc->company_id, 'number' => $doc->number, 'series' => $doc->series_code, 'doc_type' => $doc->doc_type, 'currency' => $doc->currency, 'issue_date' => $doc->issue_date->format('Y-m-d'), 'snapshot' => $doc->fiscal_snapshot, 'totals' => $doc->only(['taxable_cents', 'exempt_cents', 'unaffected_cents', 'igv_cents', 'total_cents']), 'items' => $doc->items->map(fn ($i) => $i->only(['line_number', 'description', 'unit_code', 'unit_price_cents', 'tax_type', 'quantity_millis', 'base_cents', 'igv_cents', 'total_cents']))->all()];
        try {
            $result = $this->provider->lookup($doc->submission_id) ?? $this->provider->submit($doc->submission_id, $payload);
        } catch (\Throwable) {
            $result = SimulatedTaxProvider::failure('unknown', 'provider_unavailable', 'No se pudo confirmar el resultado del simulador.');
        }
        $this->complete($companyId, $id, $doc->active_attempt_id, $result);
    }

    public function complete(string $companyId, string $id, string $attemptId, array $result): bool
    {
        return DB::transaction(function () use ($companyId, $id, $attemptId, $result): bool {
            $doc = Document::where('company_id', $companyId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($doc->status !== 'sending' || $doc->active_attempt_id !== $attemptId || ! $doc->lease_until || $doc->lease_until->lte(now())) {
                return false;
            }
            $doc->status = $result['status'];
            $doc->result_code = $result['code'];
            $doc->result_message = $result['message'];
            $doc->failure_kind = $result['kind'] ?? null;
            $doc->can_retry = $doc->status === 'failed' && $doc->failure_kind !== 'permanent' && $doc->attempt_count < 5;
            $doc->next_attempt_at = $doc->can_retry && $doc->attempt_count < 3 ? now()->addSeconds([1 => 10, 2 => 30][$doc->attempt_count]) : null;
            $doc->active_attempt_id = null;
            $doc->lease_until = null;
            $doc->version++;
            $doc->save();
            if ($doc->doc_type === '07') {
                CreditNotes::settle($doc);
            }
            if ($doc->status === 'accepted') {
                DocumentDelivery::ensurePdf($doc);
                OdooTransfers::enqueue($doc);
            }
            DB::table('emission_attempts')->where('id', $attemptId)->update(['finished_at' => now(), 'outcome' => $doc->status]);
            RequestIssuance::event($doc, $doc->status, 'sending', null, $attemptId);

            return true;
        });
    }
}
