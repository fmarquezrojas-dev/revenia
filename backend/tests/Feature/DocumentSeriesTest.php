<?php

namespace Tests\Feature;

use App\Actions\Series\SaveDocumentSeries;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class DocumentSeriesTest extends DatabaseTestCase
{
    public function test_guest_cannot_access_administration(): void
    {
        $id = DocumentSeries::factory()->create()->id;
        $this->getJson('/api/v1/series')->assertUnauthorized();
        $this->getJson("/api/v1/series/$id")->assertUnauthorized();
        $this->postJson('/api/v1/series', ['doc_type' => '01', 'code' => 'F001'])->assertUnauthorized();
        $this->patchJson("/api/v1/series/$id", ['is_active' => false])->assertUnauthorized();
    }

    #[TestWith(['owner', true])]
    #[TestWith(['issuer', false])]
    #[TestWith(['accountant', false])]
    public function test_roles_isolation_and_state_changes(string $role, bool $allowed): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $own = DocumentSeries::factory()->for($actor->company)->create(['code' => 'F999', 'current_number' => 7]);
        $foreign = DocumentSeries::factory()->create();
        $this->actingAs($actor, 'web');
        $list = $this->getJson('/api/v1/series')->assertStatus($allowed ? 200 : 403);
        if ($allowed) {
            $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        }
        $this->getJson("/api/v1/series/$own->id")->assertStatus($allowed ? 200 : 403)->assertJsonMissingPath('data.company_id');
        $this->getJson("/api/v1/series/$foreign->id")->assertNotFound();
        $this->patchJson("/api/v1/series/$foreign->id", ['is_active' => false])->assertNotFound();
        $this->getJson('/api/v1/series/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->postJson('/api/v1/series', ['doc_type' => '01', 'code' => 'F001'])->assertStatus($allowed ? 201 : 403);
        foreach ([false, true] as $active) {
            $this->patchJson("/api/v1/series/$own->id", ['is_active' => $active])->assertStatus($allowed ? 200 : 403);
            $this->assertSame($allowed ? $active : true, $own->fresh()->is_active);
            $this->assertSame(7, $own->fresh()->current_number);
        }
        $this->assertTrue($foreign->fresh()->is_active);
        $this->deleteJson("/api/v1/series/$own->id")->assertStatus(405);
        $this->postJson("/api/v1/series/$own->id/reserve")->assertNotFound();
    }

    #[TestWith(['01', ' f001 ', true, 'F001'])]
    #[TestWith(['03', 'b001', true, 'B001'])]
    #[TestWith(['07', 'fc01', true, 'FC01'])]
    #[TestWith(['07', 'bc01', true, 'BC01'])]
    #[TestWith(['01', 'F0AZ', true, 'F0AZ'])]
    #[TestWith(['01', 'B001', false, ''])]
    #[TestWith(['03', 'F001', false, ''])]
    #[TestWith(['07', 'A001', false, ''])]
    #[TestWith(['99', 'F001', false, ''])]
    #[TestWith(['01', 'F01', false, ''])]
    #[TestWith(['01', 'F0001', false, ''])]
    #[TestWith(['01', 'F-01', false, ''])]
    #[TestWith(['01', 'F 01', false, ''])]
    #[TestWith(['01', 'Fé01', false, ''])]
    #[TestWith(['01', 1000, false, ''])]
    public function test_normalization_and_prefixes(string $type, mixed $code, bool $valid, string $normalized): void
    {
        $this->actingAs(User::factory()->owner()->create(), 'web');
        $response = $this->postJson('/api/v1/series', ['doc_type' => $type, 'code' => $code])->assertStatus($valid ? 201 : 422);
        if ($valid) {
            $response->assertJsonPath('data.code', $normalized)->assertJsonPath('data.current_number', 0)->assertJsonPath('data.is_active', true);
        }
    }

    public function test_uniqueness_includes_company_and_type_even_when_inactive(): void
    {
        DocumentSeries::factory()->create(['code' => 'F001']);
        $actor = User::factory()->owner()->create();
        $this->actingAs($actor, 'web');
        $id = $this->postJson('/api/v1/series', ['doc_type' => '01', 'code' => 'F001'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/series/$id", ['is_active' => false])->assertOk();
        $this->postJson('/api/v1/series', ['doc_type' => '01', 'code' => ' f001 '])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/series', ['doc_type' => '07', 'code' => 'F001'])->assertCreated();
        try {
            app(SaveDocumentSeries::class)->handle($actor, ['doc_type' => '01', 'code' => 'F001']);
            $this->fail('Expected duplicate conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
    }

    public function test_immutable_and_server_fields_are_rejected(): void
    {
        $actor = User::factory()->owner()->create();
        $own = DocumentSeries::factory()->for($actor->company)->create();
        $this->actingAs($actor, 'web');
        foreach (['id' => $own->id, 'company_id' => $actor->company_id, 'current_number' => 1, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01'] as $key => $value) {
            $this->postJson('/api/v1/series', ['doc_type' => '01', 'code' => 'F001', $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
            $this->patchJson("/api/v1/series/$own->id", ['is_active' => false, $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        foreach (['doc_type' => '07', 'code' => 'F001', 'company_id' => null, 'current_number' => null] as $key => $value) {
            $this->patchJson("/api/v1/series/$own->id", ['is_active' => false, $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->postJson('/api/v1/series', ['doc_type' => '01', 'code' => 'F001', 'is_active' => true])->assertUnprocessable();
        $this->patchJson("/api/v1/series/$own->id", [])->assertUnprocessable();
        $this->getJson('/api/v1/series?company_id='.$actor->company_id)->assertUnprocessable();
        $this->getJson("/api/v1/series/$own->id?company_id=$actor->company_id")->assertUnprocessable();
        $this->assertSame(0, $own->fresh()->current_number);
        $this->assertTrue($own->fresh()->is_active);
    }

    public function test_filters_stable_pagination_and_limits(): void
    {
        $actor = User::factory()->owner()->create();
        DocumentSeries::factory()->for($actor->company)->create(['code' => 'F001']);
        $inactive = DocumentSeries::factory()->for($actor->company)->create(['doc_type' => '03', 'code' => 'B001', 'is_active' => false]);
        DocumentSeries::factory()->create(['doc_type' => '03', 'code' => 'B001', 'is_active' => false]);
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/series?doc_type=03&is_active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/v1/series?doc_type=07')->assertJsonCount(0, 'data');
        $first = $this->getJson('/api/v1/series?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->json('data.0.id');
        $second = $this->getJson('/api/v1/series?per_page=1&page=2')->assertOk()->json('data.0.id');
        $this->assertNotSame($first, $second);
        $this->getJson('/api/v1/series?per_page=1')->assertJsonPath('data.0.id', $first);
        foreach (['per_page=101', 'per_page=0', 'page=0', 'doc_type=99', 'is_active=bad'] as $query) {
            $this->getJson('/api/v1/series?'.$query)->assertUnprocessable();
        }
    }

    #[TestWith(['doc_type', '99'])]
    #[TestWith(['code', 'B001'])]
    #[TestWith(['code', 'f001'])]
    #[TestWith(['code', 'Fé01'])]
    #[TestWith(['current_number', -1])]
    #[TestWith(['current_number', 100000000])]
    public function test_database_constraints(string $field, mixed $value): void
    {
        $this->expectException(QueryException::class);
        DocumentSeries::factory()->create([$field => $value]);
    }

    public function test_other_sql_errors_are_not_translated_to_duplicates(): void
    {
        $actor = User::factory()->owner()->create();
        $this->expectException(QueryException::class);
        app(SaveDocumentSeries::class)->handle($actor, ['doc_type' => '01', 'code' => 'B001']);
    }
}
