<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class UserManagementTest extends DatabaseTestCase
{
    private function validPayload(): array
    {
        return [
            'name' => 'Nuevo Emisor',
            'email' => 'nuevo.emisor@example.test',
            'password' => 'PasswordPrueba123!',
            'password_confirmation' => 'PasswordPrueba123!',
            'role' => 'issuer',
        ];
    }

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users')
            ->assertUnauthorized();
    }

    public function test_guest_cannot_create_users(): void
    {
        $this->postJson('/api/v1/users', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_owner_lists_only_own_company_users(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $owner = User::factory()
            ->for($companyA)
            ->owner()
            ->create();

        $colleague = User::factory()
            ->for($companyA)
            ->issuer()
            ->create();

        $foreignUser = User::factory()
            ->for($companyB)
            ->owner()
            ->create();

        $this->actingAs($owner, 'web');

        $response = $this->getJson(
            '/api/v1/users?company_id='.$companyB->id
        );

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => $foreignUser->id]);

        $this->assertEqualsCanonicalizing(
            [$owner->id, $colleague->id],
            array_column($response->json('data'), 'id'),
        );

        foreach ($response->json('data') as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('remember_token', $user);
        }
    }

    #[TestWith(['issuer'])]
    #[TestWith(['accountant'])]
    public function test_non_owner_cannot_list_users(string $role): void
    {
        $user = User::factory()->create([
            'role' => UserRole::from($role),
        ]);

        $this->actingAs($user, 'web');

        $this->getJson('/api/v1/users')
            ->assertForbidden();
    }

    #[TestWith(['issuer'])]
    #[TestWith(['accountant'])]
    public function test_non_owner_cannot_create_users(string $role): void
    {
        $user = User::factory()->create([
            'role' => UserRole::from($role),
        ]);

        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/users', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'nuevo.emisor@example.test',
        ]);
    }

    public function test_owner_creates_user_in_own_company(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $response = $this->postJson(
            '/api/v1/users',
            $this->validPayload(),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nuevo Emisor')
            ->assertJsonPath('data.role', 'issuer')
            ->assertJsonPath('data.is_active', true);

        $created = User::query()
            ->where('email', 'nuevo.emisor@example.test')
            ->firstOrFail();

        $this->assertSame($owner->company_id, $created->company_id);

        $this->assertTrue(
            Hash::check('PasswordPrueba123!', $created->password),
        );

        $this->assertArrayNotHasKey(
            'password',
            $response->json('data'),
        );
    }

    public function test_owner_cannot_select_another_company(): void
    {
        $owner = User::factory()->owner()->create();
        $otherCompany = Company::factory()->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', [
            ...$this->validPayload(),
            'company_id' => $otherCompany->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertDatabaseMissing('users', [
            'email' => 'nuevo.emisor@example.test',
        ]);
    }

    public function test_company_limit_includes_inactive_users(): void
    {
        $company = Company::factory()->create();

        $owner = User::factory()
            ->for($company)
            ->owner()
            ->create();

        User::factory()
            ->count(3)
            ->for($company)
            ->create();

        User::factory()
            ->for($company)
            ->inactive()
            ->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('users');

        $this->assertSame(5, $company->users()->count());
    }

    public function test_can_fill_last_available_place_only_once(): void
    {
        $company = Company::factory()->create();

        $owner = User::factory()
            ->for($company)
            ->owner()
            ->create();

        User::factory()
            ->count(3)
            ->for($company)
            ->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', $this->validPayload())
            ->assertCreated();

        $this->postJson('/api/v1/users', [
            ...$this->validPayload(),
            'email' => 'otro.usuario@example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('users');

        $this->assertSame(5, $company->users()->count());
    }

    public function test_other_company_users_do_not_consume_quota(): void
    {
        $owner = User::factory()->owner()->create();
        $otherCompany = Company::factory()->create();

        User::factory()
            ->count(5)
            ->for($otherCompany)
            ->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', $this->validPayload())
            ->assertCreated();

        $this->assertSame(2, $owner->company->users()->count());
    }

    public function test_invalid_fields_are_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', [
            'name' => '',
            'email' => 'correo-invalido',
            'password' => 'abc',
            'password_confirmation' => 'otra',
            'role' => 'administrator',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'role',
            ]);
    }

    public function test_email_is_globally_unique(): void
    {
        $existing = User::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', [
            ...$this->validPayload(),
            'email' => $existing->email,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_email_is_normalized_before_creation(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', [
            ...$this->validPayload(),
            'email' => 'NUEVO.EMISOR@EXAMPLE.TEST',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.email',
                'nuevo.emisor@example.test',
            );
    }

    public function test_client_cannot_set_account_state(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->postJson('/api/v1/users', [
            ...$this->validPayload(),
            'is_active' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertDatabaseMissing('users', [
            'email' => 'nuevo.emisor@example.test',
        ]);
    }
}
