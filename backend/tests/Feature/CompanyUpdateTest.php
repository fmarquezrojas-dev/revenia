<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class CompanyUpdateTest extends DatabaseTestCase
{
    private function validPayload(): array
    {
        return [
            'legal_name' => 'Empresa de Prueba Actualizada',
            'trade_name' => 'Nombre comercial actualizado',
            'address' => 'Dirección de prueba 123',
            'email' => 'contacto@example.test',
        ];
    }

    public function test_guest_cannot_update_company(): void
    {
        $this->patchJson('/api/v1/company', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_owner_can_update_own_company(): void
    {
        $company = Company::factory()->create();
        $originalRuc = $company->ruc;

        $owner = User::factory()
            ->for($company)
            ->owner()
            ->create();

        $this->actingAs($owner, 'web');

        $this->patchJson('/api/v1/company', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.id', $company->id)
            ->assertJsonPath(
                'data.legal_name',
                'Empresa de Prueba Actualizada',
            )
            ->assertJsonPath('data.ruc', $originalRuc);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            ...$this->validPayload(),
            'ruc' => $originalRuc,
        ]);
    }

    #[TestWith(['issuer'])]
    #[TestWith(['accountant'])]
    public function test_non_owner_cannot_update_company(
        string $role,
    ): void {
        $company = Company::factory()->create();

        $user = User::factory()
            ->for($company)
            ->create(['role' => UserRole::from($role)]);

        $originalName = $company->legal_name;

        $this->actingAs($user, 'web');

        $this->patchJson('/api/v1/company', $this->validPayload())
            ->assertForbidden();

        $this->assertSame(
            $originalName,
            $company->fresh()->legal_name,
        );
    }

    public function test_invalid_data_does_not_modify_company(): void
    {
        $company = Company::factory()->create();

        $owner = User::factory()
            ->for($company)
            ->owner()
            ->create();

        $original = $company->fresh()->getAttributes();

        $this->actingAs($owner, 'web');

        $this->patchJson('/api/v1/company', [
            'legal_name' => '',
            'trade_name' => str_repeat('x', 201),
            'address' => '',
            'email' => 'correo-invalido',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'legal_name',
                'trade_name',
                'address',
                'email',
            ]);

        $this->assertSame(
            $original,
            $company->fresh()->getAttributes(),
        );
    }

    #[TestWith(['id', 'otro-identificador'])]
    #[TestWith(['ruc', '20987654326'])]
    #[TestWith(['is_active', false])]
    public function test_protected_fields_are_rejected(
        string $field,
        mixed $value,
    ): void {
        $company = Company::factory()->create();

        $owner = User::factory()
            ->for($company)
            ->owner()
            ->create();

        $original = $company->fresh()->getAttributes();

        $this->actingAs($owner, 'web');

        $this->patchJson('/api/v1/company', [
            ...$this->validPayload(),
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(
            $original,
            $company->fresh()->getAttributes(),
        );
    }

    public function test_company_id_cannot_redirect_update(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $owner = User::factory()
            ->for($companyA)
            ->owner()
            ->create();

        $originalA = $companyA->fresh()->getAttributes();
        $originalB = $companyB->fresh()->getAttributes();

        $this->actingAs($owner, 'web');

        $this->patchJson('/api/v1/company', [
            ...$this->validPayload(),
            'company_id' => $companyB->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertSame(
            $originalA,
            $companyA->fresh()->getAttributes(),
        );

        $this->assertSame(
            $originalB,
            $companyB->fresh()->getAttributes(),
        );
    }

    public function test_trade_name_can_be_cleared(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->patchJson('/api/v1/company', [
            ...$this->validPayload(),
            'trade_name' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.trade_name', null);

        $this->assertDatabaseHas('companies', [
            'id' => $owner->company_id,
            'trade_name' => null,
        ]);
    }

    public function test_policy_denies_update_of_another_company(): void
    {
        $owner = User::factory()->owner()->create();
        $otherCompany = Company::factory()->create();

        $decision = Gate::forUser($owner)->inspect(
            'update',
            $otherCompany,
        );

        $this->assertTrue($decision->denied());
        $this->assertSame(404, $decision->status());
    }
}
