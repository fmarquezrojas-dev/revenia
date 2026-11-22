<?php

namespace App\Actions\Payments;

use App\Actions\Documents\CreditNotes;
use App\Actions\Subscriptions\Subscriptions;
use App\Domain\Money\Money;
use App\Domain\Money\ProviderAmount;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\MercadoPagoGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Collections
{
    public static function error(string $code, string $message, int $status = 409): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }

    public function creditExposure(Document $doc): array
    {
        if (! in_array($doc->doc_type, ['01', '03'], true)) {
            self::error('document_not_collectible', 'La cobranza pertenece al comprobante de origen.', 422);
        }

        $row = CreditNotes::exposure($doc->company_id)->where('c.source_document_id', $doc->id)->first();

        return ['accepted_cents' => (int) ($row->accepted_cents ?? 0), 'reserved_cents' => (int) ($row->reserved_cents ?? 0), 'supported' => true];
    }

    public function balance(Document $doc): array
    {
        if (! in_array($doc->doc_type, ['01', '03'], true)) {
            self::error('document_not_collectible', 'La cobranza pertenece al comprobante de origen.', 422);
        }

        $row = DocumentBalances::query($doc->company_id, $doc->currency, $doc->id)->firstOrFail();
        if ($row->credits_cents < 0 || $row->net_cents < 0 || $row->reserved_cents < 0 || $row->reserved_cents > $row->net_cents) {
            throw new \DomainException('invalid_credit_exposure');
        }

        return [
            'original_cents' => (int) $row->total_cents,
            'credits_cents' => (int) $row->credits_cents,
            'reserved_cents' => (int) $row->reserved_cents,
            'net_cents' => (int) $row->net_cents,
            'paid_cents' => $row->paid_cents > Money::MAX_CENTS ? (string) $row->paid_cents : (int) $row->paid_cents,
            'outstanding_cents' => (int) $row->outstanding_cents,
            'excess_cents' => $row->excess_cents > Money::MAX_CENTS ? (string) $row->excess_cents : (int) $row->excess_cents,
            'currency' => $row->currency,
            'needs_review' => $row->needs_review,
            'status' => $row->needs_review ? 'review' : ($row->outstanding_cents == 0 ? 'paid' : ($row->paid_cents > 0 ? 'partial' : 'unpaid')),
            'credit_notes_supported' => true,
        ];
    }

    public function summary(Document $doc, User $actor): array
    {
        $b = $this->balance($doc);
        $connection = DB::table('merchant_connections')->where('company_id', $doc->company_id)->where('active', true)
            ->where('collector_id', '<>', (string) config('payments.collector_id'))->first(['id', 'collector_id']);
        $configured = $connection && MercadoPagoGateway::transportReady();
        $blocked = ! $configured ? ($connection ? 'payments_not_configured' : 'merchant_connection_required') : ($doc->currency !== 'PEN' ? 'currency_unsupported' : ($b['reserved_cents'] > 0 ? 'credit_reserved' : ($b['needs_review'] ? 'financial_review' : ($b['outstanding_cents'] === 0 ? 'no_balance' : null))));
        $orders = DB::table('payment_orders')->where(['company_id' => $doc->company_id, 'document_id' => $doc->id])->orderByDesc('created_at')->get(['id', 'provider', 'amount_cents', 'currency', 'status', 'preference_id', 'checkout_url', 'expires_at', 'error_code', 'created_at', 'last_reconciled_at', 'receiver_kind', 'collector_id', 'environment', 'connection_id']);
        foreach ($orders as $o) {
            if ($o->receiver_kind === 'legacy' && $o->provider === 'mercadopago') {
                $o->error_code = 'receiver_unknown';
            }
            $valid = $connection && $connection->id === $o->connection_id && $o->status === 'open' && now()->lt($o->expires_at) && (int) $o->amount_cents === $b['outstanding_cents'] && ! $b['reserved_cents'] && ! $b['needs_review'];
            $o->checkout_url = $configured && $valid && MercadoPagoGateway::checkoutUrl($o->checkout_url ?? '', $o->preference_id ?? '') ? $o->checkout_url : null;
            unset($o->preference_id);
        }

        $bank = DocumentCash::bankInstalled() ? DB::table('bank_applications')->where('company_id', $doc->company_id)->where('document_id', $doc->id)
            ->orderByDesc('applied_at')->orderBy('id')->get(['id', 'receipt_id', 'amount_cents', 'applied_at', 'reversed_at']) : [];

        return $b + ['bank_applications' => $bank, 'issuer' => $doc->fiscal_snapshot['issuer']['legal_name'] ?? $actor->company->legal_name,
            'environment' => config('payments.enabled') ? 'sandbox' : null, 'incidents' => DB::table('payment_work as w')->join('payment_orders as o', 'o.id', '=', 'w.order_id')
                ->where('o.company_id', $doc->company_id)->where('w.company_id', $doc->company_id)->where('o.document_id', $doc->id)
                ->whereIn('w.status', ['failed', 'review'])->orderByDesc('w.updated_at')->get(['w.id', 'w.order_id', 'w.error_code', 'w.updated_at']),
            'enabled' => $configured, 'blocked_reason' => $blocked, 'can_create' => $doc->status === 'accepted' && $blocked === null && $actor->can('createPayment', $doc), 'can_reconcile' => MercadoPagoGateway::transportReady() && $actor->can('reconcilePayment', $doc) && $orders->contains(fn ($o) => $o->receiver_kind === 'merchant' && $o->error_code !== 'test_receiver_unverified'), 'orders' => $orders, 'payments' => DB::table('payments')->where(['company_id' => $doc->company_id, 'document_id' => $doc->id])->orderByDesc('provider_updated_at')->orderByDesc('id')->get(['id', 'order_id', 'provider_id', 'amount_cents', 'applied_cents', 'refunded_cents', 'currency', 'status', 'needs_review', 'review_code', 'provider_updated_at', 'created_at']), 'pending' => DB::table('payment_work')->where('company_id', $doc->company_id)->whereIn('order_id', DB::table('payment_orders')->select('id')->where('document_id', $doc->id))->whereIn('status', ['pending', 'processing'])->exists()];
    }

    public function request(User $actor, string $document, string $key, string $operation, ?string $orderId = null): array
    {
        return DB::transaction(function () use ($actor, $document, $key, $operation, $orderId): array {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->where('company_id', $company->id)->sharedLock()->firstOrFail();
            abort_unless($actor->is_active && $company->is_active, 401);
            $actor->setRelation('company', $company);
            $doc = Document::where('company_id', $company->id)->whereKey($document)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($operation === 'create' ? 'createPayment' : 'reconcilePayment', $doc);
            $fingerprint = hash('sha256', $operation.':'.$document.($orderId ? ':'.$orderId : ''));
            $old = DB::table('payment_requests')->where(['company_id' => $company->id, 'operation' => $operation, 'key' => $key])->first();
            if ($old) {
                if (! hash_equals($old->fingerprint, $fingerprint)) {
                    self::error('idempotency_conflict', 'La clave pertenece a otra solicitud.');
                }

                return json_decode($old->response, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($doc->doc_type === '07') {
                self::error('document_not_collectible', 'La cobranza pertenece al comprobante de origen.', 422);
            }
            if ($doc->status !== 'accepted') {
                self::error('document_not_accepted', 'Solo se cobran comprobantes aceptados.');
            }
            if (! MercadoPagoGateway::transportReady()) {
                self::error('payments_not_configured', 'Mercado Pago de pruebas no esta configurado.', 503);
            }
            $b = $this->balance($doc);
            if ($operation === 'create') {
                $connection = DB::table('merchant_connections')->where('company_id', $company->id)->where('active', true)
                    ->where('collector_id', '<>', (string) config('payments.collector_id'))->first(['id', 'collector_id']);
                if (! $connection) {
                    self::error('merchant_connection_required', 'El dueño debe vincular la cuenta de la empresa en Configuración → Cobros.');
                }
                if ($doc->currency !== 'PEN') {
                    self::error('currency_unsupported', 'Este checkout de Peru admite PEN; no se convierte USD.', 422);
                }
                if ($b['reserved_cents'] > 0) {
                    self::error('credit_reserved', 'Hay una reserva de credito activa.');
                }
                if ($b['needs_review']) {
                    self::error('financial_review', 'El saldo requiere conciliacion antes de otro cobro.');
                }
                if ($b['outstanding_cents'] <= 0) {
                    self::error('no_balance', 'El comprobante no tiene saldo pendiente.');
                }
                $order = DB::table('payment_orders')->where('document_id', $doc->id)->whereIn('status', ['pending', 'creating', 'open', 'unknown'])->lockForUpdate()->first();
                if ($order && in_array($order->status, ['creating', 'unknown'], true)) {
                    self::error('checkout_uncertain', 'La creacion anterior requiere conciliacion; no se creara otro enlace.');
                }
                if ($order && ($order->connection_id !== $connection->id || $order->amount_cents !== $b['outstanding_cents'] || now()->gte($order->expires_at))) {
                    DB::table('payment_orders')->where('id', $order->id)->update(['status' => now()->gte($order->expires_at) ? 'expired' : 'stale', 'updated_at' => now()]);
                    $order = null;
                }
                if (! $order) {
                    $id = (string) Str::uuid();
                    DB::table('payment_orders')->insert(['id' => $id, 'company_id' => $company->id, 'document_id' => $doc->id, 'receiver_kind' => 'merchant', 'environment' => 'sandbox', 'connection_id' => $connection->id, 'collector_id' => $connection->collector_id, 'requested_by' => $actor->id, 'amount_cents' => $b['outstanding_cents'], 'currency' => $doc->currency, 'status' => 'pending', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
                    self::enqueue('create', $company->id, $id, null, 'create:'.$id);
                    $order = DB::table('payment_orders')->find($id);
                    self::audit($order, null, 'order_requested', actor: $actor->id);
                }
                $response = ['data' => ['id' => $order->id, 'status' => $order->status]];
            } else {
                $orders = DB::table('payment_orders')->where('document_id', $doc->id)->where('company_id', $company->id)->when($orderId, fn ($q) => $q->where('id', $orderId))->where('receiver_kind', 'merchant')->where(fn ($q) => $q->whereNull('error_code')->orWhere('error_code', '<>', 'test_receiver_unverified'))->orderByDesc('created_at')->limit(10)->get();
                if ($orders->isEmpty()) {
                    abort_if($orderId !== null, 404);
                    self::error('no_orders', 'No hay ordenes para conciliar.');
                }
                if ($orders->contains(fn ($o) => $o->last_reconciled_at && now()->diffInSeconds($o->last_reconciled_at, true) < 60)) {
                    self::error('reconcile_rate_limit', 'Espera un minuto antes de conciliar nuevamente.', 429);
                }
                foreach ($orders as $o) {
                    DB::table('payment_orders')->where('id', $o->id)->update(['last_reconciled_at' => now()]);
                    self::enqueue('reconcile', $company->id, $o->id, null, 'manual:'.$key.':'.$o->id);
                    self::audit($o, null, 'reconciliation_requested', actor: $actor->id);
                }
                $response = ['data' => ['status' => 'pending', 'orders' => $orders->pluck('id')->all()]];
            }
            DB::table('payment_requests')->insert(['company_id' => $company->id, 'key' => $key, 'operation' => $operation, 'document_id' => $doc->id, 'fingerprint' => $fingerprint, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 3);
    }

    public static function enqueue(string $kind, ?string $company, ?string $order, ?string $payment, string $dedup, ?string $event = null, ?string $webhookScope = null, ?string $connection = null, ?string $webhookCollector = null): void
    {
        DB::table('payment_work')->insertOrIgnore(['id' => (string) Str::uuid(), 'kind' => $kind, 'company_id' => $company, 'order_id' => $order, 'payment_id' => $payment, 'event_id' => $event, 'webhook_scope' => $webhookScope, 'webhook_collector_id' => $webhookCollector, 'connection_id' => $connection, 'dedup_key' => hash('sha256', $dedup), 'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function audit(?object $order, ?object $work, string $event, ?string $code = null, ?string $payment = null, ?string $actor = null): void
    {
        DB::table('payment_audit')->insert(['id' => (string) Str::uuid(), 'company_id' => $order?->company_id ?? $work?->company_id, 'actor_id' => $actor, 'connection_id' => $order?->connection_id ?? $work?->connection_id, 'document_id' => $order?->document_id, 'subscription_id' => $order?->subscription_id, 'order_id' => $order?->id, 'work_id' => $work?->id, 'payment_id' => $payment, 'event' => $event, 'code' => $code, 'created_at' => now()]);
    }

    /** Called only with a response freshly fetched by the configured gateway. */
    public function apply(array $p, object $work): void
    {
        $reference = $p['external_reference'] ?? null;
        if (! is_string($reference) || ! Str::isUuid($reference) || $work->order_id !== $reference || ! $work->company_id) {
            throw new \DomainException('external_reference_mismatch');
        }
        $order = DB::table('payment_orders')->where('company_id', $work->company_id)->find($reference);
        if (! $order) {
            throw new \DomainException('order_not_found');
        }
        if ($order->receiver_kind === 'legacy') {
            throw new \DomainException('receiver_unknown');
        }
        DB::transaction(function () use ($p, $work, $order): void {
            Company::whereKey($order->company_id)->lockForUpdate()->firstOrFail();
            $doc = $order->document_id ? Document::where('company_id', $order->company_id)->whereKey($order->document_id)->lockForUpdate()->firstOrFail() : null;
            $order = DB::table('payment_orders')->where('id', $order->id)->lockForUpdate()->first();
            $claim = DB::table('payment_work')->where('id', $work->id)->lockForUpdate()->first();
            if (! $claim || $claim->token !== $work->token || $claim->status !== 'processing' || now()->gte($claim->lease_until)) {
                return;
            }
            DB::table('payment_work')->where('id', $work->id)->update(['company_id' => $order->company_id, 'order_id' => $order->id]);
            $checks = ['payment_id_mismatch' => (string) ($p['id'] ?? '') === $work->payment_id, 'receiver_mismatch' => (string) ($p['collector_id'] ?? '') === $order->collector_id, 'live_payment_blocked' => $order->environment === 'sandbox' && ($p['live_mode'] ?? null) === false, 'currency_mismatch' => ($p['currency_id'] ?? null) === $order->currency, 'amount_mismatch' => ProviderAmount::cents($p['transaction_amount'] ?? null) === (int) $order->amount_cents, 'preference_mismatch' => $order->preference_id !== null && ($p['preference_id'] ?? null) === $order->preference_id];
            foreach ($checks as $code => $valid) {
                if (! $valid) {
                    self::audit($order, $work, 'payment_mismatch', $code, $work->payment_id);
                    throw new \DomainException($code);
                }
            }
            $status = $p['status'] ?? '';
            if (! in_array($status, ['pending', 'in_process', 'authorized', 'approved', 'rejected', 'cancelled', 'refunded', 'charged_back', 'in_mediation'], true)) {
                throw new \DomainException('unknown_payment_status');
            }
            if (! is_string($p['date_last_updated'] ?? null) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $p['date_last_updated'])) {
                throw new \DomainException('invalid_payment_timestamp');
            }
            try {
                $updated = CarbonImmutable::parse($p['date_last_updated'] ?? '');
            } catch (\Throwable) {
                throw new \DomainException('invalid_payment_timestamp');
            }if (empty($p['date_last_updated'])) {
                throw new \DomainException('invalid_payment_timestamp');
            }
            self::audit($order, $work, 'provider_status_observed', $status, $work->payment_id);
            $old = DB::table('payments')->where(['provider' => 'mercadopago', 'provider_id' => $work->payment_id])->lockForUpdate()->first();
            if ($old && $old->order_id !== $order->id) {
                throw new \DomainException('payment_order_conflict');
            }
            if ($old && $updated->lt($old->provider_updated_at)) {
                self::audit($order, $work, 'stale_payment_ignored', null, $work->payment_id);

                return;
            }
            $refund = ProviderAmount::cents($p['transaction_amount_refunded'] ?? '0');
            if ($refund > (int) $order->amount_cents) {
                throw new \DomainException('refund_amount_mismatch');
            }
            $review = in_array($status, ['refunded', 'charged_back', 'in_mediation'], true) || $refund > 0;
            $applied = $status === 'approved' ? (int) $order->amount_cents : 0;
            if ($old && $old->applied_cents > 0 && $status !== 'approved') {
                $review = true;
                $applied = (int) $old->applied_cents;
            }
            if ($old && $old->status !== $status && $updated->eq($old->provider_updated_at)) {
                $review = true;
                $status = $old->status;
                $applied = (int) $old->applied_cents;
            }
            $review = $review || ($old?->needs_review ?? false);
            $values = ['company_id' => $order->company_id, 'document_id' => $doc?->id, 'subscription_id' => $order->subscription_id, 'order_id' => $order->id, 'provider' => 'mercadopago', 'provider_id' => $work->payment_id, 'amount_cents' => (int) $order->amount_cents, 'applied_cents' => $applied, 'refunded_cents' => $refund, 'currency' => $order->currency, 'status' => $status, 'needs_review' => $review, 'review_code' => $review ? 'external_adjustment_review' : null, 'confirmed_at' => $old?->confirmed_at ?? ($applied > 0 ? $updated->utc()->toISOString() : null), 'provider_updated_at' => $updated, 'updated_at' => now()];
            if ($old) {
                DB::table('payments')->where('id', $old->id)->update($values);
            } else {
                DB::table('payments')->insert($values + ['id' => (string) Str::uuid(), 'created_at' => now()]);
            }
            DB::table('payment_work')->where('order_id', $order->id)->where('payment_id', $work->payment_id)->whereIn('status', ['failed', 'review'])->update(['status' => 'resolved', 'updated_at' => now()]);
            if ($order->subscription_id) {
                app(Subscriptions::class)->apply($order);
                self::audit($order, $work, 'subscription_payment_reconciled', $status, $work->payment_id);

                return;
            }
            $b = $this->balance($doc);
            foreach (DB::table('payment_orders')->where('document_id', $doc->id)->whereIn('status', ['pending', 'open', 'creating', 'unknown'])->get() as $open) {
                if ($b['outstanding_cents'] === 0 || (int) $open->amount_cents !== $b['outstanding_cents'] || $b['reserved_cents'] > 0) {
                    DB::table('payment_orders')->where('id', $open->id)->update(['status' => $open->id === $order->id && $applied > 0 ? 'closed' : 'stale', 'updated_at' => now()]);
                }
            }
            self::audit($order, $work, 'payment_reconciled', $review ? 'external_adjustment_review' : $status, $work->payment_id);
        }, 3);
    }
}
