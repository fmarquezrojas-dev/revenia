<?php

namespace Tests\Feature;

use App\Actions\Customers\SaveCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class CustomerTest extends DatabaseTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'doc_type' => 'dni', 'doc_number' => '00123456', 'name' => 'Cliente de prueba',
            'address' => null, 'email' => null,
        ], $overrides);
    }

    public function test_guest_cannot_use_any_endpoint(): void
    {
        $id = Customer::factory()->create()->id;
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->getJson("/api/v1/customers/$id")->assertUnauthorized();
        $this->postJson('/api/v1/customers', $this->payload())->assertUnauthorized();
        $this->patchJson("/api/v1/customers/$id", ['name' => 'Otro'])->assertUnauthorized();
    }

    #[TestWith(['owner', true])]
    #[TestWith(['issuer', true])]
    #[TestWith(['accountant', false])]
    public function test_permissions_and_company_isolation(string $role, bool $canWrite): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $own = Customer::factory()->for($actor->company)->create(['name' => 'Visible']);
        $foreign = Customer::factory()->create(['name' => 'Invisible']);
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson("/api/v1/customers/$own->id")->assertOk()
            ->assertJsonMissingPath('data.company_id');
        $this->getJson("/api/v1/customers/$foreign->id")->assertNotFound();
        $this->patchJson("/api/v1/customers/$foreign->id", ['name' => 'Ataque'])->assertNotFound();
        $this->postJson('/api/v1/customers', $this->payload())->assertStatus($canWrite ? 201 : 403);
        $this->patchJson("/api/v1/customers/$own->id", ['name' => 'Cambio'])->assertStatus($canWrite ? 200 : 403);
        foreach ([false, true] as $active) {
            $this->patchJson("/api/v1/customers/$own->id", ['is_active' => $active])
                ->assertStatus($canWrite ? 200 : 403);
            $this->assertSame($canWrite ? $active : true, $own->fresh()->is_active);
        }
        $this->assertSame('Invisible', $foreign->fresh()->name);
    }

    public function test_search_filters_pagination_and_stable_order(): void
    {
        $actor = User::factory()->owner()->create();
        $own = Customer::factory()->for($actor->company)->create(['name' => 'Acme', 'doc_number' => '00123456']);
        Customer::factory()->for($actor->company)->create(['name' => 'Acme', 'is_active' => false]);
        Customer::factory()->create(['name' => 'Acme', 'doc_number' => '00123456']);
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/customers?q=acme')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/customers?q=00123456')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/customers?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
        $this->getJson('/api/v1/customers?q=missing')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customers?q=%25')->assertJsonCount(0, 'data');
        $first = $this->getJson('/api/v1/customers?per_page=1&page=1')->assertOk()->assertJsonPath('meta.total', 2)->json('data.0.id');
        $second = $this->getJson('/api/v1/customers?per_page=1&page=2')->assertOk()->json('data.0.id');
        $this->assertNotSame($first, $second);
        $this->getJson('/api/v1/customers?per_page=1&page=1')->assertJsonPath('data.0.id', $first);
        foreach (['per_page=101', 'per_page=0', 'page=0', 'is_active=bad'] as $query) {
            $this->getJson('/api/v1/customers?'.$query)->assertUnprocessable();
        }
    }

    public function test_protected_fields_and_unknown_fields(): void
    {
        $actor = User::factory()->owner()->create();
        $own = Customer::factory()->for($actor->company)->create();
        $this->actingAs($actor, 'web');
        foreach (['company_id' => $actor->company_id, 'id' => $own->id, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01'] as $key => $value) {
            $this->postJson('/api/v1/customers', $this->payload([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
            $this->patchJson("/api/v1/customers/$own->id", [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->getJson('/api/v1/customers?company_id='.$actor->company_id)->assertUnprocessable();
        $this->postJson('/api/v1/customers', $this->payload(['is_active' => false]))->assertUnprocessable();
        $this->patchJson("/api/v1/customers/$own->id", ['name' => 'Seguro', 'role' => 'owner', 'password' => 'ignored'])
            ->assertOk()->assertJsonMissingPath('data.role')->assertJsonPath('data.name', 'Seguro');
        $this->assertSame($actor->company_id, $own->fresh()->company_id);
        $this->assertTrue($own->fresh()->is_active);
    }

    #[TestWith(['ruc', '20131312955', true])]
    #[TestWith(['ruc', '20131312954', false])]
    #[TestWith(['ruc', '30131312955', false])]
    #[TestWith(['dni', '00123456', true])]
    #[TestWith(['dni', '1234567', false])]
    #[TestWith(['dni', 12345678, false])]
    #[TestWith(['other', '  ab   001  ', true])]
    #[TestWith(['other', '   ', false])]
    #[TestWith(['other', '123456789012345678901', false])]
    #[TestWith(['invalid', '1234', false])]
    public function test_document_validation(string $type, mixed $number, bool $valid): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web');
        $response = $this->postJson('/api/v1/customers', $this->payload(['doc_type' => $type, 'doc_number' => $number]));
        $response->assertStatus($valid ? 201 : 422);
        if ($valid) {
            $response->assertJsonPath('data.doc_number', $type === 'other' ? 'AB 001' : $number)
                ->assertJsonPath('data.is_active', true);
        }
    }

    public function test_duplicate_scope_self_edit_and_type_change(): void
    {
        $actor = User::factory()->issuer()->create();
        Customer::factory()->create(['doc_type' => 'dni', 'doc_number' => '00123456']);
        $this->actingAs($actor, 'web');
        $id = $this->postJson('/api/v1/customers', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('doc_number');
        $this->patchJson("/api/v1/customers/$id", $this->payload())->assertOk();
        $this->patchJson("/api/v1/customers/$id", ['doc_type' => 'ruc'])->assertUnprocessable()->assertJsonValidationErrors('doc_number');
        $other = $this->postJson('/api/v1/customers', $this->payload(['doc_type' => 'other']))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/customers/$other", ['doc_type' => 'dni'])->assertUnprocessable();
    }

    public function test_general_validation(): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web');
        $this->postJson('/api/v1/customers', $this->payload([
            'name' => str_repeat('a', 201), 'email' => 'invalid', 'address' => str_repeat('a', 301),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'address']);
    }

    public function test_database_duplicate_is_translated_even_without_request_precheck(): void
    {
        $actor = User::factory()->owner()->create();
        Customer::factory()->for($actor->company)->create(['doc_type' => 'dni', 'doc_number' => '00123456']);
        try {
            app(SaveCustomer::class)->handle($actor, $this->payload());
            $this->fail('Expected unique constraint failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('doc_number', $exception->errors());
        }
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_partial_edit_revalidates_document_after_locking_current_row(): void
    {
        $actor = User::factory()->issuer()->create();
        $stale = Customer::factory()->for($actor->company)->create(['doc_type' => 'other', 'doc_number' => 'ABC']);
        Customer::whereKey($stale->id)->update(['doc_type' => 'ruc', 'doc_number' => '20131312955']);

        try {
            app(SaveCustomer::class)->handle($actor, ['doc_number' => '00123456'], $stale);
            $this->fail('An outdated partial edit must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('doc_number', $exception->errors());
        }

        $this->assertSame('20131312955', $stale->fresh()->doc_number);
    }

    public function test_database_rejects_invalid_type_without_masking_sql_error(): void
    {
        $actor = User::factory()->owner()->create();
        $this->expectException(QueryException::class);
        app(SaveCustomer::class)->handle($actor, $this->payload(['doc_type' => 'bad']));
    }
}
