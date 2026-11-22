<?php

namespace App\Actions\Payments;

use App\Actions\Documents\CreditNotes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DocumentBalances
{
    public static function portfolio(string $company, string $currency, ?string $customer = null): Builder
    {
        return self::query($company, $currency, customer: $customer)->where('d.status', 'accepted');
    }

    public static function query(string $company, string $currency, string|array|null $document = null, ?string $customer = null): Builder
    {
        $sales = DB::table('documents as d')->where('d.company_id', $company)
            ->where('d.currency', $currency)->whereIn('d.doc_type', ['01', '03']);
        $payments = DB::query()->fromSub(DocumentCash::entries(), 'cash')->where('company_id', $company)->where('currency', $currency)
            ->groupBy('document_id')
            ->select('document_id')->selectRaw("sum(applied_cents) as paid, bool_or(needs_review) as review,
                bool_or(status in ('pending','in_process','authorized')) as awaiting");
        $work = DB::table('payment_work as w')->join('payment_orders as o', 'o.id', '=', 'w.order_id')
            ->where('w.company_id', $company)->where('o.company_id', $company)
            ->whereNotNull('o.document_id')->whereIn('w.status', ['review', 'failed', 'pending', 'processing'])
            ->groupBy('o.document_id')->select('o.document_id')
            ->selectRaw("bool_or(w.status in ('review','failed')) as review, bool_or(w.status in ('pending','processing')) as pending");
        $orders = DB::table('payment_orders')->where('company_id', $company)->whereNotNull('document_id')
            ->where('provider', 'mercadopago')->groupBy('document_id')->select('document_id')
            ->selectRaw("bool_or(receiver_kind = 'legacy') as review,
                bool_or(status in ('pending','creating','unknown') OR (status='open' AND expires_at > now())) as awaiting");
        $credits = CreditNotes::exposure($company);
        if ($customer !== null) {
            $sales->where('d.customer_id', $customer);
        }
        if ($document !== null) {
            $sales->whereIn('d.id', (array) $document);
        }
        if ($document !== null || $customer !== null) {
            $ids = $document !== null ? (array) $document : (clone $sales)->select('d.id');
            $payments->whereIn('document_id', $ids);
            $orders->whereIn('document_id', $ids);
            $work->whereIn('o.document_id', $ids);
            $credits->whereIn('c.source_document_id', $ids);
        }

        $amounts = $sales->leftJoinSub($payments, 'p', 'p.document_id', '=', 'd.id')
            ->leftJoinSub($orders, 'o', 'o.document_id', '=', 'd.id')
            ->leftJoinSub($credits, 'c', 'c.source_document_id', '=', 'd.id')
            ->leftJoinSub($work, 'w', 'w.document_id', '=', 'd.id')
            ->select('d.*')->selectRaw('coalesce(c.accepted_cents, 0) as credits_cents,
                coalesce(c.reserved_cents, 0) as reserved_cents, coalesce(p.paid, 0) as paid_cents,
                d.total_cents - coalesce(c.accepted_cents, 0) as net_cents,
                (coalesce(p.awaiting,false) OR coalesce(o.awaiting,false) OR coalesce(w.pending,false)) as pending_confirmation,
                (coalesce(p.review,false) OR coalesce(w.review,false) OR coalesce(o.review,false)) as requires_reconciliation');
        $balances = DB::query()->fromSub($amounts, 'a')->select('a.*')
            ->selectRaw('greatest(net_cents - paid_cents, 0) as outstanding_cents,
                greatest(paid_cents - net_cents, 0) as excess_cents,
                (requires_reconciliation OR paid_cents > net_cents) as needs_review');

        return DB::query()->fromSub($balances, 'd');
    }
}
