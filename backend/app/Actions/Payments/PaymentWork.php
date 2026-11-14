<?php

namespace App\Actions\Payments;

use App\Contracts\PaymentGateway;
use App\Jobs\ProcessPaymentWork;
use App\Models\Company;
use App\Models\Document;
use App\Services\MercadoPagoGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class PaymentWork
{
    public function __construct(private PaymentGateway $gateway, private Collections $collections) {}

    public function run(string $id): void
    {
        $work = DB::transaction(function () use ($id): ?object {
            $w = DB::table('payment_work')->where('id', $id)->lockForUpdate()->first();
            if (! $w || $w->status !== 'pending' || now()->lt($w->available_at) || $w->attempts >= 5) {
                return null;
            }
            $w->token = (string) Str::uuid();
            $w->attempts++;
            DB::table('payment_work')->where('id', $id)->update(['status' => 'processing', 'token' => $w->token, 'lease_until' => now()->addSeconds(90), 'attempts' => $w->attempts, 'updated_at' => now()]);

            return $w;
        });
        if (! $work) {
            return;
        }
        $order = $work->order_id ? DB::table('payment_orders')->where('company_id', $work->company_id)->find($work->order_id) : null;
        try {
            if ($work->kind === 'payment') {
                $p = $this->gateway->payment($work->payment_id);
                if (! $order && is_string($p['external_reference'] ?? null) && Str::isUuid($p['external_reference'])) {
                    $order = DB::table('payment_orders')->find($p['external_reference']);
                    if ($order) {
                        DB::table('payment_work')->where('id', $work->id)->where('token', $work->token)->update(['company_id' => $order->company_id, 'order_id' => $order->id]);
                    }
                }
                $this->collections->apply($p, $work);
            } else {
                if (! $order) {
                    throw new \DomainException('order_not_found');
                }
                if ($work->kind === 'create' && $work->attempts === 1) {
                    $shouldCreate = DB::transaction(function () use ($order): bool {
                        $b = $this->balanceForOrder($order);
                        $current = DB::table('payment_orders')->where('id', $order->id)->lockForUpdate()->first();
                        if ($current->status !== 'pending') {
                            return false;
                        }
                        if (! $b['eligible'] || $b['reserved_cents'] > 0 || $b['needs_review'] || $b['outstanding_cents'] !== (int) $current->amount_cents || now()->gte($current->expires_at)) {
                            DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'stale', 'updated_at' => now()]);

                            return false;
                        }
                        DB::table('payment_orders')->where('id', $order->id)->update(['status' => 'creating', 'updated_at' => now()]);

                        return true;
                    });
                    if (! $shouldCreate) {
                        $this->finish($work, 'done');

                        return;
                    }
                    $preference = $this->gateway->create($order);
                    $this->publishPreference($order, $work, $preference);
                } elseif (! $order->preference_id) {
                    $preference = $this->gateway->recover($order);
                    if (! $preference) {
                        throw new \RuntimeException('preference_not_found_yet');
                    }$this->publishPreference($order, $work, $preference);
                }
                if ($work->kind === 'reconcile') {
                    $order = DB::table('payment_orders')->find($order->id);
                    $payments = $this->gateway->paymentIds($order);
                    DB::transaction(function () use ($order, $work, $payments): void {
                        $claim = DB::table('payment_work')->where('id', $work->id)->lockForUpdate()->first();
                        if ($claim->token !== $work->token || $claim->status !== 'processing' || now()->gte($claim->lease_until)) {
                            return;
                        }
                        foreach ($payments as $payment) {
                            Collections::enqueue('payment', $order->company_id, $order->id, $payment, 'sync:'.$work->id.':'.$payment);
                        }
                        DB::table('payment_work')->where('order_id', $order->id)->where('kind', 'reconcile')->whereIn('status', ['failed', 'review'])->update(['status' => 'resolved', 'updated_at' => now()]);
                    });
                }
            }
            $this->finish($work, 'done');
            Collections::audit($order, $work, 'work_completed');
        } catch (\Throwable $e) {
            $code = $e instanceof \DomainException ? $e->getMessage() : 'provider_unavailable';
            if (! preg_match('/\A[a-z_]{1,80}\z/', $code)) {
                $code = 'provider_mismatch';
            }
            if ($work->kind === 'create') {
                DB::table('payment_orders')->where('id', $work->order_id)->where('status', 'creating')->update(['status' => 'unknown', 'error_code' => 'checkout_creation_uncertain', 'updated_at' => now()]);
            }
            $permanent = $e instanceof \DomainException;
            $status = $permanent ? 'review' : ($work->attempts >= 5 ? 'failed' : 'pending');
            $this->finish($work, $status, $code);
            Collections::audit($order, $work, 'work_failed', $code, $work->payment_id);
        }
    }

    private function balanceForOrder(object $order): array
    {
        Company::whereKey($order->company_id)->lockForUpdate()->firstOrFail();
        if ($order->subscription_id) {
            $s = DB::table('subscriptions')->where('company_id', $order->company_id)->where('id', $order->subscription_id)->lockForUpdate()->firstOrFail();

            return ['eligible' => $s->status === 'pending', 'reserved_cents' => 0, 'needs_review' => DB::table('subscriptions')->where('company_id', $order->company_id)->where('status', 'review')->exists(), 'outstanding_cents' => $s->status === 'pending' ? (int) $s->price_cents : 0];
        }
        $doc = Document::where('company_id', $order->company_id)->whereKey($order->document_id)->lockForUpdate()->firstOrFail();

        return $this->collections->balance($doc) + ['eligible' => $doc->status === 'accepted'];
    }

    private function publishPreference(object $order, object $work, array $p): void
    {
        if (! isset($p['id'],$p['url']) || ! MercadoPagoGateway::checkoutUrl($p['url'], $p['id'])) {
            throw new \DomainException('preference_mismatch');
        }
        DB::transaction(function () use ($order, $work, $p): void {
            $b = $this->balanceForOrder($order);
            $o = DB::table('payment_orders')->where('id', $order->id)->lockForUpdate()->first();
            $w = DB::table('payment_work')->where('id', $work->id)->lockForUpdate()->first();
            if ($w->token !== $work->token || $w->status !== 'processing' || now()->gte($w->lease_until)) {
                return;
            }
            if ($o->preference_id && $o->preference_id !== $p['id']) {
                throw new \DomainException('preference_conflict');
            }
            DB::table('payment_work')->where('order_id', $o->id)->whereIn('kind', ['create', 'reconcile'])->whereIn('status', ['failed', 'review'])->update(['status' => 'resolved', 'updated_at' => now()]);

            $status = now()->gte($o->expires_at) ? 'expired' : ($b['outstanding_cents'] === (int) $o->amount_cents && $b['reserved_cents'] === 0 && ! $b['needs_review'] && $b['eligible'] && ! in_array($o->status, ['closed', 'stale'], true) ? 'open' : 'stale');
            DB::table('payment_orders')->where('id', $o->id)->update(['preference_id' => $p['id'], 'checkout_url' => $p['url'], 'status' => $status, 'error_code' => null, 'updated_at' => now()]);
        });
    }

    private function finish(object $work, string $status, ?string $code = null): void
    {
        DB::table('payment_work')->where('id', $work->id)->where('token', $work->token)->where('status', 'processing')->where('lease_until', '>', now())->update(['status' => $status, 'token' => null, 'lease_until' => null, 'published_at' => null, 'available_at' => now()->addSeconds(min(900, 30 * (2 ** ($work->attempts - 1)))), 'error_code' => $code, 'updated_at' => now()]);
    }

    public function tick(?string $company = null, int $limit = 50): int
    {
        $scope = fn () => DB::table('payment_work')->when($company, fn ($q) => $q->where('company_id', $company));
        $scope()->where('status', 'processing')->where('lease_until', '<=', now())->update(['status' => 'pending', 'lease_until' => null, 'token' => null, 'published_at' => null, 'available_at' => now(), 'error_code' => 'lease_expired', 'updated_at' => now()]);
        $scope()->where('status', 'pending')->where('attempts', '>=', 5)->update(['status' => 'failed', 'error_code' => 'attempts_exhausted', 'updated_at' => now()]);
        DB::table('payment_orders')->when($company, fn ($q) => $q->where('company_id', $company))->where('status', 'open')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);
        // Bounded periodic reconciliation also recovers missing webhooks for recent checkouts.
        $recent = DB::table('payment_orders')->when($company, fn ($q) => $q->where('company_id', $company))->whereNotNull('preference_id')->where('created_at', '>=', now()->subDays(7))->where(fn ($q) => $q->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<=', now()->subMinutes(5)))->orderBy('created_at')->limit(min($limit, 25))->get();
        foreach ($recent as $order) {
            DB::transaction(function () use ($order): void {
                $current = DB::table('payment_orders')->where('id', $order->id)->lockForUpdate()->first();
                if ($current->last_reconciled_at && now()->diffInSeconds($current->last_reconciled_at, true) < 300) {
                    return;
                }DB::table('payment_orders')->where('id', $order->id)->update(['last_reconciled_at' => now()]);
                Collections::enqueue('reconcile', $order->company_id, $order->id, null, 'scheduled:'.$order->id.':'.intdiv(now()->timestamp, 300));
            });
        }
        $ids = $scope()->where('status', 'pending')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<', now()->subMinutes(3)))->orderBy('created_at')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $claimed = DB::table('payment_work')->where('id', $id)->where('status', 'pending')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<', now()->subMinutes(3)))->update(['published_at' => now()]);
            if (! $claimed) {
                continue;
            }
            try {
                Queue::connection(config('payments.queue_connection'))->push(new ProcessPaymentWork($id), '', 'payments');
                $count++;
            } catch (\Throwable) {
                DB::table('payment_work')->where('id', $id)->where('status', 'pending')->update(['published_at' => null, 'available_at' => now()->addSeconds(30), 'error_code' => 'queue_unavailable']);
            }
        }

        return $count;
    }
}
