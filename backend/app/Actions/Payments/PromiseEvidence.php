<?php

namespace App\Actions\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PromiseEvidence
{
    public static function baseline(object $doc): string
    {
        return DB::query()->fromSub(DocumentCash::entries(), 'p')->where(['company_id' => $doc->company_id, 'document_id' => $doc->id, 'currency' => $doc->currency])
            ->where('applied_cents', '>', 0)
            ->selectRaw("coalesce(jsonb_object_agg(id::text, true), '{}'::jsonb) as ids")->first()->ids;
    }

    private static function payments(): Builder
    {
        return DB::query()->fromSub(DocumentCash::entries(), 'p')->whereColumn('p.company_id', 'c.company_id')
            ->whereColumn('p.document_id', 'c.document_id')->whereColumn('p.currency', 'c.currency')
            ->where('p.status', 'approved')->where('p.needs_review', false)
            ->whereColumn('p.funds_confirmed_at', '>', 'c.opened_at')
            ->whereRaw("(p.bank_date IS NULL OR p.bank_date >= (c.opened_at AT TIME ZONE 'America/Lima')::date)")
            ->where('p.refunded_cents', 0)->where('p.applied_cents', '>', 0)
            ->whereColumn('p.confirmed_at', '>', 'c.opened_at')->whereRaw('p.confirmed_at <= statement_timestamp()')
            ->whereRaw('NOT jsonb_exists(c.preexisting, p.id::text)');
    }

    /** A closed agreement retains only the portion of each payment actually allocated at closure. */
    public static function promises(string $company): Builder
    {
        $cash = self::payments()->selectRaw("coalesce(sum(CASE WHEN c.status='fulfilled'
            THEN least(p.applied_cents, coalesce((c.evidence->>p.id::text)::bigint,0))
            ELSE p.applied_cents END),0)")->whereRaw('(c.closed_at IS NULL OR p.confirmed_at <= c.closed_at)');

        $query = DB::table('collection_promises as c')->where('c.company_id', $company)
            ->select('c.id', 'c.company_id', 'c.document_id', 'c.currency', 'c.amount_cents', 'c.due_on', 'c.opened_at', 'c.status', 'c.credits_at_terms')
            ->selectSub($cash, 'cash_cents');
        if (DocumentCash::bankInstalled()) {
            $query->selectRaw("EXISTS (SELECT 1 FROM bank_applications a JOIN bank_receipts r ON (r.company_id,r.id)=(a.company_id,a.receipt_id)
                WHERE a.company_id=c.company_id AND a.document_id=c.document_id AND a.currency=c.currency AND a.applied_at>c.opened_at
                  AND r.confirmed_at>c.opened_at AND r.bank_date >= (c.opened_at AT TIME ZONE 'America/Lima')::date
                  AND a.reversed_at>coalesce(c.terms_reviewed_at,c.opened_at)) AS bank_reversal");
        }

        return $query;
    }

    public static function allocations(string $company, string $document, string $promise, int $target): array
    {
        $rows = self::payments()->crossJoin('collection_promises as c')
            ->where(['c.company_id' => $company, 'c.document_id' => $document, 'c.id' => $promise, 'c.status' => 'active'])
            ->orderBy('p.confirmed_at')->orderBy('p.id')->get(['p.id', 'p.applied_cents']);
        $allocated = [];
        foreach ($rows as $row) {
            $amount = min($target, (int) $row->applied_cents);
            $allocated[$row->id] = $amount;
            $target -= $amount;
            if ($target === 0) {
                return $allocated;
            }
        }
        abort(409, 'Los pagos confirmados todavía no cubren el compromiso.');
    }

    public static function progress(object $promise, object $balance, string $today): array
    {
        $covered = min((int) $promise->amount_cents, (int) $promise->cash_cents);
        $remaining = $promise->amount_cents - $covered;
        $review = $balance->needs_review || $remaining > $balance->outstanding_cents
            || ($remaining > 0 && ((int) $promise->credits_at_terms !== (int) $balance->credits_cents || ($promise->bank_reversal ?? false)));

        return ['id' => $promise->id, 'amount_cents' => (string) $promise->amount_cents, 'due_on' => $promise->due_on,
            'opened_at' => CarbonImmutable::parse($promise->opened_at)->toISOString(), 'covered_cents' => (string) $covered, 'remaining_cents' => (string) $remaining,
            'overdue' => $remaining > 0 && $promise->due_on < $today, 'review' => $review, 'can_fulfill' => ! $review && $remaining === 0];
    }
}
