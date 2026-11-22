<?php

namespace Tests\Feature;

use App\Actions\Documents\CreditNotes;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\Collections;
use App\Actions\Payments\PaymentWork;
use App\Contracts\PaymentGateway;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\User;
use App\Services\PaymentReceiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;
use Tests\Support\MerchantAccount;

class CollectionsTest extends DatabaseTestCase
{
    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('documents');
        config(['payments.enabled' => true, 'payments.access_token' => 'synthetic-test-token-not-real', 'payments.collector_id' => '123', 'payments.webhook_secret' => 'synthetic-webhook-secret', 'payments.notification_url' => 'https://demo.example.test/api/v1/webhooks/mercadopago', 'payments.return_origin' => 'https://demo.example.test']);
        $this->gateway = new class implements PaymentGateway
        {
            public function forReceiver(PaymentReceiver $receiver): PaymentGateway
            {
                return $this;
            }

            public int $creates = 0;

            public bool $timeout = false;

            public array $response = [];

            public ?array $recovered = null;

            public function create(object $o): array
            {
                $this->creates++;
                if ($this->timeout) {
                    throw new \RuntimeException('timeout');
                }

                return ['id' => '123-test', 'url' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-test'];
            }

            public function recover(object $o): ?array
            {
                return $this->recovered;
            }

            public function payment(string $id): array
            {
                return $this->response;
            }

            public function paymentIds(object $o): array
            {
                return ['500'];
            }
        };
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function fixture(string $role = 'owner', string $currency = 'PEN'): array
    {
        $u = User::factory()->create(['role' => 'owner']);
        $d = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id, 'currency' => $currency, 'customer_id' => Customer::factory()->for($u->company)->create()->id]);
        $d->items()->first()->update(['unit_price_cents' => 1000]);
        app(RequestIssuance::class)->handle($u, $d->id, 1, (string) Str::uuid());
        app(ProcessEmission::class)->handle($u->company_id, $d->id, 2);
        MerchantAccount::create($u->company_id);
        $u->update(['role' => $role]);
        $this->actingAs($u->fresh(), 'web');

