<?php

namespace App\Actions\Payments;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Receivables
{
    public const SITUATIONS = ['upcoming', 'today', 'overdue', 'undated', 'late_1_30', 'late_31_60', 'late_61_90', 'late_over_90'];

    private const PAGE_SIZE = 20;

    public static function filterRules(bool $hasDueFrom): array
    {
        $rules = [
            'currency' => ['sometimes', 'required', Rule::in(['PEN', 'USD'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer_id' => ['sometimes', 'required', 'uuid'],
            'operations' => ['sometimes', 'required', Rule::in(['pending', 'review'])],
            'situation' => ['sometimes', 'required', Rule::in(self::SITUATIONS)],
            'due_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'required', Rule::in(['oldest', 'newest'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
        ];
        if ($hasDueFrom) {
            $rules['due_to'][] = 'after_or_equal:due_from';
        }

        return $rules;
    }

    private function selection(string $company, string $currency, string $cutoff, array $filters): Builder
    {
        $balances = DocumentBalances::portfolio($company, $currency, $filters['customer_id'] ?? null)
            ->select('d.id', 'd.customer_id', 'd.currency', 'd.issue_date', 'd.due_date', 'd.outstanding_cents', 'd.pending_confirmation', 'd.needs_review')
            ->selectRaw("coalesce(d.fiscal_snapshot #>> '{customer,name}', 'Sin cliente identificado') as customer,
                d.fiscal_snapshot #>> '{customer,doc_number}' as customer_number,
                d.series_code || '-' || lpad(d.number::text, 8, '0') as number,
                cast(? as date) - d.due_date as age_days", [$cutoff]);

        match ($filters['operations'] ?? '') {
            'review' => $balances->where('d.needs_review', true),
            'pending' => $balances->where('d.pending_confirmation', true),
            default => $balances->where('d.outstanding_cents', '>', 0),
        };
        if (isset($filters['due_from'])) {
            $balances->where('d.due_date', '>=', $filters['due_from']);
        }
        if (isset($filters['due_to'])) {
            $balances->where('d.due_date', '<=', $filters['due_to']);
        }
        $aged = DB::query()->fromSub($balances, 'b')->select('b.*')->selectRaw("CASE
            WHEN outstanding_cents = 0 THEN 'settled' WHEN age_days IS NULL THEN 'undated' WHEN age_days < 0 THEN 'upcoming'
            WHEN age_days = 0 THEN 'today' WHEN age_days <= 30 THEN 'late_1_30'
            WHEN age_days <= 60 THEN 'late_31_60' WHEN age_days <= 90 THEN 'late_61_90'
            ELSE 'late_over_90' END as bucket");
        $query = DB::query()->fromSub($aged, 'a');
        if (isset($filters['q'])) {
            $pattern = '%'.addcslashes($filters['q'], '\\%_').'%';
            $query->where(function (Builder $q) use ($pattern): void {
                $q->where('customer', 'ilike', $pattern)->orWhere('number', 'ilike', $pattern)
                    ->orWhere('customer_number', 'ilike', $pattern);
            });
        }
        if (isset($filters['situation'])) {
            if ($filters['situation'] === 'overdue') {
                $query->where('age_days', '>', 0)->where('outstanding_cents', '>', 0);
            } else {
                $query->where('bucket', $filters['situation']);
            }
        }

        return $query;
    }

    public function current(string $company, array $filters, string $cutoff): array
    {
        $currency = $filters['currency'] ?? 'PEN';
        $query = $this->selection($company, $currency, $cutoff, $filters);
        $totals = (clone $query)->selectRaw('count(*) as documents, coalesce(sum(outstanding_cents), 0)::text as total');
        foreach (self::SITUATIONS as $key) {
            $predicate = $key === 'overdue' ? 'age_days > 0' : 'bucket = ?';
            $totals->selectRaw("coalesce(sum(outstanding_cents) FILTER (WHERE $predicate), 0)::text as $key", $key === 'overdue' ? [] : [$key]);
        }
        $summary = (array) $totals->first();
        $count = (int) $summary['documents'];
        unset($summary['documents']);
        $lastPage = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = min((int) ($filters['page'] ?? 1), $lastPage);
        $direction = ($filters['sort'] ?? 'oldest') === 'oldest' ? 'asc' : 'desc';
        $rows = $query->select('id', 'customer_id', 'customer', 'number', 'currency', 'issue_date', 'due_date', 'bucket', 'needs_review', 'pending_confirmation')
            ->selectRaw('outstanding_cents::text as outstanding_cents, CASE WHEN outstanding_cents > 0 AND age_days IS NOT NULL THEN greatest(age_days, 0) END as days_late')
            ->orderByRaw("due_date $direction NULLS LAST")->orderBy('issue_date', $direction)->orderBy('id')
            ->forPage($page, self::PAGE_SIZE)->get();

        return ['currency' => $currency, 'cutoff' => $cutoff, 'timezone' => 'America/Lima', 'summary' => $summary,
            'rows' => $rows, 'page' => $page, 'last_page' => $lastPage, 'per_page' => self::PAGE_SIZE, 'total' => $count];
    }
}
