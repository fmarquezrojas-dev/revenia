<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class DraftDocumentsTest extends DatabaseTestCase
{
    private function fixture(string $role = 'issuer'): array
    {
        $actor = User::factory()->create(['role' => $role]);
        $customer = Customer::factory()->for($actor->company)->create(['doc_type' => 'ruc', 'doc_number' => '20131312955']);
        $series = DocumentSeries::factory()->for($actor->company)->create(['doc_type' => '01', 'code' => 'F001']);
        $product = Product::factory()->for($actor->company)->create(['currency' => 'PEN', 'unit_price_cents' => 1000, 'tax_type' => '10']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($actor, 'web');
        $data = ['doc_type' => '01', 'currency' => 'PEN', 'issue_date' => now('America/Lima')->toDateString(), 'customer_id' => $customer->id, 'series_id' => $series->id, 'items' => [['product_id' => $product->id, 'quantity' => '3.000']]];

        return [$actor, $customer, $series, $product, $data];
    }

    private function createDraft(array $data): array
    {
        return $this->postJson('/api/v1/documents', $data)->assertCreated()->json('data');
    }

    public function test_create_preview_and_edit_preserve_snapshots_and_numbers(): void
    {
        [$actor,$customer,$series,$product,$data] = $this->fixture();
        $this->postJson('/api/v1/documents/preview', $data)->assertOk()->assertJsonPath('data.totals.total_cents', 3540);
        $this->assertDatabaseCount('documents', 0);
        $doc = $this->createDraft($data);
        $this->assertSame(null, $doc['number']);
        $this->assertSame(3540, $doc['totals']['total_cents']);
        $this->assertSame('3.000', $doc['items'][0]['quantity']);
        $product->update(['unit_price_cents' => 9000, 'description' => 'Catalogo cambiado', 'tax_type' => '20']);
        $data['version'] = 1;
        $data['items'][0]['id'] = $doc['items'][0]['id'];
        $data['items'][0]['quantity'] = '2.000';
        $this->postJson('/api/v1/documents/preview', array_merge($data, ['document_id' => $doc['id']]))->assertOk()->assertJsonPath('data.totals.total_cents', 2360);
        $this->patchJson('/api/v1/documents/'.$doc['id'], $data)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.items.0.id', $doc['items'][0]['id'])->assertJsonPath('data.items.0.unit_price_cents', 1000)->assertJsonPath('data.totals.total_cents', 2360);
        $this->getJson('/api/v1/documents/'.$doc['id'])->assertOk()->assertJsonPath('data.totals.total_cents', 2360);
        $this->assertSame(0, $series->fresh()->current_number);
        $this->assertDatabaseCount('document_items', 1);
    }

    public function test_replacement_takes_new_snapshot_and_removal_preserves_other_identity(): void
    {
        [$actor,,,$product,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        $new = Product::factory()->for($actor->company)->create(['currency' => 'PEN', 'unit_price_cents' => 35, 'tax_type' => '10']);
        $data['version'] = 1;
        $data['items'] = [['id' => $doc['items'][0]['id'], 'product_id' => $new->id, 'quantity' => '3']];
        $this->patchJson('/api/v1/documents/'.$doc['id'], $data)->assertOk()->assertJsonPath('data.items.0.id', $doc['items'][0]['id'])->assertJsonPath('data.totals.total_cents', 124);
    }

    #[TestWith(['owner', true])] #[TestWith(['issuer', true])] #[TestWith(['accountant', false])]
    public function test_role_matrix(string $role, bool $writes): void
    {
        [$actor,,,$product,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        $actor->role = $role;
        $actor->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($actor->fresh(), 'web');
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents/'.$doc['id'])->assertOk();
        $this->postJson('/api/v1/documents', $data)->assertStatus($writes ? 201 : 403);
        $this->postJson('/api/v1/documents/preview', $data)->assertStatus($writes ? 200 : 403);
        $this->getJson('/api/v1/document-series-options?doc_type=01')->assertStatus($writes ? 200 : 403);
        $this->patchJson('/api/v1/documents/'.$doc['id'], array_merge($data, ['version' => 1]))->assertStatus($writes ? 200 : 403);
        $this->deleteJson('/api/v1/documents/'.$doc['id'], ['version' => $writes ? 2 : 1])->assertStatus($writes ? 204 : 403);
        $this->assertSame($writes, $actor->can('create', Document::class));
        if ($role === 'issuer') {
            $this->getJson('/api/v1/series')->assertForbidden();
        }
    }

    public function test_unauthenticated_endpoints(): void
    {
        $id = '00000000-0000-4000-8000-000000000000';
        foreach ([['get', '/documents'], ['get', "/documents/$id"], ['post', '/documents'], ['patch', "/documents/$id"], ['delete', "/documents/$id"], ['post', '/documents/preview'], ['get', '/document-series-options?doc_type=01']] as [$method,$path]) {
            $this->{$method.'Json'}('/api/v1'.$path)->assertUnauthorized();
        }
    }

    public function test_foreign_resources_and_item_ids_are_hidden(): void
    {
        [$a,,,,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        [$b,$customer,$series,$product,$other] = $this->fixture();
        $otherDoc = $this->createDraft($other);
        $this->getJson('/api/v1/documents')->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $doc['id']]);
        foreach (['get', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/v1/documents/'.$doc['id'], ['version' => 1])->assertNotFound();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($a, 'web');
        foreach (['customer_id' => $customer->id, 'series_id' => $series->id] as $key => $value) {
            $this->postJson('/api/v1/documents', array_replace($data, [$key => $value]))->assertNotFound();
        }
        $bad = $data;
        $bad['items'][0]['product_id'] = $product->id;
        $this->postJson('/api/v1/documents', $bad)->assertNotFound();
        $bad = $data;
        $bad['version'] = 1;
        $bad['items'][0]['id'] = $otherDoc['items'][0]['id'];
        $this->patchJson('/api/v1/documents/'.$doc['id'], $bad)->assertNotFound();
        $this->postJson('/api/v1/documents/preview', array_merge($bad, ['document_id' => $doc['id']]))->assertNotFound();
    }

    public function test_conflicts_and_delete_require_current_draft_version(): void
    {
        [,,,,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        $url = '/api/v1/documents/'.$doc['id'];
        $this->patchJson($url, array_merge($data, ['version' => 1]))->assertOk();
        $this->patchJson($url, array_merge($data, ['version' => 1]))->assertConflict()->assertJsonPath('code', 'document_version_conflict');
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->postJson('/api/v1/documents/preview', array_merge($data, ['version' => 1, 'document_id' => $doc['id']]))->assertConflict();
        $this->deleteJson($url, [])->assertUnprocessable();
        DB::table('documents')->where('id', $doc['id'])->update(['status' => 'issued']);
        $this->deleteJson($url, ['version' => 2])->assertConflict()->assertJsonPath('code', 'document_not_draft');
        $this->patchJson($url, array_merge($data, ['version' => 2]))->assertConflict();
        $this->assertDatabaseCount('document_items', 1);
        DB::table('documents')->where('id', $doc['id'])->update(['status' => 'draft']);
        $this->deleteJson($url, ['version' => 2])->assertNoContent();
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_items', 0);
    }

    #[TestWith(['PEN', 70000, false, 201])] #[TestWith(['PEN', 70001, false, 422])] #[TestWith(['USD', 1, false, 422])] #[TestWith(['USD', 1, true, 201])]
    public function test_simulator_identification(string $currency, int $total, bool $identified, int $status): void
    {
        [$actor,$customer,$series,$product,$data] = $this->fixture();
        $series->update(['doc_type' => '03', 'code' => 'B001']);
        $product->update(['currency' => $currency, 'unit_price_cents' => $total, 'tax_type' => '20']);
        $data = array_replace($data, ['doc_type' => '03', 'currency' => $currency, 'customer_id' => $identified ? $customer->id : null]);
        $data['items'][0]['quantity'] = '1';
        $this->postJson('/api/v1/documents', $data)->assertStatus($status);
    }

    #[TestWith(['customer'])] #[TestWith(['product'])] #[TestWith(['series'])]
    public function test_inactive_references_stay_readable_but_cannot_save(string $ref): void
    {
        [$actor,$customer,$series,$product,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        ${$ref}->update(['is_active' => false]);
        $this->getJson('/api/v1/documents/'.$doc['id'])->assertOk();
        $this->patchJson('/api/v1/documents/'.$doc['id'], array_merge($data, ['version' => 1]))->assertUnprocessable();
        $this->assertSame(1, Document::findOrFail($doc['id'])->version);
    }

    public function test_invalid_data_never_writes_and_guarded_fields_are_rejected(): void
    {
        [$actor,$customer,$series,$product,$data] = $this->fixture();
        foreach (['company_id', 'created_by', 'status', 'number', 'issued_at', 'total_cents', 'taxable_cents', 'igv_cents', 'exempt_cents', 'unaffected_cents'] as $field) {
            $this->postJson('/api/v1/documents', array_merge($data, [$field => null]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['doc_type' => '07'], ['customer_id' => null], ['issue_date' => now('America/Lima')->addDay()->toDateString()], ['currency' => 'USD'], ['items' => []], ['items' => array_fill(0, 51, $data['items'][0])], ['items' => [$data['items'][0], $data['items'][0]]]] as $change) {
            $this->postJson('/api/v1/documents', array_replace($data, $change))->assertUnprocessable();
        }
        $bad = $data;
        $bad['items'][0]['unit_price_cents'] = 1;
        $this->postJson('/api/v1/documents', $bad)->assertUnprocessable();
        $customer->update(['doc_type' => 'dni', 'doc_number' => '12345678']);
        $this->postJson('/api/v1/documents', $data)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(0, $series->fresh()->current_number);
    }

    public function test_line_failure_rolls_back_header_and_items(): void
    {
        [$actor,,,,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        $second = Product::factory()->for($actor->company)->create(['currency' => 'PEN', 'is_active' => false]);
        $bad = $data;
        $bad['version'] = 1;
        $bad['items'][0]['quantity'] = '9';
        $bad['items'][] = ['product_id' => $second->id, 'quantity' => '1'];
        $this->patchJson('/api/v1/documents/'.$doc['id'], $bad)->assertUnprocessable();
        $this->assertDatabaseHas('documents', ['id' => $doc['id'], 'version' => 1, 'total_cents' => 3540]);
        $this->assertDatabaseHas('document_items', ['id' => $doc['items'][0]['id'], 'quantity_millis' => 3000]);
    }

    #[TestWith(['customer_id'])] #[TestWith(['series_id'])] #[TestWith(['created_by'])] #[TestWith(['product_id'])]
    public function test_database_rejects_cross_company_links(string $field): void
    {
        [,,,,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        [$actor,$customer,$series,$product] = $this->fixture();
        $foreign = match ($field) {
            'customer_id' => $customer->id,'series_id' => $series->id,'created_by' => $actor->id,'product_id' => $product->id
        };
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($field, $foreign, $doc): void {
            DB::table($field === 'product_id' ? 'document_items' : 'documents')->where('id', $field === 'product_id' ? $doc['items'][0]['id'] : $doc['id'])->update([$field => $foreign]);
        });
    }

    public function test_filters_and_limited_series_selector(): void
    {
        [$actor,$customer,$series,,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        DocumentSeries::factory()->for($actor->company)->create(['doc_type' => '03', 'code' => 'B001']);
        DocumentSeries::factory()->create(['doc_type' => '01', 'code' => 'F999']);
        $this->getJson('/api/v1/document-series-options?doc_type=01')->assertOk()->assertExactJson(['data' => [['id' => $series->id, 'code' => 'F001', 'doc_type' => '01']]]);
        $this->getJson('/api/v1/documents?doc_type=01&status=draft&customer_id='.$customer->id.'&per_page=1')->assertOk()->assertJsonPath('data.0.id', $doc['id']);
        $this->getJson('/api/v1/documents?doc_type=03')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents?per_page=101')->assertUnprocessable();
    }

    public function test_maximum_lines_and_document_overflow(): void
    {
        [$actor, , , $product, $data] = $this->fixture();
        $data['items'] = [];
        $products = Product::factory()->count(50)->for($actor->company)->create(['currency' => 'PEN', 'unit_price_cents' => 0, 'tax_type' => '10']);
        foreach ($products as $row) {
            $data['items'][] = ['product_id' => $row->id, 'quantity' => '1'];
        }
        $doc = $this->createDraft($data);
        $this->assertCount(50, $doc['items']);
        $products[0]->update(['unit_price_cents' => 9007199254740991, 'tax_type' => '20']);
        $products[1]->update(['unit_price_cents' => 1, 'tax_type' => '20']);
        $this->postJson('/api/v1/documents/preview', $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_date_is_lima_and_single_end_date_filter_works(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 02:00:00', 'UTC'));
        [,,,,$data] = $this->fixture();
        $data['issue_date'] = '2026-11-12';
        $this->postJson('/api/v1/documents', $data)->assertUnprocessable()->assertJsonValidationErrors('issue_date');
        $data['issue_date'] = '2026-11-11';
        $this->createDraft($data);
        $this->getJson('/api/v1/documents?date_to=2026-11-11')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/documents?date_from=2026-11-12')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_preview_edit_does_not_change_version_items_or_counter(): void
    {
        [,, $series, , $data] = $this->fixture();
        $doc = $this->createDraft($data);
        $before = Document::with('items')->findOrFail($doc['id'])->toArray();
        $data['document_id'] = $doc['id'];
        $data['version'] = 1;
        $data['items'][0]['id'] = $doc['items'][0]['id'];
        $data['items'][0]['quantity'] = '9.999';
        $this->postJson('/api/v1/documents/preview', $data)->assertOk();
        $this->assertSame($before, Document::with('items')->findOrFail($doc['id'])->toArray());
        $this->assertSame(0, $series->fresh()->current_number);
    }

    public function test_database_rejects_item_of_another_company_document(): void
    {
        [,,,,$data] = $this->fixture();
        $doc = $this->createDraft($data);
        [,,,,$other] = $this->fixture();
        $foreign = $this->createDraft($other);
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($doc, $foreign): void {
            DB::table('document_items')->where('id', $doc['items'][0]['id'])->update(['document_id' => $foreign['id']]);
        });
    }

    public function test_currency_change_cannot_reinterpret_existing_snapshot(): void
    {
        [,,, $product, $data] = $this->fixture();
        $doc = $this->createDraft($data);
        $product->update(['currency' => 'USD', 'unit_price_cents' => 2000]);
        $this->getJson('/api/v1/documents/'.$doc['id'])->assertJsonPath('data.items.0.currency', 'PEN')->assertJsonPath('data.items.0.unit_price_cents', 1000);
        $data['currency'] = 'USD';
        $data['version'] = 1;
        $data['items'][0]['id'] = $doc['items'][0]['id'];
        $this->patchJson('/api/v1/documents/'.$doc['id'], $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->assertDatabaseHas('documents', ['id' => $doc['id'], 'currency' => 'PEN', 'version' => 1]);
        unset($data['items'][0]['id']);
        $this->patchJson('/api/v1/documents/'.$doc['id'], $data)->assertOk()->assertJsonPath('data.items.0.currency', 'USD')->assertJsonPath('data.items.0.unit_price_cents', 2000)->assertJsonPath('data.version', 2);
    }

    public function test_full_draft_cycle_has_no_emission_side_effects(): void
    {
        [$actor, , $series, , $data] = $this->fixture();
        $beforeCompany = $actor->company->fresh()->getAttributes();
        $beforeSeries = $series->fresh()->getAttributes();
        Bus::fake();
        Http::fake();

        $this->postJson('/api/v1/documents/preview', $data)->assertOk();
        $doc = $this->createDraft($data);
        $this->assertNull($doc['number']);
        $this->assertNull($doc['issued_at']);
        $data['version'] = 1;
        $data['items'][0]['id'] = $doc['items'][0]['id'];
        $data['items'][0]['quantity'] = '2.500';
        $this->postJson('/api/v1/documents/preview', array_merge($data, ['document_id' => $doc['id']]))->assertOk();
        $this->patchJson('/api/v1/documents/'.$doc['id'], $data)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.number', null)->assertJsonPath('data.issued_at', null);
        $this->deleteJson('/api/v1/documents/'.$doc['id'], ['version' => 2])->assertNoContent();

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_items', 0);
        $this->assertSame($beforeSeries, $series->fresh()->getAttributes());
        $this->assertSame($beforeCompany, $actor->company->fresh()->getAttributes());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    #[TestWith(['user'])]
    #[TestWith(['company'])]
    public function test_inactive_account_cannot_access_drafts(string $target): void
    {
        [$actor, , , , $data] = $this->fixture();
        $doc = $this->createDraft($data);
        if ($target === 'user') {
            $actor->update(['is_active' => false]);
        } else {
            $actor->company->update(['is_active' => false]);
        }
        foreach ([['get', '/documents'], ['get', '/documents/'.$doc['id']], ['post', '/documents/preview'], ['patch', '/documents/'.$doc['id']], ['delete', '/documents/'.$doc['id']]] as [$method, $path]) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($actor->fresh(), 'web');
            $this->{$method.'Json'}('/api/v1'.$path, array_merge($data, ['version' => 1]))->assertUnauthorized();
        }
        $this->assertDatabaseHas('documents', ['id' => $doc['id'], 'version' => 1]);
    }
}
