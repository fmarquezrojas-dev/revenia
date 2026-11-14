<?php

namespace Tests\Feature;

use App\Actions\Products\SaveProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class ProductTest extends DatabaseTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'code' => '001-ABC', 'description' => 'Producto de prueba', 'unit_code' => 'NIU',
            'currency' => 'PEN', 'unit_price_cents' => 1050, 'tax_type' => '10',
        ], $overrides);
    }

    public function test_guest_cannot_use_any_endpoint(): void
    {
        $id = Product::factory()->create()->id;
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->getJson("/api/v1/products/$id")->assertUnauthorized();
        $this->postJson('/api/v1/products', $this->payload())->assertUnauthorized();
        $this->patchJson("/api/v1/products/$id", ['description' => 'Otro'])->assertUnauthorized();
    }

    #[TestWith(['owner', true])]
    #[TestWith(['issuer', true])]
    #[TestWith(['accountant', false])]
    public function test_permissions_and_company_isolation(string $role, bool $canWrite): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $own = Product::factory()->for($actor->company)->create(['description' => 'Visible']);
        $foreign = Product::factory()->create(['description' => 'Invisible']);
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonMissing(['id' => $foreign->id]);
        $this->getJson("/api/v1/products/$own->id")->assertOk()
            ->assertJsonMissingPath('data.company_id');
        $this->getJson("/api/v1/products/$foreign->id")->assertNotFound();
        $this->patchJson("/api/v1/products/$foreign->id", ['description' => 'Ataque'])->assertNotFound();
        $this->patchJson("/api/v1/products/$foreign->id", ['is_active' => false])->assertNotFound();
        $this->getJson('/api/v1/products/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->postJson('/api/v1/products', $this->payload())->assertStatus($canWrite ? 201 : 403);
        $this->patchJson("/api/v1/products/$own->id", ['description' => 'Cambio'])->assertStatus($canWrite ? 200 : 403);
        foreach ([false, true] as $active) {
            $this->patchJson("/api/v1/products/$own->id", ['is_active' => $active])
                ->assertStatus($canWrite ? 200 : 403);
            $this->assertSame($canWrite ? $active : true, $own->fresh()->is_active);
        }
        $this->assertSame('Invisible', $foreign->fresh()->description);
    }

    public function test_search_filters_pagination_and_stable_order(): void
    {
        $actor = User::factory()->owner()->create();
        $own = Product::factory()->for($actor->company)->create(['description' => 'Acme', 'code' => '00123456']);
        Product::factory()->for($actor->company)->create(['description' => 'Acme', 'is_active' => false]);
        Product::factory()->create(['description' => 'Acme', 'code' => '00123456']);
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/products?q=acme')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/products?q=00123456')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/products?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
        $this->getJson('/api/v1/products?q=missing')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?q=%25')->assertJsonCount(0, 'data');
        $first = $this->getJson('/api/v1/products?per_page=1&page=1')->assertOk()->assertJsonPath('meta.total', 2)->json('data.0.id');
        $second = $this->getJson('/api/v1/products?per_page=1&page=2')->assertOk()->json('data.0.id');
        $this->assertNotSame($first, $second);
        $this->getJson('/api/v1/products?per_page=1&page=1')->assertJsonPath('data.0.id', $first);
        foreach (['per_page=101', 'per_page=0', 'page=0', 'is_active=bad'] as $query) {
            $this->getJson('/api/v1/products?'.$query)->assertUnprocessable();
        }
    }

    public function test_protected_fields_and_unknown_fields(): void
    {
        $actor = User::factory()->owner()->create();
        $own = Product::factory()->for($actor->company)->create();
        $this->actingAs($actor, 'web');
        foreach (['company_id' => $actor->company_id, 'id' => $own->id, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01'] as $key => $value) {
            $this->postJson('/api/v1/products', $this->payload([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
            $this->patchJson("/api/v1/products/$own->id", [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->getJson('/api/v1/products?company_id='.$actor->company_id)->assertUnprocessable();
        $this->postJson('/api/v1/products', $this->payload(['is_active' => false]))->assertUnprocessable();
        $this->patchJson("/api/v1/products/$own->id", ['description' => 'Seguro', 'role' => 'owner', 'password' => 'ignored'])
            ->assertOk()->assertJsonMissingPath('data.role')->assertJsonPath('data.description', 'Seguro');
        $this->assertSame($actor->company_id, $own->fresh()->company_id);
        $this->assertTrue($own->fresh()->is_active);
    }

    #[TestWith(['currency', 'EUR'])]
    #[TestWith(['unit_code', 'KG'])]
    #[TestWith(['tax_type', '40'])]
    #[TestWith(['tax_type', 10])]
    #[TestWith(['unit_price_cents', -1])]
    #[TestWith(['unit_price_cents', 1.5])]
    #[TestWith(['unit_price_cents', '1050'])]
    #[TestWith(['unit_price_cents', '1e3'])]
    #[TestWith(['unit_price_cents', true])]
    #[TestWith(['unit_price_cents', 9007199254740992])]
    #[TestWith(['code', ''])]
    public function test_invalid_values(string $field, mixed $value): void
    {
        $actor = User::factory()->owner()->create();
        $own = Product::factory()->for($actor->company)->create();
        $this->actingAs($actor, 'web');
        $this->postJson('/api/v1/products', $this->payload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson("/api/v1/products/$own->id", [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[TestWith([0])]
    #[TestWith([35])]
    #[TestWith([9007199254740991])]
    public function test_valid_prices_are_returned_as_integers(int $price): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web');
        $this->postJson('/api/v1/products', $this->payload(['unit_price_cents' => $price]))
            ->assertCreated()->assertJsonPath('data.unit_price_cents', $price);
    }

    public function test_normalization_unique_scope_and_self_edit(): void
    {
        $actor = User::factory()->issuer()->create();
        Product::factory()->create(['code' => '001-ABC/+']);
        $this->actingAs($actor, 'web');
        $payload = $this->payload(['code' => '  001-abc/+  ']);
        $id = $this->postJson('/api/v1/products', $payload)->assertCreated()
            ->assertJsonPath('data.code', '001-ABC/+')->assertJsonPath('data.is_active', true)->json('data.id');
        $this->postJson('/api/v1/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson("/api/v1/products/$id", $payload)->assertOk();
        $other = Product::factory()->for($actor->company)->create();
        $this->patchJson("/api/v1/products/$other->id", ['code' => '001-abc/+'])->assertUnprocessable();
        $this->deleteJson("/api/v1/products/$id")->assertStatus(405);
    }

    public function test_currency_tax_filters_and_catalog_values(): void
    {
        $actor = User::factory()->owner()->create();
        $this->actingAs($actor, 'web');
        foreach (['10', '20', '30'] as $tax) {
            $this->postJson('/api/v1/products', $this->payload(['code' => $tax, 'currency' => 'USD', 'unit_code' => 'ZZ', 'tax_type' => $tax]))->assertCreated();
        }
        Product::factory()->create(['currency' => 'USD', 'tax_type' => '20']);
        $this->getJson('/api/v1/products?currency=USD&tax_type=20&is_active=1&q=Producto')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?currency=PEN')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?currency=EUR')->assertUnprocessable();
        $this->getJson('/api/v1/products?tax_type=99')->assertUnprocessable();
        $this->postJson('/api/v1/products', $this->payload(['code' => str_repeat('A', 31), 'description' => str_repeat('A', 251)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'description']);
    }

    public function test_database_duplicate_is_translated_without_prevalidation(): void
    {
        $actor = User::factory()->owner()->create();
        Product::factory()->for($actor->company)->create(['code' => '001-ABC']);
        try {
            app(SaveProduct::class)->handle($actor, $this->payload());
            $this->fail('Expected unique constraint failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertDatabaseCount('products', 1);
    }

    #[TestWith(['currency', 'EUR'])]
    #[TestWith(['unit_code', 'KG'])]
    #[TestWith(['tax_type', '99'])]
    #[TestWith(['unit_price_cents', -1])]
    #[TestWith(['unit_price_cents', 9007199254740992])]
    public function test_database_constraints_do_not_mask_other_errors(string $field, mixed $value): void
    {
        $actor = User::factory()->owner()->create();
        $this->expectException(QueryException::class);
        app(SaveProduct::class)->handle($actor, $this->payload([$field => $value]));
    }
}
