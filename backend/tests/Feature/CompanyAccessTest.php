<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class CompanyAccessTest extends DatabaseTestCase
{
    public function test_guest_cannot_read_company(): void
    {
        $this->getJson('/api/v1/company')
            ->assertUnauthorized();
    }

    public function test_owner_can_read_own_company(): void
    {
        $company = Company::factory()->create();

        $owner = User::factory()
            ->for($company)
            ->owner()
            ->create();

        $this->actingAs($owner, 'web');

        $this->getJson('/api/v1/company')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $company->id,
                    'ruc' => $company->ruc,
                    'legal_name' => $company->legal_name,
                    'trade_name' => $company->trade_name,
                    'address' => $company->address,
                    'email' => $company->email,
                    'is_active' => true,
                ],
            ]);
    }

    #[TestWith(['issuer'])]
    #[TestWith(['accountant'])]
    public function test_non_owner_cannot_read_company(
        string $role,
    ): void {
        $user = User::factory()->create([
            'role' => UserRole::from($role),
        ]);

        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/company')
            ->assertForbidden();
    }

    public function test_query_parameter_cannot_switch_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $owner = User::factory()
            ->for($companyA)
            ->owner()
            ->create();

        $this->actingAs($owner, 'web');

        $this->getJson(
            '/api/v1/company?company_id='.$companyB->id
        )
            ->assertOk()
            ->assertJsonPath('data.id', $companyA->id)
            ->assertJsonMissing([
                'id' => $companyB->id,
            ]);
    }

    public function test_policy_denies_owner_of_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $owner = User::factory()
            ->for($companyA)
            ->owner()
            ->create();

        $decision = Gate::forUser($owner)->inspect(
            'view',
            $companyB,
        );

        $this->assertTrue($decision->denied());
        $this->assertSame(404, $decision->status());
    }
}
