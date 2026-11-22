<?php

namespace App\Actions\Incidents;

use App\Actions\Delivery\DocumentDelivery;
use App\Models\Document;
use App\Models\User;
use App\Services\MercadoPagoGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class IncidentInbox
{
    public const PROCESSES = ['emission', 'pdf', 'email', 'collection', 'odoo'];

    public const STATES = ['attention', 'all', 'processing', 'delayed', 'retry', 'manual', 'failed', 'confirmation', 'resolved'];

    public const ALERTS = ['queued', 'failed', 'pdf_failed', 'email_failed', 'collection_review', 'odoo_review'];

    private const ATTENTION = ['delayed', 'retry', 'manual', 'failed', 'confirmation'];

    public static function operations(string $company): Builder
    {
        $sql = file_get_contents(__DIR__.'/operations.sql');

        return DB::query()->fromRaw('('.$sql.') AS i', [$company]);
    }

    private static function alertExpression(): string
    {
        return "CASE WHEN process='emission' AND state IN ('processing','delayed') THEN 'queued'
            WHEN state IN ('delayed','retry','manual','failed','confirmation') THEN CASE process
            WHEN 'emission' THEN 'failed' WHEN 'pdf' THEN 'pdf_failed' WHEN 'email' THEN 'email_failed'
            WHEN 'collection' THEN 'collection_review' WHEN 'odoo' THEN 'odoo_review' END END";
    }

    public static function filtered(string $company, array $filters): Builder
    {
        $q = self::operations($company);
        $q->where(function ($visible) use ($company): void {
            $visible->where('i.state', '<>', 'resolved')->orWhereNotNull('i.first_detected_at')
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('incident_follow_ups as f')->where('f.company_id', $company)
                    ->whereColumn('f.process', 'i.process')->whereColumn('f.operation_id', 'i.operation_id'));
        });
        foreach (['process', 'currency'] as $column) {
            if (isset($filters[$column])) {
                $q->where('i.'.$column, $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $q->where('i.updated_at', '>=', CarbonImmutable::parse($filters['from'], 'America/Lima')->startOfDay()->utc()->toIso8601String());
        }
        if (isset($filters['to'])) {
            $q->where('i.updated_at', '<', CarbonImmutable::parse($filters['to'], 'America/Lima')->startOfDay()->addDay()->utc()->toIso8601String());
        }
        if (isset($filters['alert'])) {
            $q->whereRaw('('.self::alertExpression().') = ?', [$filters['alert']]);
        }
        $state = $filters['state'] ?? (isset($filters['alert']) ? 'all' : 'attention');
        if ($state === 'attention') {
            $q->whereIn('i.state', self::ATTENTION);
        } elseif ($state !== 'all') {
            $q->where('i.state', $state);
        }
        if (isset($filters['q'])) {
            $pattern = '%'.strtr(trim($filters['q']), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
            $q->where(function ($search) use ($pattern, $company): void {
                $search->where('i.number', 'ilike', $pattern)->orWhere('i.reference', 'ilike', $pattern)->orWhereRaw('i.operation_id::text ILIKE ?', [$pattern])
                    ->orWhereRaw('i.source_id::text ILIKE ?', [$pattern])->orWhereRaw('i.document_id::text ILIKE ?', [$pattern])
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('payments as p')->where('p.company_id', $company)
                        ->whereNull('p.subscription_id')->whereColumn('p.order_id', 'i.operation_id')->whereRaw("i.process='collection'")->where('p.provider_id', 'ilike', $pattern))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('document_email_deliveries as e')->where('e.company_id', $company)
                        ->whereColumn('e.document_id', 'i.document_id')->whereRaw("i.process='email' AND e.id::text ILIKE ?", [$pattern]));
            });
        }

        return $q;
    }

    public static function alerts(string $company, array $filters): array
    {
        $counts = self::filtered($company, $filters + ['state' => 'all'])->selectRaw(self::alertExpression().' AS alert, count(*) AS total')
            ->groupBy('alert')->get();
        $result = array_fill_keys(self::ALERTS, 0);
        foreach ($counts as $count) {
            if ($count->alert !== null) {
                $result[$count->alert] = (int) $count->total;
            }
        }

        return $result;
    }

    public static function find(string $company, string $process, string $id): object
    {
        $query = self::operations($company)->where('i.process', $process);
        $row = (clone $query)->where('i.operation_id', $id)->first();
        if ($row) {
            return $row;
        }
        abort_unless($process === 'collection', 404);
        // A merchant notification can acquire an order after the user bookmarked its original reference.
        $notification = DB::table('payment_work')->where('company_id', $company)->where('webhook_scope', 'merchant')->find($id);
        abort_unless($notification && $notification->payment_id && $notification->connection_id, 404);
        $related = DB::table('payment_work')->select('order_id')->where('company_id', $company)->where('webhook_scope', 'merchant')
            ->where('connection_id', $notification->connection_id)->where('payment_id', $notification->payment_id)->whereNotNull('order_id');
        $related->union(DB::table('payments')->select('order_id')->where('company_id', $company)
            ->whereNull('subscription_id')->where('provider_id', $notification->payment_id));
        $order = DB::table('payment_orders')->where('company_id', $company)->whereNotNull('document_id')->where('connection_id', $notification->connection_id)
            ->whereIn('id', $related)->value('id');
        abort_unless($order, 404);

        return $query->where('i.operation_id', $order)->firstOrFail();
    }

    public static function permissions(User $actor): array
    {
        $doc = new Document;
        $doc->company_id = $actor->company_id;

        return ['emission' => $actor->can('update', $doc), 'pdf' => $actor->can('regeneratePdf', $doc),
            'email' => $actor->can('sendEmail', $doc), 'odoo' => $actor->can('transferToOdoo', $doc), 'collection' => $actor->can('reconcilePayment', $doc)];
    }

    public static function action(object $row, array $permissions): ?string
    {
        if (! $permissions[$row->process] || ! $row->recoverable || $row->next_attempt_at !== null) {
            return null;
        }

        return match ($row->process) {
            'odoo' => config('odoo.enabled') ? 'retry_odoo' : null,
            'emission' => $row->source_status === 'failed' ? 'retry_emission' : null,
            'pdf' => $row->state === 'manual' ? 'regenerate_pdf' : null,
            'email' => $row->state === 'failed' ? 'retry_email' : null,
            'collection' => in_array($row->state, ['failed', 'confirmation'], true) && MercadoPagoGateway::transportReady() ? 'reconcile' : null,
        };
    }

    public static function present(object $row, array $permissions): array
    {
        return ['process' => $row->process, 'id' => $row->operation_id, 'document_id' => $row->document_id,
            'reference' => $row->reference, 'number' => $row->number, 'state' => $row->state, 'source_status' => $row->source_status,
            'first_detected_at' => $row->first_detected_at, 'updated_at' => $row->updated_at, 'next_attempt_at' => $row->next_attempt_at,
            'reason' => IncidentHistory::publicCode($row->reason), 'action' => self::action($row, $permissions),
            'revision' => $row->source_id.':'.$row->revision, 'can_review' => $row->document_id !== null && $permissions['emission']];
    }

    public static function detail(User $actor, object $row): array
    {
        $result = self::present($row, self::permissions($actor));
        if ($result['action'] === 'retry_email') {
            $doc = Document::where('company_id', $actor->company_id)->findOrFail($row->document_id);
            $delivery = DocumentDelivery::summary($doc, $actor);
            $recent = DB::table('document_email_deliveries')->where('company_id', $actor->company_id)->where('document_id', $doc->id);
            if (empty($delivery['email']['can_send']) || (clone $recent)->where('created_at', '>', now()->subSeconds(config('delivery.email_cooldown_seconds')))->exists()
                || $recent->where('created_at', '>=', now()->subDay())->count() >= config('delivery.max_emails_per_day')) {
                $result['action'] = null;
            } else {
                $result['revision'] = $row->source_id.':'.$delivery['pdf']['file_id'];
            }
        }

        return $result;
    }
}
