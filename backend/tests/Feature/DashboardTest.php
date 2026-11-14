<?php

namespace Tests\Feature;

use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class DashboardTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T15:00:00Z'));
        Http::preventStrayRequests();
        Storage::fake('documents');
    }

    private function actor(string $role = 'owner'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'web');

        return $user;
    }

    private function accepted(User $user, int $amount = 1000, string $currency = 'PEN', string $date = '2026-11-13T15:00:00Z'): Document
    {
        $doc = Document::factory()->create(['company_id' => $user->company_id, 'created_by' => $user->id, 'currency' => $currency, 'customer_id' => Customer::factory()->create(['company_id' => $user->company_id])->id]);
        $doc->items()->first()->update(['unit_price_cents' => $amount, 'tax_type' => '20']);
        app(RequestIssuance::class)->handle($user, $doc->id, 1, (string) Str::uuid());
        app(ProcessEmission::class)->handle($user->company_id, $doc->id, 2);
        DB::table('document_events')->where('document_id', $doc->id)->where('to_status', 'accepted')->update(['created_at' => $date]);

        return $doc->fresh();
    }

    private function payment(Document $doc, int $amount, string $status = 'approved', ?string $order = null): string
    {
        $id = (string) Str::uuid();
        if (! $order) {
            $order = (string) Str::uuid();
            DB::table('payment_orders')->insert(['id' => $order, 'company_id' => $doc->company_id, 'document_id' => $doc->id, 'requested_by' => $doc->created_by, 'amount_cents' => $amount, 'currency' => 'PEN', 'status' => 'closed', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('payments')->insert(['id' => $id, 'company_id' => $doc->company_id, 'document_id' => $doc->id, 'order_id' => $order, 'provider_id' => (string) random_int(1, PHP_INT_MAX), 'amount_cents' => $amount, 'applied_cents' => $status === 'approved' ? $amount : 0, 'currency' => 'PEN', 'status' => $status, 'provider_updated_at' => now(), 'confirmed_at' => $status === 'approved' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_empty_company_has_zeroes_and_explicit_unsupported_credits(): void
    {
        $this->actor();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.accepted_cents', '0')->assertJsonPath('data.activity.collected_cents', '0')->assertJsonPath('data.activity.credit_notes_cents', null)->assertJsonPath('data.activity.credit_notes_supported', false)->assertJsonPath('data.portfolio.outstanding_cents', '0')->assertJsonCount(30, 'data.series')->assertJsonPath('data.usage.remaining', 50);
    }

    public function test_known_activity_reconciles_without_join_multiplication_and_excludes_other_currency_and_company(): void
    {
        $u = $this->actor();
        $d = $this->accepted($u, 1000);
        $this->accepted($u, 2000);
        $this->accepted($u, 9000, 'USD');
        $other = User::factory()->create();
        $this->accepted($other, 8000);
        $first = $this->payment($d, 1000);
        $order = DB::table('payments')->find($first)->order_id;
        $this->payment($d, 1000, 'approved', $order);
        $this->payment($d, 900, 'pending');
        $this->payment($d, 800, 'rejected');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.accepted_cents', '3000')->assertJsonPath('data.activity.accepted_count', 2)->assertJsonPath('data.activity.collected_cents', '2000')->assertJsonPath('data.portfolio.outstanding_cents', '2000')->assertJsonPath('data.portfolio.excess_cents', '1000')->assertJsonPath('data.portfolio.review_count', 1);
        $rows = $this->getJson('/api/v1/dashboard?metric=accepted')->assertOk()->json('data.detail.rows');
        $this->assertSame(3000, array_sum(array_column($rows, 'amount_cents')));
        $this->assertCount(2, $this->getJson('/api/v1/dashboard?metric=payments')->assertOk()->json('data.detail.rows'));
        $this->getJson('/api/v1/dashboard?currency=USD')->assertOk()->assertJsonPath('data.activity.accepted_cents', '9000')->assertJsonPath('data.activity.collected_cents', '0');
        $this->getJson('/api/v1/dashboard?metric=excess')->assertOk()->assertJsonPath('data.detail.rows.0.document_id', $d->id)->assertJsonPath('data.detail.rows.0.amount_cents', '1000');
    }

    public function test_lima_boundaries_use_acceptance_not_issue_date_and_portfolio_is_current(): void
    {
        $u = $this->actor();
        $this->accepted($u, 100, 'PEN', '2026-11-01T04:59:59Z');
        $this->accepted($u, 200, 'PEN', '2026-11-01T05:00:00Z');
        $this->accepted($u, 300, 'PEN', '2026-11-02T04:59:59Z');
        $this->accepted($u, 400, 'PEN', '2026-11-02T05:00:00Z');
        $this->getJson('/api/v1/dashboard?from=2026-11-01&to=2026-11-01')->assertOk()->assertJsonPath('data.activity.accepted_cents', '500')->assertJsonPath('data.series.0.accepted_cents', '500')->assertJsonPath('data.portfolio.outstanding_cents', '1000')->assertJsonPath('data.period.end_exclusive_utc', '2026-11-02T05:00:00+00:00');
        $this->getJson('/api/v1/dashboard?from=2020-01-01&to=2020-01-01')->assertOk()->assertJsonPath('data.activity.accepted_cents', '0')->assertJsonPath('data.portfolio.outstanding_cents', '1000');
    }

    #[TestWith(['owner', true, true])]
    #[TestWith(['issuer', true, false])]
    #[TestWith(['accountant', false, false])]
    public function test_roles_keep_existing_read_visibility_and_action_permissions(string $role, bool $create, bool $manage): void
    {
        $this->actor($role);
        $response = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.actions.create_document', $create)->assertJsonPath('data.actions.manage_subscription', $manage);
        $this->assertArrayNotHasKey('history', $response->json('data.usage'));
        $this->assertArrayNotHasKey('price_cents', $response->json('data.usage'));
    }

    #[TestWith(['from=2026-02-30'])]
    #[TestWith(['from=2026-12-01&to=2026-11-01'])]
    #[TestWith(['from=2020-01-01&to=2026-01-01'])]
    #[TestWith(['currency=EUR'])]
    #[TestWith(['group_by=company_id'])]
    #[TestWith(['company_id=foreign'])]
    #[TestWith(['metric=subscription'])]
    #[TestWith(['sort=total_cents'])]
    public function test_invalid_filters_are_rejected(string $query): void
    {
        $this->actor();
        $this->getJson('/api/v1/dashboard?'.$query)->assertUnprocessable();
    }

    public function test_guest_and_inactive_accounts_cannot_read_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $u = $this->actor();
        $u->company->update(['is_active' => false]);
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_large_aggregates_remain_exact_decimal_strings(): void
    {
        $u = $this->actor();
        $this->accepted($u, 9007199254740991);
        $this->accepted($u, 9007199254740991);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.accepted_cents', '18014398509481982')->assertJsonPath('data.portfolio.outstanding_cents', '18014398509481982');
    }

    public function test_duplicate_acceptance_events_do_not_duplicate_amounts(): void
    {
        $u = $this->actor();
        $d = $this->accepted($u);
        $event = (array) DB::table('document_events')->where('document_id', $d->id)->where('to_status', 'accepted')->first();
        $event['id'] = (string) Str::uuid();
        DB::table('document_events')->insert($event);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.accepted_cents', '1000')->assertJsonPath('data.activity.accepted_count', 1);
    }

    public function test_operational_alerts_and_rejections_have_matching_details(): void
    {
        $u = $this->actor();
        config(['issuance.demo_controls' => true]);
        foreach (['rejected', 'temporary', 'queued'] as $scenario) {
            $d = Document::factory()->create(['company_id' => $u->company_id, 'created_by' => $u->id]);
            if ($scenario !== 'queued') {
                $this->artisan('issuance:scenario', ['document' => $d->id, 'scenario' => $scenario])->assertSuccessful();
            }
            app(RequestIssuance::class)->handle($u, $d->id, 1, (string) Str::uuid());
            if ($scenario !== 'queued') {
                app(ProcessEmission::class)->handle($u->company_id, $d->id, 2);
            }
        }
        $accepted = $this->accepted($u);
        DB::table('document_pdfs')->where('document_id', $accepted->id)->update(['status' => 'failed']);
        $response = $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.rejected_count', 1)->assertJsonPath('data.alerts.failed', 1)->assertJsonPath('data.alerts.queued', 1)->assertJsonPath('data.alerts.pdf_failed', 1)->assertJsonPath('data.usage.consumed', 4);
        foreach (['failed', 'queued', 'pdf_failed'] as $metric) {
            $this->getJson('/api/v1/dashboard?metric='.$metric)->assertOk()->assertJsonPath('data.detail.total', $response->json('data.alerts.'.$metric));
        }
    }

    public function test_paginated_details_and_query_count_are_bounded_for_large_fixture(): void
    {
        $u = $this->actor();
        $d = $this->accepted($u, 100);
        $template = (array) DB::table('documents')->find($d->id);
        $events = [];
        $documents = [];
        for ($i = 2; $i <= 501; $i++) {
            $row = $template;
            $row['id'] = (string) Str::uuid();
            $row['number'] = $i;
            $row['submission_id'] = (string) Str::uuid();
            $documents[] = $row;
            $events[] = ['id' => (string) Str::uuid(), 'company_id' => $u->company_id, 'document_id' => $row['id'], 'type' => 'accepted', 'from_status' => 'sending', 'to_status' => 'accepted', 'created_at' => now()];
        }
        foreach (array_chunk($documents, 100) as $chunk) {
            DB::table('documents')->insert($chunk);
        }
        DB::table('document_events')->insert($events);
        DB::enableQueryLog();
        $start = microtime(true);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.accepted_count', 501)->assertJsonPath('data.activity.accepted_cents', '50100');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(40, count($queries));
        fwrite(STDERR, sprintf("\nDashboard 501 docs: %d queries, %.1f ms request, %.1f ms SQL\n", count($queries), (microtime(true) - $start) * 1000, array_sum(array_column($queries, 'time'))));
        $this->getJson('/api/v1/dashboard?metric=accepted&page=2')->assertOk()->assertJsonCount(20, 'data.detail.rows')->assertJsonPath('data.detail.total', 501)->assertJsonPath('data.detail.last_page', 26);
    }
}
