<?php

namespace Tests\Feature;

use App\Actions\Dashboard\BusinessDashboard;
use App\Actions\Payments\Collections;
use App\Actions\Treasury\BankLedger;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class BankReceiptsTest extends DatabaseTestCase
{
    private User $owner;

    private Customer $customer;

    private string $account;

    private string $base = '/api/v1/bank-receipts';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->customer = Customer::factory()->create(['company_id' => $this->owner->company_id]);
        $this->actingAs($this->owner);
        $this->postJson($this->base.'/accounts', ['alias' => 'Cuenta de prueba', 'institution' => 'Entidad de prueba', 'currency' => 'PEN', 'active' => true])->assertOk();
        $this->account = $this->getJson($this->base.'/accounts')->json('data.0.id');
    }

    private function receipt(array $extras = []): array
    {
        return $extras + ['request_id' => (string) Str::uuid(), 'account_id' => $this->account, 'currency' => 'PEN',
            'origin' => 'customer', 'bank_date' => now('America/Lima')->toDateString(), 'reference' => 'ABC 123',
            'method' => 'deposit', 'amount_cents' => 9000];
    }

    private function action(string $id, string $name, int $revision, array $payload)
    {
        return $this->postJson($this->base.'/'.$id.'/'.$name, $payload + ['request_id' => (string) Str::uuid(), 'revision' => $revision]);
    }

    private function sale(Customer $customer, string $currency = 'PEN'): Document
    {
        $doc = Document::factory()->create(['company_id' => $this->owner->company_id, 'customer_id' => $customer->id,
            'currency' => $currency, 'created_by' => $this->owner->id, 'total_cents' => 5000, 'exempt_cents' => 5000]);
        $doc->items()->update(['total_cents' => 5000, 'base_cents' => 5000, 'unit_price_cents' => 5000]);
        $doc->update(['status' => 'accepted', 'series_code' => $doc->series->code, 'number' => 1, 'issued_at' => now(),
            'submission_id' => (string) Str::uuid(), 'fiscal_snapshot' => ['customer' => ['name' => 'Cliente de prueba']]]);

        return $doc;
    }

    public function test_distribution_reversal_and_evidence_use_one_source_of_cash(): void
    {
        $a = $this->sale($this->customer);
        $b = $this->sale($this->customer);
        $follow = '/api/v1/receivables/'.$a->id.'/followup';
        $this->postJson($follow.'/promise', ['revision' => 0, 'currency' => 'PEN', 'amount_cents' => 2500, 'due_on' => now()->toDateString()])->assertOk();
        $id = $this->postJson($this->base, $this->receipt())->assertOk()->json('data.id');
        $this->action($id, 'confirm', 1, ['origin' => 'customer', 'verified' => true])->assertOk();
        $this->getJson($follow)->assertJsonPath('data.promise.covered_cents', '0');
        $this->getJson('/api/v1/customers/'.$this->customer->id.'/statement')->assertOk()
            ->assertJsonPath('data.receipts.total', 0)->assertJsonPath('data.summary.available_cents', '0')
            ->assertJsonPath('data.summary.outstanding_cents', '10000');
        $this->action($id, 'identify', 2, ['customer_id' => $this->customer->id])->assertOk();
        $lines = [['document_id' => $a->id, 'amount_cents' => 2500], ['document_id' => $b->id, 'amount_cents' => 5000]];
        $request = ['lines' => $lines, 'request_id' => (string) Str::uuid()];
        $this->action($id, 'apply', 3, $request)->assertOk();
        $this->action($id, 'apply', 3, $request)->assertOk()->assertJsonPath('data.repeated', true);
        $this->action($id, 'apply', 3, ['lines' => $lines])->assertConflict();
        $detail = $this->getJson($this->base.'/'.$id)->assertOk()->assertJsonPath('data.receipt.available_cents', '1500')->assertJsonPath('data.applications.total', 2);
        $app = collect($detail->json('data.applications.rows'))->firstWhere('document_id', $a->id)['id'];
        $this->assertSame(2500, app(Collections::class)->balance($a)['outstanding_cents']);
        $this->assertSame(0, app(Collections::class)->balance($b)['outstanding_cents']);
        $promise = $this->getJson($follow)->assertJsonPath('data.promise.can_fulfill', true)->json('data.promise.id');
        $this->postJson($follow.'/fulfill', ['revision' => 1, 'promise_id' => $promise])->assertOk();
        $period = BusinessDashboard::period([], CarbonImmutable::now('America/Lima'));
        $bank = BankLedger::indicators($this->owner->company_id, $period);
        $this->assertEquals(1, $bank['receipts']);
        $this->assertSame('7500', $bank['applied_cents']);
        $this->assertSame('9000', $bank['received_cents']);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity.collected_cents', '7500');
        $this->action($id, 'reverse', 4, ['application_id' => $app, 'reason' => 'Corrección de la imputación'])->assertOk();
        $this->action($id, 'reverse', 5, ['application_id' => $app, 'reason' => 'Intento repetido'])->assertConflict();
        $this->getJson($this->base.'/'.$id.'?application='.$app)->assertOk()->assertJsonPath('data.receipt.available_cents', '4000');
        $statement = $this->getJson('/api/v1/customers/'.$this->customer->id.'/statement?view=print')->assertOk()
            ->assertJsonPath('data.summary.available_cents', '4000')->assertJsonPath('data.summary.outstanding_cents', '5000')
            ->assertJsonPath('data.receipts.rows.0.id', $id)->assertDontSee('Corrección de la imputación')->json('data.movements.rows');
        $bankEvents = collect($statement)->where('receipt_id', $id);
        $this->assertCount(2, $bankEvents->where('kind', 'bank_application'));
        $this->assertSame('2500', $bankEvents->where('kind', 'bank_reversal')->sole()['amount_cents']);
        $this->assertSame($app, $bankEvents->where('kind', 'bank_reversal')->sole()['source_id']);
        $this->getJson($follow)->assertJsonPath('data.history.0.evidence_review', true)->assertJsonPath('data.outstanding_cents', '5000');
        $this->getJson('/api/v1/documents/'.$a->id.'/collections')->assertOk()
            ->assertJsonPath('data.outstanding_cents', 5000)->assertJsonPath('data.paid_cents', 0);
        $this->getJson('/api/v1/documents/'.$b->id.'/collections')->assertOk()
            ->assertJsonPath('data.outstanding_cents', 0)->assertJsonPath('data.paid_cents', 5000);
        $this->getJson('/api/v1/receivables?customer_id='.$this->customer->id.'&currency=PEN')->assertOk()
            ->assertJsonPath('data.summary.total', '5000')->assertJsonPath('data.rows.0.id', $a->id)
            ->assertJsonPath('data.rows.0.outstanding_cents', '5000');
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_duplicates_roles_and_settlements(): void
    {
        $issuer = User::factory()->create(['company_id' => $this->owner->company_id, 'role' => 'issuer']);
        $data = $this->receipt(['origin' => 'mp_settlement']);
        $id = $this->actingAs($issuer)->postJson($this->base, $data)->assertOk()->json('data.id');
        $this->postJson($this->base, $data)->assertOk()->assertJsonPath('data.repeated', true);
        $data['request_id'] = (string) Str::uuid();
        $this->postJson($this->base, $data)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->base, $this->receipt(['reference' => 'abc  123', 'amount_cents' => 9001]))
            ->assertConflict()->assertJsonPath('existing_id', $id);
        $this->action($id, 'confirm', 1, ['origin' => 'mp_settlement', 'verified' => true])->assertForbidden();
        $this->actingAs($this->owner);
        $this->action($id, 'confirm', 1, ['origin' => 'customer', 'verified' => true])->assertUnprocessable();
        $this->action($id, 'confirm', 1, ['origin' => 'mp_settlement', 'verified' => true])->assertOk();
        $this->action($id, 'identify', 2, ['customer_id' => $this->customer->id])->assertConflict();
        $this->getJson($this->base.'?situation=confirmed')->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.available_cents', '0');
        $this->putJson($this->base.'/accounts/'.$this->account, ['alias' => 'Cuenta histórica', 'institution' => 'Entidad',
            'active' => false, 'revision' => 1, 'currency' => 'PEN'])->assertOk();
        $this->postJson($this->base, $this->receipt(['reference' => 'Otra']))->assertUnprocessable();
        $this->actingAs(User::factory()->create(['company_id' => $this->owner->company_id, 'role' => 'accountant']))
            ->getJson($this->base.'/'.$id)->assertOk();
        $this->postJson($this->base, $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner']))->getJson($this->base.'/'.$id)->assertNotFound();
        $this->getJson($this->base)->assertJsonPath('data.total', 0);
    }

    public function test_credit_reservations_block_distribution_and_each_currency_has_its_own_portfolio(): void
    {
        $sale = $this->sale($this->customer);
        $usd = $this->sale($this->customer, 'USD');
        $id = $this->postJson($this->base, $this->receipt(['customer_id' => $this->customer->id]))->assertOk()->json('data.id');
        $this->action($id, 'confirm', 1, ['origin' => 'customer', 'verified' => false])->assertUnprocessable();
        $this->action($id, 'confirm', 1, ['origin' => 'customer', 'verified' => true])->assertOk();
        $note = Document::factory()->create(['company_id' => $sale->company_id, 'customer_id' => $this->customer->id,
            'doc_type' => '07', 'currency' => 'PEN', 'source_document_id' => $sale->id, 'credit_reason' => 'Corrección']);
        DB::table('credit_note_lines')->insert(['company_id' => $sale->company_id, 'source_document_id' => $sale->id,
            'source_item_id' => $sale->items()->firstOrFail()->id, 'note_id' => $note->id,
            'note_item_id' => $note->items()->firstOrFail()->id, 'state' => 'reserved']);
        $this->getJson($this->base.'/'.$id.'/candidates')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.rows.0.id', $sale->id)->assertJsonPath('data.rows.0.available_cents', '0');
        $this->action($id, 'apply', 2, ['lines' => [['document_id' => $sale->id, 'amount_cents' => 1]]])->assertConflict();
        $this->action($id, 'apply', 2, ['lines' => [['document_id' => $usd->id, 'amount_cents' => 1]]])->assertNotFound();
        $this->getJson('/api/v1/dashboard?currency=USD')->assertOk()->assertJsonPath('data.bank.received_cents', '0');
        $this->getJson('/api/v1/dashboard?currency=PEN')->assertOk()->assertJsonPath('data.bank.available_cents', '9000');
        $this->assertDatabaseCount('bank_applications', 0);
    }

    public function test_invalid_multi_document_batch_rolls_back_all_allocations(): void
    {
        $sale = $this->sale($this->customer);
        $other = $this->sale(Customer::factory()->create(['company_id' => $this->owner->company_id]));
        $id = $this->postJson($this->base, $this->receipt(['customer_id' => $this->customer->id]))->json('data.id');
        $this->action($id, 'apply', 1, ['lines' => [['document_id' => $sale->id, 'amount_cents' => 100]]])->assertConflict();
        $this->action($id, 'confirm', 1, ['origin' => 'customer', 'verified' => true])->assertOk();
        $this->action($id, 'apply', 2, ['lines' => [['document_id' => $sale->id, 'amount_cents' => 100],
            ['document_id' => $other->id, 'amount_cents' => 200]]])->assertNotFound();
        $this->action($id, 'apply', 2, ['lines' => [['document_id' => $sale->id, 'amount_cents' => 5001]]])->assertConflict();
        $this->getJson($this->base.'/'.$id.'/candidates')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($this->base.'/'.$id)->assertJsonPath('data.receipt.available_cents', '9000');
        $this->assertDatabaseCount('bank_applications', 0);
    }
}
