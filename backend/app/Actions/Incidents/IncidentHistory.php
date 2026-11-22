<?php

namespace App\Actions\Incidents;

use Illuminate\Support\Facades\DB;

final class IncidentHistory
{
    private const CODES = [
        'odoo_map_customer', 'odoo_map_anonymous', 'odoo_map_tax', 'odoo_map_currency', 'odoo_map_account', 'odoo_map_journal', 'odoo_map_unit',
        'odoo_destination', 'odoo_credentials', 'odoo_unavailable', 'odoo_original', 'odoo_mapping', 'odoo_amounts', 'odoo_receipt', 'odoo_endpoint', 'odoo_paused', 'odoo_rejected', 'odoo_document',
        'simulated_acceptance', 'simulated_rejection', 'identity_conflict', 'temporary', 'timeout', 'configuration',
        'provider_unavailable', 'lease_expired', 'snapshot_incomplete', 'pdf_generation_failed', 'file_missing', 'pdf_unavailable',
        'delivery_unknown', 'demo_transport_blocked', 'email_render_failed', 'transport_reviewed', 'queue_unavailable',
        'provider_access_denied', 'receiver_unknown', 'receiver_mismatch', 'test_receiver_unverified', 'merchant_receiver_unavailable',
        'amount_mismatch', 'currency_mismatch', 'preference_mismatch', 'checkout_creation_uncertain', 'external_adjustment_review',
        'preference_not_found_yet', 'attempts_exhausted', 'live_payment_blocked', 'merchant_order_mismatch', 'external_reference_mismatch',
        'payment_id_mismatch', 'payment_order_conflict', 'integration_mismatch', 'order_not_found', 'multiple_preferences',
        'pending', 'in_process', 'authorized', 'approved', 'rejected', 'cancelled', 'refunded', 'charged_back', 'in_mediation',
        'failed', 'ready', 'sent', 'unknown', 'accepted', 'queued', 'generating', 'sending', 'done', 'processing', 'review', 'resolved',
        'requested', 'recorded', 'state_changed', 'rate_limited', 'unavailable', 'denied',
    ];

    public static function publicCode(?string $code): ?string
    {
        return $code === null || in_array($code, self::CODES, true) ? $code : 'technical_review';
    }

