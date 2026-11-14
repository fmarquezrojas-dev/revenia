<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\DatabaseTestCase;

class UserUpdateTest extends DatabaseTestCase
{
    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'Usuario Actualizado',
            'role' => 'issuer',
            'is_active' => true,
        ], $changes);
    }

    private function endpoint(User $user): string
    {
        return '/api/v1/users/'.$user->id;
    }

    public function test_guest_cannot_update_user(): void
    {
        $target = User::factory()->create();

        $this->patchJson(
            $this->endpoint($target),
            $this->payload(),
        )->assertUnauthorized();
    }

    public function test_owner_can_update_colleague_name_and_role(): void
    {
        $owner = User::factory()->owner()->create();

        $target = User::factory()
            ->for($owner->company)
            ->issuer()
            ->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($target),
            $this->payload(['role' => 'accountant']),
        )
            ->assertOk()
            ->assertJsonPath('data.name', 'Usuario Actualizado')
            ->assertJsonPath('data.role', 'accountant');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Usuario Actualizado',
            'role' => 'accountant',
            'company_id' => $owner->company_id,
        ]);
    }

    #[TestWith(['issuer'])]
    #[TestWith(['accountant'])]
    public function test_non_owner_cannot_update_users(
        string $role,
    ): void {
        $actor = User::factory()->create([
            'role' => UserRole::from($role),
        ]);

        $target = User::factory()
            ->for($actor->company)
            ->create();

        $original = $target->fresh()->getAttributes();

        $this->actingAs($actor, 'web');

        $this->patchJson(
            $this->endpoint($target),
            $this->payload(),
        )->assertForbidden();

        $this->assertSame(
            $original,
            $target->fresh()->getAttributes(),
        );
    }

    public function test_owner_cannot_update_another_company_user(): void
    {
        $owner = User::factory()->owner()->create();
        $foreignUser = User::factory()->create();

        $original = $foreignUser->fresh()->getAttributes();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($foreignUser),
            $this->payload(),
        )->assertNotFound();

        $this->assertSame(
            $original,
            $foreignUser->fresh()->getAttributes(),
        );
    }

    public function test_owner_can_deactivate_and_reactivate_colleague(): void
    {
        $owner = User::factory()->owner()->create();

        $target = User::factory()
            ->for($owner->company)
            ->issuer()
            ->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($target),
            $this->payload(['is_active' => false]),
        )
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($target->fresh()->is_active);

        $this->patchJson(
            $this->endpoint($target),
            $this->payload(['is_active' => true]),
        )
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_last_active_owner_cannot_be_deactivated(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($owner),
            $this->payload([
                'role' => 'owner',
                'is_active' => false,
            ]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_last_active_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($owner),
            $this->payload(['role' => 'accountant']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(
            UserRole::Owner,
            $owner->fresh()->role,
        );
    }

    public function test_inactive_owner_does_not_satisfy_owner_requirement(): void
    {
        $owner = User::factory()->owner()->create();

        User::factory()
            ->for($owner->company)
            ->owner()
            ->inactive()
            ->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($owner),
            $this->payload(['role' => 'issuer']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_owner_can_demote_self_when_another_active_owner_exists(): void
    {
        $owner = User::factory()->owner()->create([
            'password' => 'PasswordPrueba123!',
        ]);

        $otherOwner = User::factory()
            ->for($owner->company)
            ->owner()
            ->create();

        $this->postJson('/login', [
            'email' => $owner->email,
            'password' => 'PasswordPrueba123!',
        ])->assertNoContent();

        $this->patchJson(
            $this->endpoint($owner),
            $this->payload(['role' => 'accountant']),
        )->assertOk();

        $this->assertSame(
            UserRole::Accountant,
            $owner->fresh()->role,
        );

        $this->assertSame(
            UserRole::Owner,
            $otherOwner->fresh()->role,
        );

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/users')
            ->assertForbidden();
    }

    public function test_owner_can_deactivate_self_when_another_active_owner_exists(): void
    {
        $owner = User::factory()->owner()->create();

        User::factory()
            ->for($owner->company)
            ->owner()
            ->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($owner),
            $this->payload([
                'role' => 'owner',
                'is_active' => false,
            ]),
        )->assertOk();

        $this->assertFalse($owner->fresh()->is_active);
    }

    #[TestWith(['company_id', '00000000-0000-4000-8000-000000000001'])]
    #[TestWith(['email', 'otro@example.test'])]
    #[TestWith(['password', 'PasswordNueva123!'])]
    public function test_protected_fields_cannot_be_changed(
        string $field,
        mixed $value,
    ): void {
        $owner = User::factory()->owner()->create();

        $target = User::factory()
            ->for($owner->company)
            ->issuer()
            ->create();

        $original = $target->fresh()->getAttributes();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            $this->endpoint($target),
            $this->payload([$field => $value]),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(
            $original,
            $target->fresh()->getAttributes(),
        );
    }

    public function test_invalid_values_are_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $target = User::factory()
            ->for($owner->company)
            ->create();

        $this->actingAs($owner, 'web');

        $this->patchJson($this->endpoint($target), [
            'name' => '',
            'role' => 'administrator',
            'is_active' => 'valor-invalido',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'role',
                'is_active',
            ]);
    }

    public function test_invalid_uuid_returns_not_found(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner, 'web');

        $this->patchJson(
            '/api/v1/users/no-es-un-uuid',
            $this->payload(),
        )->assertNotFound();
    }
}
