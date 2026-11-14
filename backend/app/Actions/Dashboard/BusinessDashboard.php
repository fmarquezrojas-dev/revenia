<?php

namespace App\Actions\Dashboard;

use App\Actions\Subscriptions\Subscriptions;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BusinessDashboard
{
    public const METRICS = ['accepted', 'rejected', 'payments', 'outstanding', 'excess', 'review', 'queued', 'failed', 'pdf_failed', 'email_failed'];

    private function documents(string $company, string $currency): Builder
    {
        return DB::table('documents as d')->where('d.company_id', $company)->where('d.currency', $currency)->whereIn('d.doc_type', ['01', '03']);
    }

    private function events(string $company, string $status): Builder
    {
        return DB::table('document_events')->where('company_id', $company)->where('to_status', $status)->select('document_id')->selectRaw('min(created_at) as occurred_at')->groupBy('document_id');
    }

    private function activity(string $company, string $currency, string $status, string $start, string $end): Builder
    {
        return $this->documents($company, $currency)->joinSub($this->events($company, $status), 'e', 'e.document_id', '=', 'd.id')->where('e.occurred_at', '>=', $start)->where('e.occurred_at', '<', $end);
    }

    private function payments(string $company, string $currency, string $start, string $end): Builder
    {
        return $this->documents($company, $currency)->join('payments as p', function ($join) {
            $join->on('p.document_id', '=', 'd.id')->on('p.company_id', '=', 'd.company_id');
        })->whereNull('p.subscription_id')->where('p.currency', $currency)->where('p.applied_cents', '>', 0)->where('p.confirmed_at', '>=', $start)->where('p.confirmed_at', '<', $end);
    }

    private function portfolio(string $company, string $currency): Builder
    {
        $paid = DB::table('payments')->where('company_id', $company)->whereNotNull('document_id')->whereNull('subscription_id')->where('currency', $currency)->select('document_id')->selectRaw('sum(applied_cents)::numeric as paid, bool_or(needs_review) as review')->groupBy('document_id');
        $work = DB::table('payment_work as w')->join('payment_orders as o', 'o.id', '=', 'w.order_id')->where('o.company_id', $company)->where('w.company_id', $company)->whereNotNull('o.document_id')->whereIn('w.status', ['review', 'failed'])->select('o.document_id')->distinct();
        $rows = $this->documents($company, $currency)->where('d.status', 'accepted')->leftJoinSub($paid, 'p', 'p.document_id', '=', 'd.id')->leftJoinSub($work, 'w', 'w.document_id', '=', 'd.id')->select('d.*')->selectRaw('greatest(d.total_cents::numeric - coalesce(p.paid,0),0) as outstanding_cents, greatest(coalesce(p.paid,0) - d.total_cents::numeric,0) as excess_cents, (coalesce(p.review,false) OR coalesce(p.paid,0) > d.total_cents OR w.document_id IS NOT NULL) as needs_review');

        return DB::query()->fromSub($rows, 'd');
    }

    public function selection(string $company, array $f, string $metric): Builder
    {
        $currency = $f['currency'];
        $start = $f['start_utc'];
        $end = $f['end_exclusive_utc'];
        if (in_array($metric, ['accepted', 'rejected'], true)) {
            return $this->activity($company, $currency, $metric, $start, $end)->select('d.*')->selectRaw('d.total_cents::text as amount_cents, e.occurred_at as relevant_at, d.id as row_id');
        }
        if ($metric === 'payments') {
            return $this->payments($company, $currency, $start, $end)->select('d.*')->selectRaw('p.applied_cents::text as amount_cents, p.confirmed_at as relevant_at, p.id as row_id, p.status as payment_status, p.needs_review as payment_review');
        }
        if (in_array($metric, ['outstanding', 'excess', 'review'], true)) {
            $q = $this->portfolio($company, $currency);
            if ($metric === 'review') {
                $q->where('d.needs_review', true);
            } else {
                $q->where('d.'.$metric.'_cents', '>', 0);
            }
            $amount = $metric === 'review' ? 'd.total_cents' : 'd.'.$metric.'_cents';

            return $q->select('d.*')->selectRaw($amount.'::text as amount_cents, d.issued_at as relevant_at, d.id as row_id');
        }
        $q = $this->documents($company, $currency);
        if ($metric === 'queued') {
            $q->whereIn('d.status', ['queued', 'sending']);
        } elseif ($metric === 'failed') {
            $q->where('d.status', 'failed');
        } else {
            $table = $metric === 'pdf_failed' ? 'document_pdfs' : 'document_email_deliveries';
            $q->whereExists(function ($sub) use ($table, $metric): void {
                $sub->selectRaw('1')->from($table.' as t')->whereColumn('t.document_id', 'd.id')->whereColumn('t.company_id', 'd.company_id')->whereIn('t.status', $metric === 'pdf_failed' ? ['failed'] : ['failed', 'unknown']);
            });
        }

        return $q->select('d.*')->selectRaw('d.total_cents::text as amount_cents, d.issued_at as relevant_at, d.id as row_id');
    }

    private function row(object $row): array
    {
        $snapshot = json_decode($row->fiscal_snapshot ?? '{}', true);

        return ['id' => $row->row_id, 'document_id' => $row->id, 'number' => $row->number ? $row->series_code.'-'.str_pad((string) $row->number, 8, '0', STR_PAD_LEFT) : 'Borrador', 'customer' => $snapshot['customer']['name'] ?? 'Sin cliente identificado', 'amount_cents' => (string) $row->amount_cents, 'currency' => $row->currency, 'status' => $row->status, 'payment_status' => $row->payment_status ?? null, 'needs_review' => $row->payment_review ?? $row->needs_review ?? false, 'relevant_at' => $row->relevant_at, 'href' => '/documents/'.$row->id];
    }

    public function details(string $company, array $f, string $metric, int $page): array
    {
        $query = $this->selection($company, $f, $metric);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('relevant_at')->orderBy('row_id')->forPage($page, 20)->get();

        return ['metric' => $metric, 'rows' => $rows->map(fn ($row) => $this->row($row)), 'page' => $page, 'per_page' => 20, 'total' => $total, 'last_page' => max(1, (int) ceil($total / 20))];
    }

    public function summary(User $user, array $f): array
    {
        $company = $user->company_id;
        $accepted = $this->activity($company, $f['currency'], 'accepted', $f['start_utc'], $f['end_exclusive_utc']);
        $payments = $this->payments($company, $f['currency'], $f['start_utc'], $f['end_exclusive_utc']);
        $emitted = (clone $accepted)->selectRaw('count(*) as count, coalesce(sum(d.total_cents),0)::text as cents')->first();
        $collected = (clone $payments)->selectRaw('count(*) as count, coalesce(sum(p.applied_cents),0)::text as cents')->first();
        $portfolio = $this->portfolio($company, $f['currency'])->selectRaw('coalesce(sum(outstanding_cents),0)::text as outstanding_cents, count(*) FILTER (WHERE outstanding_cents > 0) as outstanding_count, coalesce(sum(excess_cents),0)::text as excess_cents, count(*) FILTER (WHERE excess_cents > 0) as excess_count, count(*) FILTER (WHERE needs_review) as review_count')->first();
        $emissionDays = (clone $accepted)->selectRaw("(e.occurred_at AT TIME ZONE 'America/Lima')::date as day, sum(d.total_cents)::text as cents")->groupByRaw("(e.occurred_at AT TIME ZONE 'America/Lima')::date")->pluck('cents', 'day');
        $paymentDays = (clone $payments)->selectRaw("(p.confirmed_at AT TIME ZONE 'America/Lima')::date as day, sum(p.applied_cents)::text as cents")->groupByRaw("(p.confirmed_at AT TIME ZONE 'America/Lima')::date")->pluck('cents', 'day');
        $series = [];
        for ($day = CarbonImmutable::parse($f['from'], 'America/Lima'); $day->toDateString() <= $f['to']; $day = $day->addDay()) {
            $date = $day->toDateString();
            $series[] = ['date' => $date, 'accepted_cents' => (string) ($emissionDays[$date] ?? '0'), 'collected_cents' => (string) ($paymentDays[$date] ?? '0')];
        }
        $alerts = [];
        foreach (['queued', 'failed', 'pdf_failed', 'email_failed'] as $key) {
            $alerts[$key] = $this->selection($company, $f, $key)->count();
        }

        return ['currency' => $f['currency'], 'period' => $f, 'timezone' => 'America/Lima', 'calculated_at' => now()->toIso8601String(), 'activity' => ['accepted_cents' => $emitted->cents, 'accepted_count' => (int) $emitted->count, 'credit_notes_cents' => null, 'credit_notes_supported' => false, 'collected_cents' => $collected->cents, 'payments_count' => (int) $collected->count, 'rejected_count' => $this->selection($company, $f, 'rejected')->count()], 'portfolio' => $portfolio, 'series' => $series, 'alerts' => $alerts, 'recent' => $this->selection($company, $f, 'accepted')->orderByDesc('relevant_at')->orderBy('row_id')->limit(5)->get()->map(fn ($row) => $this->row($row)), 'usage' => app(Subscriptions::class)->usage($company), 'actions' => ['create_document' => $user->can('create', Document::class), 'manage_subscription' => $user->can('manage-subscription')]];
    }
}