    public static function page(string $company, object $incident, int $page): array
    {
        $doc = $incident->document_id;
        $process = $incident->process;
        $scoped = fn ($table) => DB::table($table)->where('company_id', $company)->where('document_id', $doc);
        $entries = $scoped('incident_follow_ups')->where('process', $process)->where('operation_id', $incident->operation_id)
            ->selectRaw("id,created_at AS at,actor_id,action AS event,outcome AS code, 'followup' AS source");
        if ($process === 'emission') {
            // attempt_started, outcome and abandoned carry the original attempt ID in the domain journal.
            $entries->unionAll($scoped('document_events')->selectRaw("id,created_at,actor_id,type,code,'emission'"));
        } elseif ($process === 'pdf' || $process === 'email') {
            $attempts = $scoped('document_delivery_attempts')->whereIn('kind', $process === 'pdf' ? ['pdf'] : ['email', 'email_review']);
            $entries->unionAll((clone $attempts)->where('kind', '<>', 'email_review')->selectRaw("id,created_at,actor_id,'attempt_started',null,'start'"));
            $entries->unionAll($attempts->whereNotNull('finished_at')->selectRaw("id,finished_at,actor_id,status,error_code,'finish'"));
            if ($process === 'email') {
                $entries->unionAll($scoped('document_email_deliveries')->selectRaw("id,created_at,requested_by,'email_requested',null,'delivery'"));
            } else {
                $entries->unionAll($scoped('document_delivery_requests')->where('operation', 'pdf')
                    ->selectRaw("id,created_at,actor_id,'pdf_requested',null,'request'"));
            }
        } elseif ($process === 'odoo') {
            $entries->unionAll(DB::table('odoo_events')->where('company_id', $company)->where('transfer_id', $incident->operation_id)
                ->selectRaw("id,created_at,actor_id,event,code,'odoo'"));
        } else {
            $entries->unionAll($scoped('payment_audit')->where('order_id', $incident->operation_id)
                ->selectRaw("id,created_at,actor_id,event,code,'audit'"));
            $work = DB::table('payment_work')->where('company_id', $company);
            if ($doc === null) {
                $connection = (clone $work)->where('webhook_scope', 'merchant')->where('id', $incident->operation_id)->value('connection_id');
                $work->where('webhook_scope', 'merchant')->where('connection_id', $connection)->where('payment_id', $incident->reference);
            } else {
                $connection = DB::table('payment_orders')->where('company_id', $company)->where('document_id', $doc)
                    ->where('id', $incident->operation_id)->value('connection_id');
                $paymentIds = (clone $work)->select('payment_id')->where('order_id', $incident->operation_id)->whereNotNull('payment_id')
                    ->union($scoped('payments')->select('provider_id')->where('order_id', $incident->operation_id));
                $work->where(function ($s) use ($incident, $connection, $paymentIds): void {
                    $s->where('order_id', $incident->operation_id);
                    if ($connection) {
                        $s->orWhere(fn ($w) => $w->whereNull('order_id')->where('webhook_scope', 'merchant')
                            ->where('connection_id', $connection)->whereIn('payment_id', $paymentIds));
                    }
                });
            }
            $entries->unionAll(DB::table('payment_audit')->where('company_id', $company)->whereNull('order_id')
                ->whereIn('work_id', (clone $work)->select('id'))->selectRaw("id,created_at,actor_id,event,code,'unmatched_audit'"));
            $entries->unionAll((clone $work)->selectRaw("id,created_at,null,'work_queued',null,'work'"));
            $entries->unionAll($work->whereIn('status', ['review', 'failed', 'resolved'])->selectRaw("id,updated_at,null,status,error_code,'work_result'"));
            $entries->unionAll($scoped('payments')->whereNull('subscription_id')->where('order_id', $incident->operation_id)
                ->selectRaw("id,provider_updated_at,null,'provider_status_observed',status,'receipt'"));
        }
        if (in_array($process, ['emission', 'email', 'pdf'], true)) {
            $entries->unionAll(DB::table('incident_queue_events as q')->join('emission_outbox as b', 'b.id', '=', 'q.publication_id')
                ->where('b.company_id', $company)->where('b.document_id', $doc)->where('b.kind', $process)
                ->selectRaw("q.id,q.occurred_at,null,q.event,null,'publication'"));
        }
        $events = DB::query()->fromSub($entries, 'e')->leftJoin('users as u', function ($join) use ($company): void {
            $join->on('u.id', '=', 'e.actor_id')->where('u.company_id', $company);
        })->select('e.*', 'u.name as actor');
        if ($process === 'odoo') {
            $events->leftJoin('odoo_events as oe', function ($join) use ($company, $incident) {
                $join->on('oe.id', '=', 'e.id')->where('e.source', 'odoo')->where('oe.company_id', $company)
                    ->where('oe.transfer_id', $incident->operation_id);
            })->addSelect('oe.destination_name');
        }
        $events = $events->orderByDesc('at')->orderByDesc('e.id')->orderBy('e.source')->simplePaginate(30, ['*'], 'history_page', $page);
        $publicEvents = ['retry_odoo', 'odoo_queued', 'odoo_rebound', 'odoo_retry', 'odoo_dependency', 'odoo_review', 'odoo_uncertain', 'odoo_done', 'retry_emission', 'regenerate_pdf', 'retry_email', 'reconcile', 'reviewed', 'number_assigned', 'issuance_requested',
            'retry_requested', 'attempt_started', 'attempt_abandoned', 'accepted', 'rejected', 'failed', 'recovered', 'email_requested', 'pdf_requested',
            'ready', 'sent', 'unknown', 'publication_failed', 'publication_delayed', 'order_requested', 'reconciliation_requested', 'provider_status_observed',
            'payment_reconciled', 'stale_payment_ignored', 'work_completed', 'work_failed', 'work_queued', 'work_started', 'payment_mismatch', 'review', 'resolved'];

        return ['events' => array_map(fn ($e) => ['id' => $e->source.':'.$e->id, 'at' => $e->at, 'actor' => $e->actor, 'destination' => $e->destination_name ?? null,
            'event' => in_array($e->event, $publicEvents, true) ? $e->event : 'operation_updated', 'code' => self::publicCode($e->code)], $events->items()),
            'page' => $page, 'has_more' => $events->hasMorePages()];
    }
}
