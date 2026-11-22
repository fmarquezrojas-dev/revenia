<?php

namespace App\Actions\Documents;

use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\Collections;
use App\Domain\Money\TaxCalculator;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreditNotes
{
    /** Both collections and the portfolio sum the same immutable historical lines. */
    public static function exposure(string $company): Builder
    {
        return DB::table('credit_note_lines as c')
            ->join('document_items as i', 'i.id', '=', 'c.source_item_id')
            ->where('c.company_id', $company)->whereIn('c.state', ['reserved', 'applied'])
            ->groupBy('c.source_document_id')->select('c.source_document_id')
            ->selectRaw("coalesce(sum(i.total_cents) FILTER (WHERE c.state='applied'),0) as accepted_cents,
                coalesce(sum(i.total_cents) FILTER (WHERE c.state='reserved'),0) as reserved_cents");
    }

    private static function series(Document $source): \Illuminate\Database\Eloquent\Builder
    {
        return DocumentSeries::where('company_id', $source->company_id)->where('doc_type', '07')
            ->where('is_active', true)->where('code', 'like', ($source->doc_type === '01' ? 'F' : 'B').'%');
    }

    public static function detail(Document $source, User $user): array
    {
        return DB::transaction(function () use ($source, $user) {
            // Read availability and preview against the same source lock as issuance and payments.
            Document::where('company_id', $source->company_id)->whereKey($source->id)->sharedLock()->firstOrFail();
            $notes = Document::where('company_id', $source->company_id)->where('source_document_id', $source->id)
                ->orderByDesc('created_at')->get(['id', 'series_code', 'number', 'status', 'total_cents']);
            $states = DB::table('credit_note_lines')->where('company_id', $source->company_id)->where('source_document_id', $source->id)
                ->whereIn('state', ['reserved', 'applied'])->pluck('state', 'source_item_id');

            $balance = app(Collections::class)->balance($source);

            return ['notes' => $notes, 'states' => (object) $states->all(),
                'series' => $user->can('update', $source) ? self::series($source)->orderBy('code')->get(['id', 'code']) : [],
                'balance' => ['net_cents' => $balance['net_cents'], 'paid_cents' => (string) $balance['paid_cents']]];
        });
    }

    public function draft(User $actor, string $id, array $data): Document
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $source = Document::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $source);
            if ($source->status !== 'accepted' || ! in_array($source->doc_type, ['01', '03'], true)) {
                RequestIssuance::conflict('Solo se acreditan facturas o boletas aceptadas.');
            }
            $series = self::series($source)->sharedLock()->findOrFail($data['series_id']);
            $lines = $source->items()->whereIn('id', $data['item_ids'])->get();
            abort_unless($lines->count() === count($data['item_ids']), 404);
            $this->checkAvailable($source, $data['item_ids']);
            $totals = (new TaxCalculator)->totals($lines->toArray());
            $snapshot = $source->fiscal_snapshot;
            $snapshot['series'] = $series->only(['id', 'code', 'doc_type', 'is_active']);
            $snapshot['source_document_id'] = $source->id;
            $snapshot['source_doc_type'] = $source->doc_type;
            $snapshot['source_number'] = $source->series_code.'-'.str_pad((string) $source->number, 8, '0', STR_PAD_LEFT);
            $snapshot['credit_reason'] = $data['reason'];
            $note = Document::create($totals + ['company_id' => $source->company_id, 'created_by' => $actor->id,
                'source_document_id' => $id, 'credit_reason' => $data['reason'], 'doc_type' => '07',
                'currency' => $source->currency, 'customer_id' => $source->customer_id, 'series_id' => $series->id,
                'issue_date' => now('America/Lima')->toDateString(), 'status' => 'draft', 'version' => 1, 'fiscal_snapshot' => $snapshot]);
            foreach ($lines as $line) {
                $copy = $note->items()->create($line->except(['id', 'document_id']));
                DB::table('credit_note_lines')->insert(['company_id' => $source->company_id, 'source_document_id' => $id,
                    'source_item_id' => $line->id, 'note_id' => $note->id, 'note_item_id' => $copy->id]);
            }

            return $note;
        }, 3);
    }

    private function checkAvailable(Document $source, array $ids): void
    {
        if (DB::table('credit_note_lines')->where('company_id', $source->company_id)->where('source_document_id', $source->id)
            ->whereIn('source_item_id', $ids)->whereIn('state', ['reserved', 'applied'])->exists()) {
            RequestIssuance::conflict('Una línea seleccionada ya está reservada o acreditada.');
        }
    }

    public function reserve(Document $note): void
    {
        $source = Document::where('company_id', $note->company_id)->lockForUpdate()->findOrFail($note->source_document_id);
        self::series($source)->whereKey($note->series_id)->firstOrFail();
        $rows = DB::table('credit_note_lines')->where('company_id', $source->company_id)->where('note_id', $note->id)->where('state', 'draft');
        $ids = (clone $rows)->pluck('source_item_id')->all();
        $this->checkAvailable($source, $ids);
        $amount = (int) $source->items()->whereIn('id', $ids)->sum('total_cents');
        $exposure = self::exposure($source->company_id)->where('c.source_document_id', $source->id)->first();
        if (! $ids || $amount !== $note->total_cents || $amount > $source->total_cents - ($exposure->accepted_cents ?? 0) - ($exposure->reserved_cents ?? 0)) {
            RequestIssuance::conflict('El importe supera el crédito disponible.');
        }
        $rows->update(['state' => 'reserved']);
    }

    public static function settle(Document $note): void
    {
        $state = match (true) {
            $note->status === 'accepted' => 'applied',
            $note->status === 'rejected', $note->failure_kind === 'permanent' => 'released',
            default => null
        };
        if ($state) {
            Document::where('company_id', $note->company_id)->whereKey($note->source_document_id)->lockForUpdate()->firstOrFail();
            DB::table('credit_note_lines')->where('note_id', $note->id)->where('company_id', $note->company_id)
                ->where('state', 'reserved')->update(['state' => $state]);
        }
    }
}