        return [$u->fresh(), $d->fresh()];
    }

    private function order(Document $d, ?string $key = null)
    {
        return $this->postJson('/api/v1/documents/'.$d->id.'/payment-link', [], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function open(Document $d): object
    {
        $this->order($d)->assertAccepted();
        $w = DB::table('payment_work')->where('kind', 'create')->latest('created_at')->first();
        app(PaymentWork::class)->run($w->id);

        return DB::table('payment_orders')->where('document_id', $d->id)->first();
    }

    private function apply(object $o, string $id = '500', string $status = 'approved', string $date = '2026-11-13T10:00:00Z', array $extra = []): void
    {
        $this->gateway->response = array_replace(['id' => $id, 'collector_id' => $o->collector_id, 'external_reference' => $o->id, 'preference_id' => '123-test', 'live_mode' => false, 'currency_id' => 'PEN', 'transaction_amount' => sprintf('%d.%02d', intdiv($o->amount_cents, 100), $o->amount_cents % 100), 'transaction_amount_refunded' => '0', 'status' => $status, 'date_last_updated' => $date], $extra);
        Collections::enqueue('payment', $o->company_id, $o->id, $id, (string) Str::uuid());
        $w = DB::table('payment_work')->where('kind', 'payment')->where('status', 'pending')->first();
        app(PaymentWork::class)->run($w->id);
    }

    public function test_idempotency_reuses_intent_and_full_balance_without_changing_fiscal_state(): void
    {
        [, $d] = $this->fixture();
        $key = (string) Str::uuid();
        $id = $this->order($d, $key)->assertAccepted()->json('data.id');
        $this->order($d, $key)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->order($d)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseCount('payment_work', 1);
        $this->assertDatabaseHas('payment_orders', ['amount_cents' => $d->total_cents]);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->postJson('/api/v1/documents/'.$d->id.'/payment-link', ['amount' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_missing_config_and_usd_are_blocked(): void
    {
        [, $d] = $this->fixture('owner', 'USD');
        $this->order($d)->assertUnprocessable()->assertJsonPath('code', 'currency_unsupported');
        config(['payments.enabled' => false]);
        $this->order($d)->assertStatus(503);
        $this->assertDatabaseCount('payment_orders', 0);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        [$u,$d] = $this->fixture('accountant');
        $this->getJson('/api/v1/documents/'.$d->id.'/collections')->assertOk()->assertJsonPath('data.can_create', false);
        $this->order($d)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'web');
        $this->order($d)->assertNotFound();
        $this->getJson('/api/v1/documents/'.$d->id.'/collections')->assertNotFound();
    }

    public function test_disabling_a_merchant_blocks_new_links_but_preserves_a_late_payment(): void
    {
        [$owner, $doc] = $this->fixture();
        $order = $this->open($doc);
        $this->deleteJson('/api/v1/merchant-connection')->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->order($doc)->assertConflict()->assertJsonPath('code', 'merchant_connection_required');
        $this->getJson('/api/v1/documents/'.$doc->id.'/collections')->assertJsonPath('data.orders.0.checkout_url', null);
        $this->apply($order);
        $this->assertSame($doc->total_cents, app(Collections::class)->balance($doc)['paid_cents']);
        $this->assertDatabaseHas('payment_audit', ['event' => 'connection_disabled', 'actor_id' => $owner->id, 'connection_id' => $order->connection_id]);
    }

    public function test_changing_receiver_keeps_the_original_order_bound_to_the_verified_merchant(): void
    {
        [, $doc] = $this->fixture();
        $order = $this->open($doc);
        Http::fake(['https://api.mercadolibre.com/users/me' => Http::response(['id' => 789, 'site_id' => 'MPE', 'tags' => ['test_user']])]);
        $this->putJson('/api/v1/merchant-connection', ['access_token' => 'replacement-test-token', 'webhook_secret' => 'replacement-test-secret'])
            ->assertOk()->assertJsonPath('data.collector_id', '789')->assertJsonMissingPath('data.access_token')->assertJsonMissingPath('data.webhook_secret');
        $row = DB::table('merchant_connections')->where('collector_id', '789')->firstOrFail();
        $this->assertNotSame('replacement-test-token', $row->access_token);
        $this->assertNotSame('replacement-test-secret', $row->webhook_secret);
        $this->assertDatabaseHas('payment_orders', ['id' => $order->id, 'collector_id' => '456', 'connection_id' => $order->connection_id]);
        $this->apply($order, extra: ['collector_id' => '789']);
        $this->assertDatabaseCount('payments', 0);
        $this->getJson('/api/v1/receivables?operations=review')->assertOk()->assertJsonPath('data.rows.0.id', $doc->id);
    }

    public function test_one_payment_applies_once_and_stale_pending_cannot_regress(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        for ($i = 0; $i < 10; $i++) {
            $this->apply($o);
        }$this->apply($o, '500', 'pending', '2026-11-12T10:00:00Z');
        $this->assertDatabaseCount('payments', 1);
        $b = app(Collections::class)->balance($d);
        $this->assertSame($d->total_cents, $b['paid_cents']);
        $this->assertSame('paid', $b['status']);
        $this->assertSame('accepted', $d->fresh()->status);
        $this->order($d)->assertConflict()->assertJsonPath('code', 'no_balance');
    }

    public function test_two_real_payment_ids_are_not_deduplicated_as_one_payment(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $this->apply($o);
        $this->apply($o, '501');
        $b = app(Collections::class)->balance($d);
        $this->assertSame($d->total_cents * 2, $b['paid_cents']);
        $this->assertSame($d->total_cents, $b['excess_cents']);
        $this->assertSame('review', $b['status']);
    }

    public function test_refund_keeps_provisional_amount_and_blocks_new_checkout(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $this->apply($o);
        $this->apply($o, '500', 'refunded', '2026-11-14T10:00:00Z', ['transaction_amount_refunded' => '0.01']);
        $b = app(Collections::class)->balance($d);
        $this->assertSame($d->total_cents, $b['paid_cents']);
        $this->assertTrue($b['needs_review']);
        $this->order($d)->assertConflict();
    }

    #[TestWith(['live_mode', true])]
    #[TestWith(['external_reference', '00000000-0000-4000-8000-000000000000'])]
    #[TestWith(['date_last_updated', 'tomorrow'])]
    #[TestWith(['collector_id', '999'])]
    #[TestWith(['currency_id', 'USD'])]
    #[TestWith(['transaction_amount', '0.01'])]
    #[TestWith(['preference_id', 'other'])]
    public function test_mismatched_provider_payment_is_never_applied(string $field, mixed $value): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $this->apply($o, extra: [$field => $value]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('payment_work', ['kind' => 'payment', 'status' => 'review']);
    }

    public function test_uncertain_creation_recovery_never_repeats_post(): void
    {
        [, $d] = $this->fixture();
        $this->gateway->timeout = true;
        $o = $this->open($d);
        $this->assertSame('unknown', $o->status);
        $this->order($d)->assertConflict()->assertJsonPath('code', 'checkout_uncertain');
        $w = DB::table('payment_work')->where('kind', 'create')->first();
        $this->travel(31)->seconds();
        $this->gateway->recovered = ['id' => '123-test', 'url' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-test'];
        app(PaymentWork::class)->run($w->id);
        $this->assertSame(1, $this->gateway->creates);
        $this->assertDatabaseHas('payment_orders', ['id' => $o->id, 'status' => 'open']);
    }

    public function test_signed_webhook_is_durable_deduplicated_and_does_not_trust_body_status(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $ts = '1700000000';
        $signature = hash_hmac('sha256', 'id:500;request-id:request-1;ts:'.$ts.';', MerchantAccount::SECRET);
        $headers = ['x-request-id' => 'request-1', 'x-signature' => 'ts='.$ts.',v1='.$signature];
        $body = ['id' => 'event-1', 'user_id' => $o->collector_id, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '500'], 'status' => 'approved'];
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/webhooks/mercadopago/companies/'.$d->company_id.'?data.id=500', $body, $headers)->assertOk();
        }$this->assertSame(1, DB::table('payment_work')->where('kind', 'payment')->count());
        $this->assertDatabaseCount('payments', 0);
        $this->postJson('/api/v1/webhooks/mercadopago/companies/'.$d->company_id.'?data.id=501', $body, $headers)->assertUnauthorized();
        $this->gateway->response = ['id' => '500', 'collector_id' => $o->collector_id, 'external_reference' => $o->id, 'preference_id' => '123-test', 'live_mode' => false, 'currency_id' => 'PEN', 'transaction_amount' => '11.80', 'status' => 'approved', 'date_last_updated' => '2026-11-13T10:00:00Z'];
        $work = DB::table('payment_work')->where('kind', 'payment')->first();
        for ($i = 0; $i < 10; $i++) {
            app(PaymentWork::class)->run($work->id);
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1180, app(Collections::class)->balance($d)['paid_cents']);

    }

    public function test_expired_lease_recovery_and_attempt_limit(): void
    {
        [, $d] = $this->fixture();
        $this->order($d)->assertAccepted();
        $w = DB::table('payment_work')->first();
        DB::table('payment_work')->where('id', $w->id)->update(['status' => 'processing', 'token' => (string) Str::uuid(), 'lease_until' => now()->subMinute(), 'attempts' => 5]);
        app(PaymentWork::class)->tick($d->company_id);
        $this->assertDatabaseHas('payment_work', ['id' => $w->id, 'status' => 'failed', 'attempts' => 5]);
    }

    public function test_pending_then_approved_then_later_pending_is_reviewed_without_erasing_payment(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $this->apply($o, '500', 'pending');
        $this->assertSame(0, app(Collections::class)->balance($d)['paid_cents']);
        $this->apply($o, '500', 'approved', '2026-11-14T10:00:00Z');
        $this->apply($o, '500', 'pending', '2026-11-15T10:00:00Z');
        $this->assertSame($d->total_cents, app(Collections::class)->balance($d)['paid_cents']);
        $this->assertTrue(app(Collections::class)->balance($d)['needs_review']);
    }

    public function test_credit_boundary_blocks_reservations_and_exposes_late_payment_excess(): void
    {
        [$u,$d] = $this->fixture();
        $o = $this->open($d);
        $series = DocumentSeries::factory()->for($u->company)->create(['doc_type' => '07', 'code' => 'BC01']);
        $note = app(CreditNotes::class)->draft($u, $d->id, ['item_ids' => $d->items->pluck('id')->all(), 'series_id' => $series->id, 'reason' => 'Devolución completa']);
        app(RequestIssuance::class)->handle($u, $note->id, 1, (string) Str::uuid());
        $this->order($d)->assertConflict()->assertJsonPath('code', 'credit_reserved');
        $this->getJson('/api/v1/documents/'.$d->id.'/collections')->assertOk()->assertJsonPath('data.orders.0.checkout_url', null);
        app(ProcessEmission::class)->handle($u->company_id, $note->id, 2);
        $this->apply($o);
        $this->assertSame($d->total_cents, app(Collections::class)->balance($d)['excess_cents']);
        $this->assertDatabaseHas('payments', ['order_id' => $o->id, 'applied_cents' => $o->amount_cents]);
        $this->assertSame('review', app(Collections::class)->balance($d)['status']);
    }

    public function test_drafts_and_inactive_users_cannot_request_orders(): void
    {
        $u = User::factory()->create(['role' => 'owner']);
        $d = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id]);
        $this->actingAs($u, 'web');
        $this->order($d)->assertConflict()->assertJsonPath('code', 'document_not_accepted');
        $u->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($u->fresh(), 'web');
        $this->order($d)->assertUnauthorized();
        $this->assertDatabaseCount('payment_orders', 0);
    }

    public function test_issuer_creates_but_only_owner_can_reconcile_and_rate_limit_is_idempotent(): void
    {
        [$u,$d] = $this->fixture('issuer');
        $this->open($d);
        $url = '/api/v1/documents/'.$d->id.'/payments/reconcile';
        $key = (string) Str::uuid();
        $this->postJson($url, [], ['Idempotency-Key' => $key])->assertForbidden();
        $u->update(['role' => 'owner']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($u->fresh(), 'web');
        $this->postJson($url, [], ['Idempotency-Key' => $key])->assertAccepted();
        $this->postJson($url, [], ['Idempotency-Key' => $key])->assertAccepted();
        $this->postJson($url, [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(429);
    }

    public function test_invalid_webhook_body_and_unsupported_event_leave_no_work(): void
    {
        $sig = hash_hmac('sha256', 'id:500;request-id:r1;ts:1;', config('payments.webhook_secret'));
        $headers = ['x-request-id' => 'r1', 'x-signature' => 'ts=1,v1='.$sig];
        $url = '/api/v1/webhooks/mercadopago?data.id=500';
        $this->postJson($url, ['id' => 'event', 'data' => ['id' => '501']], $headers)->assertStatus(400);
        $this->postJson($url, ['id' => 'event', 'data' => ['id' => '500'], 'type' => 'other', 'action' => 'payment.updated', 'live_mode' => false], $headers)->assertUnprocessable();
        $this->assertDatabaseCount('payment_work', 0);
    }

    public function test_successful_reconciliation_resolves_technical_failure_without_deleting_audit(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        Collections::enqueue('reconcile', $o->company_id, $o->id, null, 'failed-search');
        $old = DB::table('payment_work')->where('kind', 'reconcile')->first();
        DB::table('payment_work')->where('id', $old->id)->update(['status' => 'failed', 'error_code' => 'provider_unavailable']);
        $this->assertTrue(app(Collections::class)->balance($d)['needs_review']);
        Collections::enqueue('reconcile', $o->company_id, $o->id, null, 'retry-search');
        $next = DB::table('payment_work')->where('kind', 'reconcile')->where('status', 'pending')->first();
        app(PaymentWork::class)->run($next->id);
        $this->assertDatabaseHas('payment_work', ['id' => $old->id, 'status' => 'resolved']);
        $this->assertFalse(app(Collections::class)->balance($d)['needs_review']);
        $this->assertDatabaseHas('payment_work', ['kind' => 'payment', 'payment_id' => '500', 'status' => 'pending']);
    }

    public function test_expired_checkout_is_hidden_and_replaced_only_with_new_intent(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $this->travel(25)->hours();
        $this->getJson('/api/v1/documents/'.$d->id.'/collections')->assertOk()->assertJsonPath('data.orders.0.checkout_url', null);
        $next = $this->order($d)->assertAccepted()->json('data.id');
        $this->assertNotSame($o->id, $next);
        $this->assertDatabaseHas('payment_orders', ['id' => $o->id, 'status' => 'expired']);
    }

    public function test_dashboard_payment_date_is_stable_after_retries_and_adjustments(): void
    {
        [, $d] = $this->fixture();
        $o = $this->open($d);
        $this->apply($o, '500', 'pending', '2026-10-31T23:00:00Z');
        $this->assertNull(DB::table('payments')->value('confirmed_at'));
        $this->apply($o, '500', 'approved', '2026-11-01T05:00:00Z');
        $confirmed = DB::table('payments')->value('confirmed_at');
        $this->apply($o, '500', 'approved', '2026-12-01T05:00:00Z');
        $this->apply($o, '500', 'refunded', '2026-12-02T05:00:00Z');
        $this->assertSame($confirmed, DB::table('payments')->value('confirmed_at'));
        $this->assertDatabaseCount('payments', 1);
        $this->getJson('/api/v1/dashboard?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('data.activity.collected_cents', (string) $d->total_cents)->assertJsonPath('data.portfolio.review_count', 1);
        $this->getJson('/api/v1/dashboard?from=2026-12-01&to=2026-12-31')->assertOk()->assertJsonPath('data.activity.collected_cents', '0');
    }
}
