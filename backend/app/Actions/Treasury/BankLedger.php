<?php

namespace App\Actions\Treasury;

use App\Actions\Payments\DocumentBalances;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BankLedger
{
    public const STATES = ['confirmed', 'pending', 'settlement', 'unidentified', 'available', 'partial', 'applied'];

    public static function accounts(string $company)
    {
        return DB::table('bank_accounts')->where('company_id', $company)->orderByDesc('active')->orderBy('alias')->orderBy('id')
            ->get(['id', 'alias', 'institution', 'currency', 'active', 'revision']);
    }

    private static function receipts(string $company): Builder
    {
        $rows = DB::table('bank_receipts as r')->where('r.company_id', $company)
            ->join('bank_accounts as a', fn ($j) => $j->on('a.company_id', 'r.company_id')->on('a.id', 'r.account_id'))
            ->leftJoin('customers as c', fn ($j) => $j->on('c.company_id', 'r.company_id')->on('c.id', 'r.customer_id'))
            ->select('r.id', 'r.account_id', 'r.customer_id', 'r.currency', 'r.bank_date', 'r.method', 'r.origin', 'r.reference', 'r.revision',
                'r.recorded_at', 'r.confirmed_at', 'a.alias', 'c.name as customer')
            ->selectRaw("r.amount_cents::text, r.applied_cents::text,
                CASE WHEN r.confirmed_at IS NOT NULL AND r.origin='customer' THEN r.amount_cents-r.applied_cents ELSE 0 END::text AS available_cents,
                CASE WHEN r.confirmed_at IS NULL THEN 'pending' WHEN r.origin='mp_settlement' THEN 'settlement'
                  WHEN r.customer_id IS NULL THEN 'unidentified' WHEN r.applied_cents=0 THEN 'available'
                  WHEN r.applied_cents=r.amount_cents THEN 'applied' ELSE 'partial' END AS situation");

        return DB::query()->fromSub($rows, 'b');
    }

    public static function availableReceipts(string $company, string $currency): Builder
    {
        return self::receipts($company)->where('currency', $currency)->where('origin', 'customer')
            ->whereNotNull('confirmed_at')->whereRaw('available_cents::bigint > 0');
    }

    public static function inbox(string $company, array $filters): array
    {
        $query = self::receipts($company);
        if (isset($filters['q'])) {
            $query->where('customer', 'ilike', '%'.addcslashes($filters['q'], '\\%_').'%');
        }
        foreach (['account_id', 'customer_id', 'currency', 'situation'] as $key) {
            if (isset($filters[$key])) {
                if ($key === 'situation' && $filters[$key] === 'confirmed') {
                    $query->whereNotNull('confirmed_at');
                } else {
                    $query->where($key, $filters[$key]);
                }
            }
        }
        if (isset($filters['from'])) {
            $query->where('bank_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('bank_date', '<=', $filters['to']);
        }

        return self::page($query->orderByDesc('bank_date')->orderByDesc('recorded_at')->orderBy('id'), $filters)
            + ['accounts' => self::accounts($company)];
    }

    private static function page(Builder $query, array $filters): array
    {
        $total = (clone $query)->count();
        $last = max(1, (int) ceil($total / 20));
        $page = min((int) ($filters['page'] ?? 1), $last);

        return ['rows' => $query->forPage($page, 20)->get(), 'total' => $total, 'page' => $page, 'last_page' => $last];
    }

    public static function detail(string $company, string $id, array $filters): array
    {
        $receipt = self::receipts($company)->where('id', $id)->firstOrFail();
        $note = DB::table('bank_receipts')->where(['company_id' => $company, 'id' => $id])->value('note');
        $events = DB::table('bank_operations as o')->where('o.company_id', $company)->where('o.receipt_id', $id)
            ->join('users as u', fn ($j) => $j->on('u.company_id', 'o.company_id')->on('u.id', 'o.actor_id'))
            ->select('o.request_id', 'o.action', 'o.recorded_at', 'o.details', 'u.name as actor')
            ->orderByDesc('o.recorded_at')->orderBy('o.request_id');
        $history = self::page($events, ['page' => $filters['history_page'] ?? 1]);
        foreach ($history['rows'] as $event) {
            $event->details = json_decode($event->details);
        }
        $apps = DB::table('bank_applications as a')->where('a.company_id', $company)->where('a.receipt_id', $id)
            ->join('documents as d', fn ($j) => $j->on('d.company_id', 'a.company_id')->on('d.id', 'a.document_id'))
            ->join('users as u', fn ($j) => $j->on('u.company_id', 'a.company_id')->on('u.id', 'a.applied_by'))
            ->leftJoin('users as v', fn ($j) => $j->on('v.company_id', 'a.company_id')->on('v.id', 'a.reversed_by'))
            ->select('a.id', 'a.document_id', 'a.applied_at', 'a.reversed_at', 'a.reason', 'u.name as actor', 'v.name as reversed_by', 'a.batch_id')
            ->selectRaw("a.amount_cents::text, d.series_code||'-'||lpad(d.number::text,8,'0') AS number")
            ->orderByDesc('a.applied_at')->orderBy('a.id');

        if (isset($filters['application'])) {
            $target = (clone $apps)->where('a.id', $filters['application'])->firstOrFail();
            $before = (clone $apps)->reorder()->where(fn ($q) => $q->where('a.applied_at', '>', $target->applied_at)
                ->orWhere(fn ($same) => $same->where('a.applied_at', $target->applied_at)->where('a.id', '<', $target->id)))->count();
            $filters['page'] = intdiv($before, 20) + 1;
        }

        return ['receipt' => $receipt, 'note' => $note, 'history' => $history, 'applications' => self::page($apps, $filters)];
    }

    public static function candidates(string $company, string $id, array $filters): array
    {
        $r = self::receipts($company)->where('id', $id)->firstOrFail();
        abort_unless($r->confirmed_at && $r->customer_id && $r->origin === 'customer', 409, 'Confirma e identifica el abono.');
        $q = DocumentBalances::portfolio($company, $r->currency)->where('d.customer_id', $r->customer_id)->where('d.outstanding_cents', '>', 0)
            ->select('d.id', 'd.due_date', 'd.needs_review', 'd.pending_confirmation')
            ->selectRaw("d.series_code||'-'||lpad(d.number::text,8,'0') AS number, d.outstanding_cents::text, d.reserved_cents::text,
                greatest(d.outstanding_cents-d.reserved_cents,0)::text AS available_cents");

        return self::page($q->orderBy('d.due_date')->orderBy('d.id'), $filters);
    }

    public static function indicators(string $company, array $period): array
    {
        $confirmed = DB::table('bank_receipts')->where('company_id', $company)->where('currency', $period['currency'])->whereNotNull('confirmed_at');
        $income = (clone $confirmed)->whereBetween('bank_date', [$period['from'], $period['to']])->selectRaw("count(*) as receipts,
            coalesce(sum(amount_cents),0)::text as received_cents,
            coalesce(sum(amount_cents) FILTER (WHERE origin='mp_settlement'),0)::text as settlement_cents")->first();
        $applications = DB::table('bank_applications')->where('company_id', $company)->where('currency', $period['currency'])
            ->whereNull('reversed_at')->where('applied_at', '>=', $period['start_utc'])->where('applied_at', '<', $period['end_exclusive_utc']);
        $available = self::availableReceipts($company, $period['currency'])->selectRaw('coalesce(sum(available_cents::bigint),0)::text as cents')->first();

        return (array) $income + ['applied_cents' => (string) $applications->sum('amount_cents'), 'available_cents' => $available->cents];
    }
}
