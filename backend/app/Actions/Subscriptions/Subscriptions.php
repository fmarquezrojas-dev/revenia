<?php

namespace App\Actions\Subscriptions;

use App\Actions\Payments\Collections;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\MercadoPagoGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Subscriptions
{
    public static function boundary(string $anchor, int $index): CarbonImmutable
    {
        return CarbonImmutable::parse($anchor)->setTimezone('America/Lima')->addMonthsNoOverflow($index)->utc();
    }

    public function current(string $company): ?object
    {
        return DB::table('subscriptions')->where('company_id', $company)->where('status', 'paid')->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
    }

    public function usage(string $company): array
    {
        $s = $this->current($company);
        $review = DB::table('subscriptions')->where('company_id', $company)->where('status', 'review')->exists();
        $used = $s ? (int) DB::table('company_usage')->where('subscription_id', $s->id)->value('consumed') : 0;
        $status = $review ? 'review' : (! $s ? (DB::table('subscriptions')->where('company_id', $company)->whereNotNull('ends_at')->exists() ? 'expired' : 'absent') : ($used >= $s->emission_limit ? 'exhausted' : 'active'));

        return ['status' => $status, 'period_id' => $s?->id, 'plan_name' => $s?->plan_name, 'starts_at' => $s?->starts_at, 'ends_at' => $s?->ends_at, 'limit' => $s?->emission_limit ?? 0, 'consumed' => $used, 'remaining' => $s ? max($s->emission_limit - $used, 0) : 0, 'can_issue' => $status === 'active', 'expiring_soon' => $s && CarbonImmutable::parse($s->ends_at)->lte(now()->addDays(3)), 'timezone' => 'America/Lima', 'rule' => 'Una unidad por primera emision registrada, incluidos rechazos. Reintentos no consumen. Borradores disponibles sin vigencia. Notas de credito no habilitadas.'];
    }

    public function consume(Document $doc): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Quota requires the issuance transaction');
        }
        if (DB::table('emission_consumptions')->where('document_id', $doc->id)->exists()) {
            return;
        }
        $u = $this->usage($doc->company_id);
        $code = match ($u['status']) {
            'review' => 'subscription_review','expired' => 'subscription_expired','absent' => 'subscription_missing','exhausted' => 'quota_exhausted',default => null
        };
        if ($code) {
            Collections::error($code, match ($code) {
                'quota_exhausted' => 'La cuota del periodo esta agotada.','subscription_review' => 'La suscripcion requiere revision del pago.','subscription_expired' => 'La suscripcion ha vencido. El titular puede renovarla.',default => 'La empresa no tiene una suscripcion vigente.'
            }, 422);
        }
        $capacity = DB::table('company_usage')->where('subscription_id', $u['period_id'])->lockForUpdate()->first();
        if (! $capacity || $capacity->consumed >= $u['limit']) {
            Collections::error('quota_exhausted', 'La cuota del periodo esta agotada.', 422);
        }
        DB::table('company_usage')->where('subscription_id', $capacity->subscription_id)->increment('consumed', 1, ['updated_at' => now()]);
        DB::table('emission_consumptions')->insert(['id' => (string) Str::uuid(), 'company_id' => $doc->company_id, 'subscription_id' => $capacity->subscription_id, 'document_id' => $doc->id, 'consumed_at' => now(), 'historical' => false]);
    }

    public function purchase(User $actor, string $code, string $key): array
    {
        return DB::transaction(function () use ($actor, $code, $key): array {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->sharedLock()->firstOrFail();
            $actor->setRelation('company', $company);
            Gate::forUser($actor)->authorize('manage-subscription');
            $fingerprint = hash('sha256', $code);
            $old = DB::table('payment_requests')->where(['company_id' => $company->id, 'operation' => 'subscribe', 'key' => $key])->first();
            if ($old) {
                if (! hash_equals($old->fingerprint, $fingerprint)) {
                    Collections::error('idempotency_conflict', 'La clave corresponde a otro plan.');
                }

                return json_decode($old->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $plan = DB::table('plans')->where('code', $code)->where('available', true)->sharedLock()->first();
            if (! $plan) {
                Collections::error('plan_unavailable', 'Plan no disponible.', 422);
            }
            if (! MercadoPagoGateway::configured()) {
                Collections::error('payments_not_configured', 'Los pagos de prueba no estan configurados.', 503);
            }
            if (DB::table('subscriptions')->where('company_id', $company->id)->where('status', 'review')->exists()) {
                Collections::error('subscription_review', 'Hay una contratacion que requiere revision.');
            }
            if (DB::table('subscriptions')->where('company_id', $company->id)->where('starts_at', '>', now())->exists()) {
                Collections::error('renewal_scheduled', 'Ya existe un periodo futuro contratado.');
            }
            $pending = DB::table('subscriptions')->where('company_id', $company->id)->where('status', 'pending')->first();
            if ($pending && $pending->plan_code !== $code) {
                Collections::error('subscription_pending', 'Ya existe una contratacion pendiente de otro plan.');
            }
            if (! $pending) {
                $id = (string) Str::uuid();
                $last = DB::table('subscriptions')->where('company_id', $company->id)->whereNotNull('starts_at')->orderByDesc('ends_at')->first();
                DB::table('subscriptions')->insert(['id' => $id, 'company_id' => $company->id, 'plan_code' => $plan->code, 'plan_name' => $plan->name, 'price_cents' => $plan->price_cents, 'currency' => $plan->currency, 'emission_limit' => $plan->emission_limit, 'predecessor_id' => $last?->id, 'created_at' => now(), 'updated_at' => now()]);
                $order = (string) Str::uuid();
                DB::table('payment_orders')->insert(['id' => $order, 'company_id' => $company->id, 'document_id' => null, 'subscription_id' => $id, 'requested_by' => $actor->id, 'amount_cents' => $plan->price_cents, 'currency' => $plan->currency, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
                Collections::enqueue('create', $company->id, $order, null, 'create:'.$order);
                Collections::audit(DB::table('payment_orders')->find($order), null, 'subscription_requested');
                $pending = DB::table('subscriptions')->find($id);
            }
            $response = ['data' => ['id' => $pending->id, 'status' => $pending->status, 'order_id' => DB::table('payment_orders')->where('subscription_id', $pending->id)->value('id')]];
            DB::table('payment_requests')->insert(['company_id' => $company->id, 'operation' => 'subscribe', 'key' => $key, 'subscription_id' => $pending->id, 'fingerprint' => $fingerprint, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 3);
    }

    /** Called under the company lock, after authoritative payment validation and persistence. */
    public function apply(object $order): void
    {
        $s = DB::table('subscriptions')->where('company_id', $order->company_id)->where('id', $order->subscription_id)->lockForUpdate()->firstOrFail();
        $payments = DB::table('payments')->where('subscription_id', $s->id)->get();
        $approved = $payments->where('applied_cents', '>', 0);
        $review = $payments->contains(fn ($p) => $p->needs_review) || $approved->count() > 1 || $s->status === 'review';
        if ($review) {
            DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'review', 'review_code' => $approved->count() > 1 ? 'duplicate_subscription_payment' : 'payment_review', 'updated_at' => now()]);
            DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'closed', 'updated_at' => now()]);

            return;
        }
        if ($approved->isEmpty()) {
            return;
        }if ($s->starts_at) {
            return;
        }
        $last = DB::table('subscriptions')->where('company_id', $s->company_id)->whereNotNull('starts_at')->orderByDesc('ends_at')->first();
        if ($s->status !== 'pending' || $last?->id !== $s->predecessor_id) {
            DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'review', 'review_code' => 'late_incompatible_payment', 'updated_at' => now()]);

            return;
        }
        if ($last && CarbonImmutable::parse($last->ends_at)->gt(now())) {
            $anchor = $last->anchor_at;
            $index = $last->period_index + 1;
            $start = CarbonImmutable::parse($last->ends_at);
            $end = self::boundary($anchor, $index + 1);
        } else {
            $start = CarbonImmutable::now();
            $anchor = $start->toIso8601String();
            $index = 0;
            $end = self::boundary($anchor, 1);
        }
        DB::table('subscriptions')->where('id', $s->id)->update(['status' => 'paid', 'starts_at' => $start, 'ends_at' => $end, 'anchor_at' => $anchor, 'period_index' => $index, 'updated_at' => now()]);
        DB::table('company_usage')->insert(['subscription_id' => $s->id, 'company_id' => $s->company_id, 'consumed' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'closed', 'updated_at' => now()]);
        Collections::audit($order, null, 'subscription_activated');
    }

    public function cancel(User $actor, string $id, string $key): array
    {
        return DB::transaction(function () use ($actor, $id, $key): array {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->sharedLock()->firstOrFail();
            $actor->setRelation('company', $company);
            $s = DB::table('subscriptions')->where('company_id', $company->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manage-subscription');
            $fingerprint = hash('sha256', $id);
            $old = DB::table('payment_requests')->where(['company_id' => $company->id, 'operation' => 'cancel-subscription', 'key' => $key])->first();
            if ($old) {
                if (! hash_equals($old->fingerprint, $fingerprint)) {
                    Collections::error('idempotency_conflict', 'Clave utilizada para otra contratacion.');
                }

                return json_decode($old->response, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($s->status !== 'pending') {
                Collections::error('subscription_conflict', 'Solo se cancelan intenciones pendientes.');
            }
            $order = DB::table('payment_orders')->where('subscription_id', $id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['creating', 'unknown'], true)) {
                Collections::error('checkout_uncertain', 'Concilia la creacion incierta antes de cancelar.');
            }
            DB::table('subscriptions')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'stale', 'updated_at' => now()]);
            Collections::audit($order, null, 'subscription_cancelled');
            $response = ['data' => ['id' => $id, 'status' => 'cancelled']];
            DB::table('payment_requests')->insert(['company_id' => $company->id, 'operation' => 'cancel-subscription', 'key' => $key, 'subscription_id' => $id, 'fingerprint' => $fingerprint, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        }, 3);
    }

    public function detail(string $company, ?string $subscriptionId = null): array
    {
        $rows = DB::table('subscriptions')->where('company_id', $company)->when($subscriptionId, fn ($query) => $query->where('id', $subscriptionId))->orderByDesc('created_at')->limit(50)->get();
        foreach ($rows as $s) {
            $s->phase = $s->status !== 'paid' ? $s->status : (now()->lt($s->starts_at) ? 'future' : (now()->gte($s->ends_at) ? 'expired' : 'active'));
            $s->consumed = (int) DB::table('company_usage')->where('subscription_id', $s->id)->value('consumed');
            $o = DB::table('payment_orders')->where('subscription_id', $s->id)->first();
            $s->order = $o ? ['id' => $o->id, 'status' => $o->status, 'checkout_url' => MercadoPagoGateway::configured() && $this->usage($company)['status'] !== 'review' && $s->status === 'pending' && $o->status === 'open' && now()->lt($o->expires_at) && MercadoPagoGateway::checkoutUrl($o->checkout_url ?? '', $o->preference_id ?? '') ? $o->checkout_url : null, 'error_code' => $o->error_code] : null;
            $s->verification_error = $o && DB::table('payment_work')->where('order_id', $o->id)->whereIn('status', ['review', 'failed'])->exists();
            if ($s->status === 'pending' && $s->verification_error) {
                $s->phase = 'verification_error';
            }
            $s->payments = DB::table('payments')->where('subscription_id', $s->id)->get(['provider_id', 'amount_cents', 'applied_cents', 'status', 'needs_review', 'provider_updated_at']);
        }

        return ['usage' => $this->usage($company), 'history' => $rows, 'enabled' => MercadoPagoGateway::configured(), 'pending' => DB::table('payment_work')->where('company_id', $company)->whereIn('order_id', DB::table('payment_orders')->select('id')->whereNotNull('subscription_id'))->whereIn('status', ['pending', 'processing'])->exists()];
    }

    public function grantDemo(string $company): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \DomainException('Demo exclusivamente local');
        }

        return DB::transaction(function () use ($company): array {
            Company::whereKey($company)->lockForUpdate()->firstOrFail();
            if (DB::table('subscription_demo_grants')->where('company_id', $company)->exists()) {
                return ['created' => false];
            }
            if (! app()->environment('testing') && ! DB::table('local_demo_records')->where('company_id', $company)->exists()) {
                throw new \DomainException('Empresa fuera del dataset autorizado');
            }
            if (DB::table('subscriptions')->where('company_id', $company)->exists()) {
                throw new \DomainException('La empresa ya tiene contratos; no se conceden derechos extra');
            }
            $docs = Document::where('company_id', $company)->whereNotNull('number')->get();
            $groups = [];
            foreach ($docs as $d) {
                if (! $d->issued_at) {
                    throw new \DomainException('Emision historica sin fecha: requiere revision');
                }$month = CarbonImmutable::parse($d->issued_at)->setTimezone('America/Lima')->startOfMonth()->toIso8601String();
                $groups[$month][] = $d;
            }
            $current = CarbonImmutable::now('America/Lima')->startOfMonth()->toIso8601String();
            $groups[$current] ??= [];
            $plan = DB::table('plans')->where('code', 'demo-basic')->firstOrFail();
            foreach ($groups as $month => $items) {
                $id = (string) Str::uuid();
                DB::table('subscriptions')->insert(['id' => $id, 'company_id' => $company, 'plan_code' => $plan->code, 'plan_name' => $plan->name, 'price_cents' => $plan->price_cents, 'currency' => $plan->currency, 'emission_limit' => $plan->emission_limit, 'status' => 'paid', 'source' => 'demo', 'starts_at' => CarbonImmutable::parse($month)->utc(), 'ends_at' => self::boundary($month, 1), 'anchor_at' => CarbonImmutable::parse($month)->utc(), 'period_index' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('company_usage')->insert(['subscription_id' => $id, 'company_id' => $company, 'consumed' => count($items), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($items as $d) {
                    DB::table('emission_consumptions')->insert(['id' => (string) Str::uuid(), 'company_id' => $company, 'subscription_id' => $id, 'document_id' => $d->id, 'consumed_at' => $d->issued_at, 'historical' => true]);
                }
            }
            DB::table('subscription_demo_grants')->insert(['company_id' => $company, 'created_at' => now()]);

            return ['created' => true, 'historical_emissions' => $docs->count()];
        });
    }

    public function audit(?string $company = null, int $limit = 100): array
    {
        return DB::table('company_usage as u')->join('subscriptions as s', 's.id', '=', 'u.subscription_id')->when($company, fn ($q) => $q->where('u.company_id', $company))->select('u.company_id', 'u.subscription_id', 'u.consumed')->selectRaw('(SELECT count(*) FROM emission_consumptions c WHERE c.subscription_id=u.subscription_id) AS recorded')->orderBy('u.subscription_id')->limit($limit)->get()->filter(fn ($r) => (int) $r->consumed !== (int) $r->recorded)->values()->all();
    }
}
