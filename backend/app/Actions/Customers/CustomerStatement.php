<?php

namespace App\Actions\Customers;

use App\Actions\Payments\DocumentBalances;
use App\Actions\Payments\DocumentCash;
use App\Actions\Treasury\BankLedger;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CustomerStatement
{
    private const PAGE_SIZE = 20;

    private function movements(Customer $customer, string $currency, ?string $document, bool $bank): Builder
    {
        $sql = file_get_contents(__DIR__.'/statement-movements.sql');
        if ($bank) {
            // The original application belongs to the sale even after reversal and reassignment of a receipt.
            $sql = str_replace('/* bank applications */', <<<'SQL'
                UNION ALL
                SELECT s.id,event.kind,a.id,r.id,event.occurred_at,r.reference,a.amount_cents,NULL,false
                FROM sales s
                JOIN bank_applications a ON (a.company_id,a.document_id,a.currency)=(s.company_id,s.id,s.currency)
                JOIN bank_receipts r ON (r.company_id,r.id)=(a.company_id,a.receipt_id)
                CROSS JOIN LATERAL (VALUES ('bank_application',a.applied_at),('bank_reversal',a.reversed_at))
                    AS event(kind,occurred_at)
                WHERE event.occurred_at IS NOT NULL
                SQL, $sql);
        }

        return DB::query()->fromRaw('('.$sql.') AS m', [$customer->company_id, $customer->id, $currency, $document, $document]);
    }

    private function listing(Builder $query, int $page, bool $print, ?int $count = null): array
    {
        $count ??= (clone $query)->count();
        $last = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = $print ? 1 : min($page, $last);
        if (! $print) {
            $query->forPage($page, self::PAGE_SIZE);
        }

        return ['rows' => $query->get(), 'page' => $page, 'last_page' => $last, 'total' => $count];
    }

    public function read(Customer $customer, array $filters, CarbonImmutable $now): array
    {
        $currency = $filters['currency'] ?? 'PEN';
        $from = $filters['from'] ?? $now->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $now->toDateString();
        $print = ($filters['view'] ?? '') === 'print';
        $bank = DocumentCash::bankInstalled();
        $base = DocumentBalances::portfolio($customer->company_id, $currency, $customer->id);
        $totals = (clone $base)->selectRaw('count(*) as documents, count(*) filter (where needs_review) as review,
            coalesce(sum(outstanding_cents),0)::text as outstanding_cents,
            coalesce(sum(outstanding_cents) filter (where due_date < ?::date),0)::text as overdue_cents,
            coalesce(sum(excess_cents),0)::text as excess_cents', [$now->toDateString()])->first();
        $documents = $base->select('d.id', 'd.issue_date', 'd.due_date', 'd.needs_review', 'd.pending_confirmation')
            ->selectRaw("d.series_code||'-'||lpad(d.number::text,8,'0') as number,
                d.total_cents::text, d.credits_cents::text, d.reserved_cents::text, d.paid_cents::text,
                d.excess_cents::text, d.outstanding_cents::text")
            ->orderByDesc('d.issue_date')->orderBy('d.id');
        $document = $filters['document'] ?? null;
        $selection = $document ? (clone $documents)->where('d.id', $document)->firstOrFail() : null;
        $activity = $this->movements($customer, $currency, $document, $bank)
            ->where('occurred_at', '>=', CarbonImmutable::parse($from, $now->timezone)->startOfDay()->toIso8601String())
            ->where('occurred_at', '<', CarbonImmutable::parse($to, $now->timezone)->addDay()->startOfDay()->toIso8601String())
            ->orderByDesc('occurred_at')->orderBy('document_id')->orderBy('kind')->orderBy('source_id');

        $receipts = null;
        $totals->available_cents = null;
        if ($bank) {
            $available = BankLedger::availableReceipts($customer->company_id, $currency)->where('customer_id', $customer->id);
            $amount = (clone $available)->selectRaw('count(*) as receipts, coalesce(sum(available_cents::bigint),0)::text as cents')->first();
            $totals->available_cents = $amount->cents;
            $receipts = $this->listing($available->select('id', 'bank_date', 'reference', 'amount_cents', 'applied_cents', 'available_cents')
                ->orderByDesc('bank_date')->orderBy('id'), $filters['receipts_page'] ?? 1, $print, $amount->receipts);
        }

        return ['customer' => $customer->only('id', 'name', 'doc_type', 'doc_number'),
            'company' => $customer->company()->firstOrFail(['legal_name', 'ruc'])->only('legal_name', 'ruc'),
            'currency' => $currency, 'calculated_at' => $now->toIso8601String(), 'timezone' => $now->timezoneName,
            'period' => ['from' => $from, 'to' => $to], 'movement_document' => $selection?->number, 'summary' => $totals,
            'documents' => $this->listing($documents, $filters['page'] ?? 1, $print, $totals->documents),
            'movements' => $this->listing($activity, $filters['movements_page'] ?? 1, $print), 'receipts' => $receipts];
    }
}
