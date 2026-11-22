<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class CustomerStatementTest extends DatabaseTestCase
{
    private function sale(Customer $customer, User $actor, string $currency = 'PEN'): Document
    {
        $date = now('America/Lima')->subDays(2)->toDateString();
        $sale = Document::factory()->create(['company_id' => $actor->company_id, 'created_by' => $actor->id,
            'customer_id' => $customer->id, 'currency' => $currency, 'total_cents' => 1000, 'exempt_cents' => 1000,
            'payment_terms' => 'cash', 'issue_date' => $date, 'due_date' => $date]);
        $sale->update(['status' => 'accepted', 'series_code' => $sale->series->code, 'number' => 1, 'issued_at' => now(),
            'submission_id' => (string) Str::uuid(), 'fiscal_snapshot' => ['customer' => ['name' => $customer->name]]]);

        return $sale;
    }

    public function test_customer_balances_do_not_net_and_period_only_limits_movements(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'owner']);
        $client = Customer::factory()->create(['company_id' => $owner->company_id]);
        $other = Customer::factory()->create(['company_id' => $owner->company_id]);
        $sale = $this->sale($client, $owner);
        $debt = $this->sale($client, $owner);
        $usd = $this->sale($client, $owner, 'USD');
        $another = $this->sale($other, $owner);
        $order = (string) Str::uuid();
        $connection = (string) Str::uuid();
        DB::table('merchant_connections')->insert(['id' => $connection, 'company_id' => $owner->company_id,
            'collector_id' => '7100', 'active' => true, 'access_token' => 'private-test-field', 'webhook_secret' => 'private-test-field',
            'verified_at' => now(), 'updated_at' => now()]);
        $fields = ['provider' => 'mercadopago', 'company_id' => $owner->company_id, 'document_id' => $sale->id,
            'currency' => 'PEN', 'amount_cents' => 1300, 'created_at' => '2025-02-01 04:59:59+00', 'updated_at' => now()];
        DB::table('payment_orders')->insert($fields + ['id' => $order, 'requested_by' => $owner->id, 'status' => 'open',
            'expires_at' => now()->addDay(), 'receiver_kind' => 'merchant', 'collector_id' => '7100',
            'connection_id' => $connection, 'environment' => 'sandbox', 'checkout_url' => 'private-test-field']);
        DB::table('payments')->insert($fields + ['id' => (string) Str::uuid(), 'order_id' => $order, 'provider_id' => '811',
            'status' => 'approved', 'applied_cents' => 1300, 'provider_updated_at' => now(), 'confirmed_at' => '2025-02-01 05:00:00+00']);
        $url = '/api/v1/customers/'.$client->id.'/statement';
        $this->actingAs($owner);
        $february = $this->getJson($url.'?from=2025-02-01&to=2025-02-01')->assertOk()
            ->assertJsonPath('data.summary.outstanding_cents', '1000')->assertJsonPath('data.summary.overdue_cents', '1000')
            ->assertJsonPath('data.summary.excess_cents', '300')->assertJsonPath('data.summary.review', 1)
            ->assertJsonPath('data.movements.total', 1)->assertJsonPath('data.movements.rows.0.kind', 'mp_payment')
            ->assertJsonPath('data.movements.rows.0.sandbox', true)->assertJsonPath('data.movements.rows.0.amount_cents', '1300')
            ->assertDontSee('private-test-field')->json('data.summary');
        $this->getJson($url.'?from=2025-01-31&to=2025-01-31&view=print')->assertOk()
            ->assertJsonPath('data.movements.total', 1)->assertJsonPath('data.movements.rows.0.kind', 'order')
            ->assertJsonPath('data.movements.rows.0.order_state', 'open')->assertJsonPath('data.summary', $february);
        $this->getJson($url.'?document='.$debt->id)->assertOk()->assertJsonPath('data.summary', $february)
            ->assertJsonPath('data.documents.total', 2)->assertJsonPath('data.movements.rows.0.document_id', $debt->id);
        $this->getJson('/api/v1/receivables?customer_id='.$client->id)->assertOk()->assertJsonPath('data.summary.total', '1000');
        $this->getJson($url.'?currency=USD')->assertOk()->assertJsonPath('data.documents.rows.0.id', $usd->id)
            ->assertJsonPath('data.summary.excess_cents', '0')->assertJsonPath('data.summary.available_cents', '0');
        $this->getJson($url.'?document='.$another->id)->assertNotFound();
        $this->getJson($url.'?from=2025-02-02&to=2025-02-01')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_complete_print_pagination_permissions_and_tenant_boundaries(): void
    {
        $user = User::factory()->create(['role' => 'accountant']);
        $customer = Customer::factory()->create(['company_id' => $user->company_id, 'is_active' => false]);
        $foreign = Customer::factory()->create();
        $url = '/api/v1/customers/'.$customer->id.'/statement';
        for ($i = 0; $i < 21; $i++) {
            $this->sale($customer, $user);
        }
        $this->getJson($url.'?view=print')->assertUnauthorized();
        $this->actingAs($user);
        $one = $this->getJson($url)->assertOk()->assertJsonCount(20, 'data.movements.rows')
            ->assertJsonCount(20, 'data.documents.rows')->json('data');
        $two = $this->getJson($url.'?page=2&movements_page=2')->assertOk()->assertJsonCount(1, 'data.documents.rows')
            ->assertJsonPath('data.summary', $one['summary'])->json('data');
        $full = $this->getJson($url.'?view=print&page=2')->assertOk()->assertJsonCount(21, 'data.documents.rows')
            ->assertJsonCount(21, 'data.movements.rows')->assertJsonPath('data.summary.outstanding_cents', '21000')->json('data');
        $this->assertSame(array_merge($one['documents']['rows'], $two['documents']['rows']), $full['documents']['rows']);
        $this->assertSame(array_merge($one['movements']['rows'], $two['movements']['rows']), $full['movements']['rows']);
        $this->getJson('/api/v1/customers/'.$foreign->id.'/statement?view=print')->assertNotFound();
        $this->getJson($url.'?company_id='.$foreign->company_id)->assertUnprocessable();
        $this->postJson($url)->assertMethodNotAllowed();
        $this->assertDatabaseCount('bank_operations', 0);
        $this->assertDatabaseCount('payment_work', 0);
    }
}
