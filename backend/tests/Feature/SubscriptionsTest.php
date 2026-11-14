<?php

namespace Tests\Feature;

use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Actions\Payments\Collections;
use App\Actions\Payments\PaymentWork;
use App\Actions\Subscriptions\Subscriptions;
use App\Contracts\PaymentGateway;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class SubscriptionsTest extends DatabaseTestCase
{
    private object $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-31T15:00:00Z'));
        config(['subscriptions.test_fixture_grants' => false, 'payments.enabled' => true, 'payments.access_token' => 'synthetic-test-token-not-real', 'payments.collector_id' => '123', 'payments.webhook_secret' => 'synthetic-webhook-secret', 'payments.notification_url' => 'https://demo.example.test/webhook', 'payments.return_origin' => 'https://demo.example.test']);
        Http::preventStrayRequests();
        Storage::fake('documents');
        $this->gateway = new class implements PaymentGateway
        {
            public array $response = [];

            public function create(object $o): array
            {
                return ['id' => 'pref-'.$o->id, 'url' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=pref-'.$o->id];
            }

            public function recover(object $o): ?array
            {
                return $this->create($o);
            }

            public function payment(string $id): array
            {
                return $this->response;
            }

            public function paymentIds(object $o): array
            {
                return [];
            }
        };
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function actor(string $role = 'owner'): User
    {
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u, 'web');

        return $u;
    }

    private function purchase(string $code = 'demo-basic', ?string $key = null)
    {
        return $this->postJson('/api/v1/subscriptions', ['plan_code' => $code], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function order(string $plan = 'demo-basic'): object
    {
        $id = $this->purchase($plan)->assertAccepted()->json('data.order_id');
        $w = DB::table('payment_work')->where('order_id', $id)->where('kind', 'create')->first();
        app(PaymentWork::class)->run($w->id);

        return DB::table('payment_orders')->find($id);
    }

    private function pay(object $o, string $id = '500', string $status = 'approved', array $extra = []): void
    {
        $this->gateway->response = array_replace(['id' => $id, 'collector_id' => '123', 'external_reference' => $o->id, 'preference_id' => $o->preference_id, 'live_mode' => false, 'currency_id' => 'PEN', 'transaction_amount' => sprintf('%d.%02d', intdiv($o->amount_cents, 100), $o->amount_cents % 100), 'transaction_amount_refunded' => '0', 'status' => $status, 'date_last_updated' => now()->toIso8601String()], $extra);
        Collections::enqueue('payment', $o->company_id, $o->id, $id, (string) Str::uuid());
        $w = DB::table('payment_work')->where('kind', 'payment')->where('status', 'pending')->first();
        app(PaymentWork::class)->run($w->id);
    }

    private function document(User $u): Document
    {
        $d = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id]);
        $d->items()->first()->update(['unit_price_cents' => 100]);

        return $d;
    }

    private function issue(Document $d, ?string $key = null)
    {
        return $this->postJson('/api/v1/documents/'.$d->id.'/issue', ['version' => $d->version], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_first_purchase_requires_provider_approval_and_snapshots_terms(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->assertNull(app(Subscriptions::class)->current($u->company_id));
        DB::table('plans')->where('code', 'demo-basic')->update(['price_cents' => 9999, 'emission_limit' => 999, 'available' => false]);
        $this->pay($o, status: 'pending');
        $this->assertNull(app(Subscriptions::class)->current($u->company_id));
        $this->travel(1)->seconds();
        $this->pay($o);
        $s = app(Subscriptions::class)->current($u->company_id);
        $this->assertNotNull($s);
        $this->assertSame(1900, $s->price_cents);
        $this->assertSame(50, $s->emission_limit);
        $this->assertSame('2026-02-28 15:00:01', CarbonImmutable::parse($s->ends_at)->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseHas('payments', ['provider_id' => '500', 'document_id' => null, 'subscription_id' => $s->id]);
    }

    public function test_idempotency_and_pending_equivalence(): void
    {
        $this->actor();
        $key = (string) Str::uuid();
        $id = $this->purchase(key: $key)->assertAccepted()->json('data.id');
        $this->purchase(key: $key)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->purchase()->assertAccepted()->assertJsonPath('data.id', $id);
        $this->purchase('demo-plus', $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->purchase('demo-plus')->assertConflict();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payment_orders', 1);
    }

    public function test_catalog_disabled_and_arbitrary_terms_are_rejected(): void
    {
        $this->actor();
        $this->postJson('/api/v1/subscriptions', ['plan_code' => 'demo-basic', 'price_cents' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        DB::table('plans')->where('code', 'demo-basic')->update(['available' => false]);
        $this->purchase()->assertUnprocessable()->assertJsonPath('code', 'plan_unavailable');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_renewal_is_future_and_preserves_anchor_without_short_month_drift(): void
    {
        $u = $this->actor();
        $first = $this->order();
        $this->pay($first);
        $current = app(Subscriptions::class)->current($u->company_id);
        $this->travel(3)->days();
        $next = $this->order('demo-plus');
        $this->pay($next, '501');
        $future = DB::table('subscriptions')->find($next->subscription_id);
        $this->assertTrue(CarbonImmutable::parse($future->starts_at)->eq($current->ends_at));
        $this->assertSame('2026-03-31', CarbonImmutable::parse($future->ends_at)->toDateString());
        $this->assertSame($current->id, app(Subscriptions::class)->current($u->company_id)->id);
        $this->purchase()->assertConflict()->assertJsonPath('code', 'renewal_scheduled');
        $this->travelTo(CarbonImmutable::parse($future->starts_at));
        $this->assertSame($future->id, app(Subscriptions::class)->current($u->company_id)->id);
    }

    public function test_late_payment_reactivates_from_confirmation_without_changing_expired_period(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        $old = app(Subscriptions::class)->current($u->company_id);
        $renew = $this->order();
        $this->travelTo(CarbonImmutable::parse('2026-03-10T12:00:00Z'));
        $this->pay($renew, '501');
        $new = app(Subscriptions::class)->current($u->company_id);
        $this->assertTrue(CarbonImmutable::parse($new->starts_at)->eq(now()));
        $this->assertSame($old->ends_at, DB::table('subscriptions')->find($old->id)->ends_at);
    }

    public function test_repeated_payment_activates_once_but_distinct_payment_requires_review(): void
    {
        $u = $this->actor();
        $o = $this->order();
        for ($i = 0; $i < 10; $i++) {
            $this->pay($o);
        }$this->assertDatabaseCount('company_usage', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->pay($o, '501');
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('review', app(Subscriptions::class)->usage($u->company_id)['status']);
    }

    #[TestWith(['rejected'])]
    #[TestWith(['pending'])]
    public function test_nonapproved_payments_do_not_activate(string $status): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o, status: $status);
        $this->assertNull(app(Subscriptions::class)->current($u->company_id));
        $this->assertDatabaseCount('company_usage', 0);
    }

    public function test_incompatible_payment_never_grants_rights(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o, extra: ['transaction_amount' => '0.01']);
        $this->assertNull(app(Subscriptions::class)->current($u->company_id));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_refund_restricts_emission_without_erasing_period_or_history(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        $d = $this->document($u);
        $this->issue($d)->assertAccepted();
        $this->travel(1)->seconds();
        $this->pay($o, status: 'refunded', extra: ['transaction_amount_refunded' => '19.00']);
        $this->assertSame('review', app(Subscriptions::class)->usage($u->company_id)['status']);
        $this->issue($this->document($u))->assertUnprocessable()->assertJsonPath('code', 'subscription_review');
        $this->assertDatabaseCount('emission_consumptions', 1);
        $this->getJson('/api/v1/documents/'.$d->id)->assertOk();
    }

    public function test_quota_consumes_once_and_exhaustion_does_not_take_a_number(): void
    {
        $u = $this->actor();
        DB::table('plans')->where('code', 'demo-basic')->update(['emission_limit' => 1]);
        $o = $this->order();
        $this->pay($o);
        $d = $this->document($u);
        $key = (string) Str::uuid();
        $this->issue($d, $key)->assertAccepted();
        $this->issue($d, $key)->assertAccepted();
        $second = $this->document($u);
        $this->issue($second)->assertUnprocessable()->assertJsonPath('code', 'quota_exhausted');
        $this->assertNull($second->fresh()->number);
        $this->assertSame(0, $second->series->fresh()->current_number);
        $this->assertDatabaseCount('emission_consumptions', 1);
        $this->assertSame(1, DB::table('company_usage')->value('consumed'));
    }

    public function test_rollback_reverts_quota_number_and_outbox(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        $d = $this->document($u);
        try {
            DB::transaction(function () use ($u, $d): void {
                app(RequestIssuance::class)->handle($u, $d->id, 1, (string) Str::uuid());
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }$this->assertNull($d->fresh()->number);
        $this->assertSame(0, $d->series->fresh()->current_number);
        $this->assertDatabaseCount('emission_consumptions', 0);
        $this->assertDatabaseCount('emission_outbox', 0);
        $this->assertSame(0, DB::table('company_usage')->value('consumed'));
    }

    public function test_expiry_is_effective_without_scheduler_but_history_remains_accessible(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        $s = app(Subscriptions::class)->current($u->company_id);
        $this->travelTo(CarbonImmutable::parse($s->ends_at));
        $this->issue($this->document($u))->assertUnprocessable()->assertJsonPath('code', 'subscription_expired');
        $this->getJson('/api/v1/documents')->assertOk();
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.usage.status', 'expired');
        $this->purchase()->assertAccepted();
    }

    #[TestWith(['issuer'])]
    #[TestWith(['accountant'])]
    public function test_nonowners_see_operational_usage_without_contract_payments(string $role): void
    {
        $this->actor($role);
        $this->getJson('/api/v1/usage')->assertOk();
        $this->getJson('/api/v1/plans')->assertForbidden();
        $this->getJson('/api/v1/subscription')->assertForbidden();
        $this->purchase()->assertForbidden();
    }

    public function test_foreign_contract_is_hidden(): void
    {
        $this->actor();
        $o = $this->order();
        $this->app['auth']->forgetGuards();
        $this->actor();
        $this->getJson('/api/v1/subscriptions/'.$o->subscription_id)->assertNotFound();
    }

    public function test_demo_grant_is_repeatable_and_never_renews_rights(): void
    {
        $u = $this->actor();
        $s = app(Subscriptions::class);
        $s->grantDemo($u->company_id);
        $d = $this->document($u);
        $this->issue($d)->assertAccepted();
        $this->travel(40)->days();
        $this->assertFalse($s->grantDemo($u->company_id)['created']);
        $this->assertSame('expired', $s->usage($u->company_id)['status']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(1, DB::table('company_usage')->value('consumed'));
        $this->assertSame([], $s->audit($u->company_id));
        DB::table('company_usage')->increment('consumed');
        $this->assertCount(1, $s->audit($u->company_id));
        $this->assertSame(2, DB::table('company_usage')->value('consumed'));
    }

    public function test_leap_year_boundaries_retain_original_anchor(): void
    {
        $this->assertSame('2028-02-29', Subscriptions::boundary('2028-01-31T15:00:00Z', 1)->toDateString());
        $this->assertSame('2028-03-31', Subscriptions::boundary('2028-01-31T15:00:00Z', 2)->toDateString());
    }

    public function test_cancelled_intent_late_payment_is_recorded_without_overwriting_new_period(): void
    {
        $u = $this->actor();
        $old = $this->order();
        $this->postJson('/api/v1/subscriptions/'.$old->subscription_id.'/cancel', [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $new = $this->order('demo-plus');
        $this->pay($new, '501');
        $period = DB::table('subscriptions')->find($new->subscription_id);
        $this->pay($old, '500');
        $this->assertDatabaseCount('company_usage', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('subscriptions', ['id' => $old->subscription_id, 'status' => 'review', 'starts_at' => null]);
        $this->assertSame($period->starts_at, DB::table('subscriptions')->find($new->subscription_id)->starts_at);
    }

    public function test_rejection_and_technical_retry_keep_exactly_one_consumption(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        config(['issuance.demo_controls' => true]);
        $d = $this->document($u);
        $this->artisan('issuance:scenario', ['document' => $d->id, 'scenario' => 'rejected'])->assertSuccessful();
        $this->issue($d)->assertAccepted();
        app(ProcessEmission::class)->handle($u->company_id, $d->id, 2);
        $this->assertSame('rejected', $d->fresh()->status);
        $this->assertSame(1, DB::table('company_usage')->value('consumed'));
        $retry = $this->document($u);
        $this->artisan('issuance:scenario', ['document' => $retry->id, 'scenario' => 'temporary'])->assertSuccessful();
        $this->issue($retry)->assertAccepted();
        app(ProcessEmission::class)->handle($u->company_id, $retry->id, 2);
        $retry->refresh();
        $this->assertTrue($retry->can_retry);
        $this->postJson('/api/v1/documents/'.$retry->id.'/retry', ['version' => $retry->version], ['Idempotency-Key' => (string) Str::uuid()])->assertAccepted();
        $this->assertSame(2, DB::table('company_usage')->value('consumed'));
        $this->assertDatabaseCount('emission_consumptions', 2);
    }

    public function test_paid_subscription_cannot_mutate_captured_terms(): void
    {
        $this->actor();
        $o = $this->order();
        $this->pay($o);
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->where('id', $o->subscription_id)->update(['emission_limit' => 999]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_database_rejects_orders_with_both_or_neither_destination(bool $both): void
    {
        $u = $this->actor();
        $o = $this->order();
        $d = $this->document($u);
        $this->expectException(QueryException::class);
        DB::table('payment_orders')->where('id', $o->id)->update($both ? ['document_id' => $d->id] : ['subscription_id' => null]);
    }

    public function test_document_payment_never_activates_subscription_and_plan_payment_never_reduces_document_balance(): void
    {
        $u = $this->actor();
        app(Subscriptions::class)->grantDemo($u->company_id);
        $d = $this->document($u);
        $this->issue($d)->assertAccepted();
        app(ProcessEmission::class)->handle($u->company_id, $d->id, 2);
        $d->refresh();
        $subscriptionOrder = $this->order();
        $this->pay($subscriptionOrder);
        $this->assertSame(0, app(Collections::class)->balance($d)['paid_cents']);
        $before = DB::table('subscriptions')->count();
        $id = $this->postJson('/api/v1/documents/'.$d->id.'/payment-link', [], ['Idempotency-Key' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        $w = DB::table('payment_work')->where('order_id', $id)->where('kind', 'create')->first();
        app(PaymentWork::class)->run($w->id);
        $this->pay(DB::table('payment_orders')->find($id), '501');
        $this->assertSame($before, DB::table('subscriptions')->count());
        $this->assertSame($d->total_cents, app(Collections::class)->balance($d)['paid_cents']);
    }

    public function test_overlap_is_rejected_even_outside_service(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        $s = (array) DB::table('subscriptions')->find($o->subscription_id);
        $s['id'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::table('subscriptions')->insert($s);
    }

    public function test_catalog_sync_is_idempotent_and_does_not_renew_or_change_contracts(): void
    {
        $u = $this->actor();
        $o = $this->order();
        $this->pay($o);
        $before = DB::table('subscriptions')->find($o->subscription_id);
        $catalog = config('subscriptions.catalog');
        $catalog[0]['price_cents'] = 2500;
        $catalog[0]['emission_limit'] = 70;
        config(['subscriptions.catalog' => $catalog]);
        $this->artisan('subscriptions:maintain', ['--sync-catalog' => true])->assertSuccessful();
        $this->artisan('subscriptions:maintain', ['--sync-catalog' => true])->assertSuccessful();
        $this->assertDatabaseCount('plans', 2);
        $this->assertDatabaseCount('subscriptions', 1);
        $after = DB::table('subscriptions')->find($o->subscription_id);
        $this->assertSame($before->ends_at, $after->ends_at);
        $this->assertSame(1900, $after->price_cents);
        $this->assertSame(50, $after->emission_limit);
    }

    public function test_missing_subscription_allows_drafts_but_no_issuance_and_inactive_account_is_rejected(): void
    {
        $u = $this->actor();
        $d = $this->document($u);
        $this->getJson('/api/v1/documents/'.$d->id)->assertOk();
        $this->issue($d)->assertUnprocessable()->assertJsonPath('code', 'subscription_missing');
        $this->assertNull($d->fresh()->number);
        $u->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($u->fresh(), 'web');
        $this->getJson('/api/v1/usage')->assertUnauthorized();
        $this->purchase()->assertUnauthorized();
    }

    public function test_individual_contract_remains_accessible_beyond_recent_history(): void
    {
        $this->actor();
        $o = $this->order();
        $row = (array) DB::table('subscriptions')->find($o->subscription_id);
        $row['status'] = 'cancelled';
        $row['created_at'] = now()->addSecond();
        for ($i = 0; $i < 51; $i++) {
            $row['id'] = (string) Str::uuid();
            DB::table('subscriptions')->insert($row);
        }
        $this->getJson('/api/v1/subscriptions/'.$o->subscription_id)->assertOk()->assertJsonPath('data.id', $o->subscription_id);
    }

    public function test_subscription_payment_is_excluded_from_business_dashboard(): void
    {
        $this->actor();
        $o = $this->order();
        $this->pay($o);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.collected_cents', '0')->assertJsonPath('data.activity.payments_count', 0)->assertJsonPath('data.usage.limit', 50);
        $this->getJson('/api/v1/dashboard?metric=payments')->assertOk()->assertJsonCount(0, 'data.detail.rows');
    }
}
