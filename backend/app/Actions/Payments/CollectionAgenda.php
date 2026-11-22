<?php

namespace App\Actions\Payments;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CollectionAgenda
{
    public const TASKS = ['action_today', 'action_late', 'promise_late', 'unassigned'];

    public static function people(User $user, bool $eligibleOnly)
    {
        $query = User::where('company_id', $user->company_id);
        if ($eligibleOnly) {
            $query->where('is_active', true)->whereIn('role', ['owner', 'issuer']);
        }

        return $query->orderBy('name')->orderBy('id')->get(['id', 'name', 'is_active', 'role']);
    }

    public function page(User $actor, array $filters, string $today): array
    {
        $company = $actor->company_id;
        $currency = $filters['currency'] ?? 'PEN';
        $mine = ($filters['scope'] ?? 'mine') === 'mine';
        $agreements = PromiseEvidence::promises($company)->where('c.status', 'active');
        $base = DocumentBalances::portfolio($company, $currency)->where('d.outstanding_cents', '>', 0)
            ->leftJoin('collection_followups as f', fn ($j) => $j->on('f.company_id', 'd.company_id')->on('f.document_id', 'd.id'))
            ->leftJoin('users as u', fn ($j) => $j->on('u.company_id', 'f.company_id')->on('u.id', 'f.assignee_id'))
            ->leftJoinSub($agreements, 'p', fn ($j) => $j->on('p.company_id', 'd.company_id')->on('p.document_id', 'd.id'))
            ->select('d.id', 'f.next_action_on', 'u.name as assignee', 'd.due_date', 'd.needs_review')
            ->selectRaw("d.outstanding_cents::text AS outstanding_cents,
                d.series_code || '-' || lpad(d.number::text,8,'0') AS number,
                coalesce(d.fiscal_snapshot #>> '{customer,name}', 'Sin cliente identificado') AS customer,
                (u.id IS NULL OR NOT u.is_active OR u.role NOT IN ('issuer','owner')) AS unassigned,
                CASE WHEN p.amount_cents > p.cash_cents THEN p.due_on END AS promise_due");
        if ($mine) {
            $base->where('f.assignee_id', $actor->id);
        }
        if (isset($filters['assignee'])) {
            $base->where('f.assignee_id', $filters['assignee']);
        }
        if (isset($filters['q'])) {
            $pattern = '%'.addcslashes($filters['q'], '\\%_').'%';
            $base->whereRaw("(d.fiscal_snapshot #>> '{customer,name}' ILIKE ? OR d.fiscal_snapshot #>> '{customer,doc_number}' ILIKE ?)", [$pattern, $pattern]);
        }

        // Apply the same inclusive window separately to the action and to the promise.
        $dates = DB::query()->fromSub($base, 'b')->select('b.*');
        foreach (['next_action_on' => 'action_date', 'promise_due' => 'promise_date'] as $source => $alias) {
            $dates->selectRaw("CASE WHEN $source BETWEEN coalesce(?::date, '-infinity'::date) AND coalesce(?::date, 'infinity'::date) THEN $source END AS $alias",
                [$filters['from'] ?? null, $filters['to'] ?? null]);
        }
        $flags = DB::query()->fromSub($dates, 'a')->select('a.*')->selectRaw('action_date = ?::date AS action_today,
            action_date < ?::date AS action_late, promise_date < ?::date AS promise_late', [$today, $today, $today]);
        $list = DB::query()->fromSub($flags, 't');
        if (isset($filters['from']) || isset($filters['to'])) {
            $list->where(fn ($q) => $q->whereNotNull('action_date')->orWhereNotNull('promise_date'));
        }
        $totals = (clone $list)->selectRaw('count(*) AS total');
        foreach (self::TASKS as $task) {
            $totals->selectRaw("count(*) FILTER (WHERE $task) AS $task");
        }
        $counts = (array) $totals->first();
        $task = $filters['task'] ?? null;
        if ($task) {
            $list->where($task, true);
        }
        $total = (int) $counts[$task ?? 'total'];
        $last = max(1, (int) ceil($total / 20));
        $page = min((int) ($filters['page'] ?? 1), $last);
        $rows = $list->orderByRaw('CASE WHEN promise_late THEN 0 WHEN action_late THEN 1 WHEN action_today THEN 2 WHEN unassigned THEN 3 ELSE 4 END')
            ->orderByRaw('least(action_date,promise_date) ASC NULLS LAST')->orderBy('id')->forPage($page, 20)->get();

        return ['rows' => $rows, 'counts' => $counts, 'total' => $total, 'page' => $page, 'last_page' => $last,
            'today' => $today, 'currency' => $currency, 'people' => $mine ? [] : self::people($actor, false)];
    }
}
