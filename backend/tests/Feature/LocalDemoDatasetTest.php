<?php

namespace Tests\Feature;

use App\Actions\Demo\LocalDemoDataset;
use App\Actions\Issuance\EmissionOutbox;
use App\Actions\Issuance\ProcessEmission;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class LocalDemoDatasetTest extends DatabaseTestCase
{
    private function owner(): User
    {
        config(['issuance.demo_controls' => true]);
        $owner = User::factory()->owner()->create();
        User::factory()->issuer()->create(['company_id' => $owner->company_id]);
        User::factory()->accountant()->create(['company_id' => $owner->company_id]);

        return $owner;
    }

    public function test_dry_run_writes_nothing_and_cli_rejects_testing_database(): void
    {
        $owner = $this->owner();
        $result = app(LocalDemoDataset::class)->load($owner, false, true);
        $this->assertTrue($result['dry_run']);
        $this->assertDatabaseCount('local_demo_records', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->artisan('demo:load-local', ['company' => $owner->company_id, '--actor' => $owner->id])->assertFailed();
    }

    public function test_repeat_preserves_manual_changes_passwords_and_numbers(): void
    {
        $owner = $this->owner();
        $before = $owner->company->getAttributes();
        $passwords = User::pluck('password', 'id')->all();
        $load = app(LocalDemoDataset::class);
        $first = $load->load($owner);
        $this->assertSame(20, $first['summary']['customers']['created']);
        $this->assertSame(24, $first['summary']['products']['created']);
        $this->assertSame(12, $first['summary']['documents']['created']);
        $this->assertDatabaseCount('emission_requests', 6);
        $this->assertDatabaseCount('emission_outbox', 6);
        $this->assertSame(6, Document::where('status', 'draft')->whereNull('number')->count());
        $product = Product::where('code', 'DEMO-P001')->firstOrFail();
        $product->update(['description' => 'Cambio manual preservado', 'unit_price_cents' => 4321]);
        $customer = Customer::firstOrFail();
        $customer->update(['name' => 'Cliente editado manualmente']);
        $numbers = DocumentSeries::pluck('current_number', 'id')->all();
        $documents = Document::orderBy('id')->get()->toArray();
        $second = $load->load($owner);
        $this->assertSame(0, $second['summary']['documents']['created']);
        $this->assertSame(12, $second['summary']['documents']['reused']);
        $this->assertSame($numbers, DocumentSeries::pluck('current_number', 'id')->all());
        $this->assertSame($documents, Document::orderBy('id')->get()->toArray());
        $this->assertSame(4321, $product->fresh()->unit_price_cents);
        $this->assertSame('Cliente editado manualmente', $customer->fresh()->name);
        $this->assertSame($passwords, User::pluck('password', 'id')->all());
        $this->assertSame($before, $owner->company->fresh()->getAttributes());
        $this->assertDatabaseCount('emission_requests', 6);
    }

    public function test_real_simulator_rejects_and_recovers_without_another_number(): void
    {
        $owner = $this->owner();
        app(LocalDemoDataset::class)->load($owner);
        foreach (Document::where('status', 'queued')->get() as $doc) {
            app(ProcessEmission::class)->handle($doc->company_id, $doc->id, $doc->version);
        }
        $this->assertSame(4, Document::where('status', 'accepted')->count());
        $this->assertSame(1, Document::where('status', 'rejected')->count());
        $failed = Document::where('status', 'failed')->firstOrFail();
        $this->assertTrue($failed->can_retry);
        $this->assertSame('temporary', $failed->result_code);
        $number = $failed->number;
        $submission = $failed->submission_id;
        $counter = DocumentSeries::findOrFail($failed->series_id)->current_number;
        $this->travel(11)->seconds();
        app(EmissionOutbox::class)->recover();
        $failed->refresh();
        app(ProcessEmission::class)->handle($failed->company_id, $failed->id, $failed->version);
        $failed->refresh();
        $this->assertSame('accepted', $failed->status);
        $this->assertSame($number, $failed->number);
        $this->assertSame($submission, $failed->submission_id);
        $this->assertSame($counter, DocumentSeries::findOrFail($failed->series_id)->current_number);
        $this->assertSame(2, $failed->attempt_count);
        $this->assertDatabaseCount('document_pdfs', 5);
    }

    public function test_second_company_is_isolated_and_non_owner_cannot_load(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        app(LocalDemoDataset::class)->load($other, true);
        $this->assertSame(0, Customer::where('company_id', $owner->company_id)->count());
        $this->actingAs($owner)->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 0);
        $doc = Document::firstOrFail();
        $this->actingAs($owner)->getJson('/api/v1/documents/'.$doc->id)->assertNotFound();
        $this->assertNull($doc->number);
        $this->expectException(AuthorizationException::class);
        app(LocalDemoDataset::class)->load(User::where('company_id', $owner->company_id)->where('role', 'accountant')->firstOrFail());
    }

    public function test_partial_catalog_failure_rolls_back_and_can_resume(): void
    {
        $owner = $this->owner();
        $existing = Product::factory()->create(['company_id' => $owner->company_id, 'code' => 'DEMO-P001', 'currency' => 'USD']);
        try {
            app(LocalDemoDataset::class)->load($owner);
            $this->fail('Expected incompatible product validation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('local_demo_records', 0);
            $this->assertDatabaseCount('customers', 0);
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('products', 1);
        }
        $existing->update(['currency' => 'PEN']);
        $result = app(LocalDemoDataset::class)->load($owner);
        $this->assertSame(23, $result['summary']['products']['created']);
        $this->assertSame(1, $result['summary']['products']['reused']);
        $this->assertSame(12, $result['summary']['documents']['created']);
    }
}
