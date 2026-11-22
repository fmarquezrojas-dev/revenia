<?php

namespace Tests\Feature;

use App\Actions\Documents\DraftDocuments;
use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Issuance\ProcessEmission;
use App\Actions\Issuance\RequestIssuance;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class CreditNotesTest extends DatabaseTestCase
{
    private User $actor;

    private Document $source;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'issuer']);
        $company = $this->actor->company;
        $series = DocumentSeries::factory()->for($company)->create(['code' => 'F001', 'doc_type' => '01']);
        $credit = DocumentSeries::factory()->for($company)->create(['code' => 'FC01', 'doc_type' => '07']);
        $customer = Customer::factory()->for($company)->create(['doc_type' => 'ruc', 'doc_number' => '20131312955']);
        $items = [];
        foreach ([80000, 20000] as $price) {
            $product = Product::factory()->for($company)->create(['unit_price_cents' => $price]);
            $items[] = ['product_id' => $product->id, 'quantity' => '1.000'];
        }
        $draft = app(DraftDocuments::class)->save($this->actor, ['doc_type' => '01', 'series_id' => $series->id, 'customer_id' => $customer->id,
            'currency' => 'PEN', 'issue_date' => now('America/Lima')->toDateString(), 'items' => $items]);
        app(RequestIssuance::class)->handle($this->actor, $draft->id, 1, (string) Str::uuid());
        $this->process($draft->id);
        $this->source = $draft->fresh('items');
        $product->update(['is_active' => false, 'unit_price_cents' => 55, 'tax_type' => '20']);
        $this->payload = ['reason' => 'Devolución de accesorio', 'series_id' => $credit->id, 'item_ids' => [$this->source->items[1]->id]];
        $this->actingAs($this->actor, 'web');
    }

    private function process(string $id): void
    {
        $doc = Document::findOrFail($id);
        app(ProcessEmission::class)->handle($doc->company_id, $id, $doc->version);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/documents/'.$this->source->id.$suffix;
    }

    private function draft(): string
    {
        return $this->postJson($this->url('/credit-notes'), $this->payload)->assertCreated()
            ->assertJsonPath('data.doc_type', '07')->assertJsonPath('data.source_document_id', $this->source->id)
            ->assertJsonPath('data.totals.igv_cents', 3600)->assertJsonPath('data.totals.total_cents', 23600)->json('data.id');
    }

    #[TestWith([100000, 0, 5600])]
    #[TestWith([0, 94400, 0])]
    public function test_credit_application_and_period_reconcile(int $paid, int $due, int $excess): void
    {
        if ($paid) {
            $order = (string) Str::uuid();
            $common = ['provider' => 'test-history', 'company_id' => $this->actor->company_id, 'document_id' => $this->source->id, 'currency' => 'PEN', 'amount_cents' => $paid, 'created_at' => now(), 'updated_at' => now()];
            DB::table('payment_orders')->insert($common + ['id' => $order, 'requested_by' => $this->actor->id, 'status' => 'closed', 'expires_at' => now()]);
            DB::table('payments')->insert($common + ['id' => (string) Str::uuid(), 'order_id' => $order, 'provider_id' => '100', 'status' => 'approved',
                'applied_cents' => $paid, 'confirmed_at' => now(), 'provider_updated_at' => now()]);
        }
        $this->getJson($this->url('/collections'))->assertOk()->assertJsonPath('data.outstanding_cents', 118000 - $paid);
        $note = $this->draft();
        $this->getJson($this->url('/collections'))->assertJsonPath('data.reserved_cents', 0);
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $issue = '/api/v1/documents/'.$note.'/issue';
        $response = $this->postJson($issue, ['version' => 1], $key)->assertAccepted()->json();
        $this->postJson($issue, ['version' => 1], $key)->assertExactJson($response);
        $this->getJson($this->url('/collections'))->assertJsonPath('data.net_cents', 118000)->assertJsonPath('data.reserved_cents', 23600);
        $this->getJson($this->url())->assertJsonPath('data.credit.states.'.$this->payload['item_ids'][0], 'reserved');
        $statement = '/api/v1/customers/'.$this->source->customer_id.'/statement?document='.$this->source->id;
        $this->getJson($statement)->assertOk()->assertJsonPath('data.documents.rows.0.reserved_cents', '23600')
            ->assertJsonPath('data.documents.rows.0.credits_cents', '0')->assertJsonFragment(['kind' => 'reserve']);
        $this->process($note);
        $this->process($note);
        $this->postJson($issue, ['version' => 1], $key)->assertExactJson($response);
        $this->getJson($this->url('/collections'))->assertOk()->assertJsonPath('data.original_cents', 118000)
            ->assertJsonPath('data.credits_cents', 23600)->assertJsonPath('data.net_cents', 94400)->assertJsonPath('data.reserved_cents', 0)
            ->assertJsonPath('data.paid_cents', $paid)->assertJsonPath('data.outstanding_cents', $due)->assertJsonPath('data.excess_cents', $excess)
            ->assertJsonPath('data.needs_review', $excess > 0);
        $this->getJson($this->url())->assertJsonPath('data.credit.states.'.$this->payload['item_ids'][0], 'applied')->assertJsonPath('data.credit.notes.0.id', $note);
        $this->getJson($statement)->assertOk()->assertJsonPath('data.documents.rows.0.reserved_cents', '0')
            ->assertJsonPath('data.documents.rows.0.credits_cents', '23600')->assertJsonFragment(['kind' => 'credit'])
            ->assertJsonPath('data.summary.outstanding_cents', (string) $due)->assertJsonPath('data.summary.excess_cents', (string) $excess);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.activity.accepted_cents', '118000')->assertJsonPath('data.activity.credit_notes_cents', '23600')
            ->assertJsonPath('data.activity.collected_cents', (string) $paid)->assertJsonPath('data.portfolio.outstanding_cents', (string) $due)
            ->assertJsonPath('data.portfolio.excess_cents', (string) $excess)->assertJsonPath('data.portfolio.review_count', $excess > 0 ? 1 : 0);
        $this->getJson('/api/v1/dashboard?metric=credits')->assertJsonPath('data.detail.rows.0.document_id', $note);
        $this->getJson('/api/v1/dashboard?currency=USD')->assertJsonPath('data.activity.credit_notes_cents', '0')->assertJsonPath('data.portfolio.excess_cents', '0');
        $this->getJson('/api/v1/dashboard?from=2000-01-01&to=2000-01-31')->assertJsonPath('data.activity.credit_notes_cents', '0')->assertJsonPath('data.portfolio.excess_cents', (string) $excess);
        $this->assertDatabaseCount('emission_consumptions', 2);
        $this->postJson($this->url('/credit-notes'), $this->payload)->assertConflict();
        $doc = Document::findOrFail($note);
        $html = view('documents.pdf', ['doc' => $doc, 'snapshot' => $doc->fiscal_snapshot])->render();
        foreach (['NOTA DE CRÉDITO', 'F001-00000001', '236.00 PEN', 'Emisión tributaria simulada', 'Devolución de accesorio'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    #[TestWith(['rejected', 'released'])]
    #[TestWith(['permanent', 'released'])]
    #[TestWith(['timeout_after', 'reserved'])]
    public function test_result_and_recovery(string $scenario, string $state): void
    {
        config(['issuance.demo_controls' => true]);
        $note = $this->draft();
        DB::table('simulation_scenarios')->insert(['document_id' => $note, 'scenario' => $scenario]);
        app(RequestIssuance::class)->handle($this->actor, $note, 1, (string) Str::uuid());
        $this->process($note);
        $this->assertDatabaseHas('credit_note_lines', ['note_id' => $note, 'state' => $state]);
        if ($state === 'released') {
            $replacement = $this->draft();
            app(RequestIssuance::class)->handle($this->actor, $replacement, 1, (string) Str::uuid());
            $this->assertDatabaseHas('credit_note_lines', ['note_id' => $replacement, 'state' => 'reserved']);
        } else {
            $this->getJson($this->url('/collections'))->assertJsonPath('data.net_cents', 118000)->assertJsonPath('data.reserved_cents', 23600);
            $this->travel(11)->seconds();
            app(EmissionOutbox::class)->recover();
            $this->process($note);
            $this->assertDatabaseHas('credit_note_lines', ['note_id' => $note, 'state' => 'applied']);
            $this->assertDatabaseHas('simulated_submissions', ['document_id' => $note, 'calls' => 1]);
            $this->assertDatabaseCount('emission_consumptions', 2);
            $this->assertDatabaseHas('document_series', ['id' => $this->payload['series_id'], 'current_number' => 1]);
        }
    }

    public function test_all_original_lines_settle_the_whole_invoice(): void
    {
        $payload = array_replace($this->payload, ['item_ids' => $this->source->items->pluck('id')->all()]);
        $id = $this->postJson($this->url('/credit-notes'), $payload)->assertCreated()
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.totals.total_cents', 118000)->json('data.id');
        app(RequestIssuance::class)->handle($this->actor, $id, 1, (string) Str::uuid());
        $this->process($id);
        $this->getJson($this->url('/collections'))->assertJsonPath('data.net_cents', 0)->assertJsonPath('data.excess_cents', 0);
        $this->postJson($this->url('/credit-notes'), $payload)->assertConflict();
    }

    public function test_usd_boleta_uses_its_historical_line_rounding_and_its_own_portfolio(): void
    {
        $company = $this->actor->company;
        $saleSeries = DocumentSeries::factory()->for($company)->create(['doc_type' => '03', 'code' => 'B001']);
        $creditSeries = DocumentSeries::factory()->for($company)->create(['doc_type' => '07', 'code' => 'BC01']);
        $product = Product::factory()->for($company)->create(['currency' => 'USD', 'unit_price_cents' => 3]);
        $sale = app(DraftDocuments::class)->save($this->actor, ['doc_type' => '03', 'currency' => 'USD',
            'series_id' => $saleSeries->id, 'customer_id' => $this->source->customer_id, 'issue_date' => now('America/Lima')->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => '1.500']]]);
        app(RequestIssuance::class)->handle($this->actor, $sale->id, 1, (string) Str::uuid());
        $this->process($sale->id);
        $product->update(['unit_price_cents' => 9999, 'is_active' => false]);
        $url = '/api/v1/documents/'.$sale->id.'/credit-notes';
        $payload = array_replace($this->payload, ['item_ids' => [$sale->items[0]->id]]);
        $this->postJson($url, $payload)->assertNotFound();
        $payload['series_id'] = $creditSeries->id;
        $note = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.items.0.base_cents', 5)->assertJsonPath('data.items.0.igv_cents', 1)
            ->assertJsonPath('data.items.0.quantity', '1.500')->json('data.id');
        app(RequestIssuance::class)->handle($this->actor, $note, 1, (string) Str::uuid());
        $this->process($note);
        $this->getJson('/api/v1/dashboard?currency=USD')->assertJsonPath('data.activity.accepted_cents', '6')
            ->assertJsonPath('data.activity.credit_notes_cents', '6')->assertJsonPath('data.portfolio.outstanding_cents', '0');
        $this->getJson('/api/v1/dashboard?currency=PEN')->assertJsonPath('data.activity.credit_notes_cents', '0')
            ->assertJsonPath('data.portfolio.outstanding_cents', '118000');
    }

    public function test_database_rejects_a_credit_link_to_another_company(): void
    {
        $note = $this->draft();
        $foreign = Document::factory()->create();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('23503');
        DB::table('documents')->where('id', $note)->update(['source_document_id' => $foreign->id]);
    }

    public function test_foreign_references_roles_and_draft_boundaries(): void
    {
        $foreign = User::factory()->create(['role' => 'owner']);
        $foreignDoc = Document::factory()->create(['company_id' => $foreign->company_id, 'created_by' => $foreign->id]);
        $this->postJson($this->url('/credit-notes'), array_replace($this->payload, ['item_ids' => [$foreignDoc->items[0]->id]]))->assertNotFound();
        $this->postJson($this->url('/credit-notes'), array_replace($this->payload, ['series_id' => $foreignDoc->series_id]))->assertNotFound();
        $this->postJson($this->url('/credit-notes'), $this->payload + ['total_cents' => 1])->assertUnprocessable();
        $note = $this->draft();
        $this->postJson('/api/v1/documents/'.$note.'/credit-notes', $this->payload)->assertConflict();
        $this->app['auth']->forgetGuards();
        $this->actingAs($foreign, 'web');
        $this->getJson('/api/v1/documents/'.$note)->assertNotFound();
        $this->postJson($this->url('/credit-notes'), $this->payload)->assertNotFound();
        $this->postJson('/api/v1/documents/'.$note.'/issue', ['version' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
        $this->actor->update(['role' => 'accountant']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->actor->fresh(), 'web');
        $this->getJson('/api/v1/documents/'.$note)->assertOk();
        $this->postJson($this->url('/credit-notes'), $this->payload)->assertForbidden();
        $this->postJson('/api/v1/documents/'.$note.'/issue', ['version' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    }
}
