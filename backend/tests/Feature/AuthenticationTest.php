<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Se ejecuta antes de que RefreshDatabase prepare las tablas.
     */
    protected function beforeRefreshingDatabase(): void
    {
        if (
            ! app()->environment('testing')
            || config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'facturly_testing'
        ) {
            throw new \RuntimeException(sprintf(
                'Configuración incorrecta: entorno=%s; conexión=%s; base=%s. '
                .'Se requiere testing / pgsql / facturly_testing.',
                app()->environment(),
                config('database.default'),
                DB::connection()->getDatabaseName(),
            ));
        }

        $connection = DB::selectOne(
            'SELECT current_database() AS database_name, current_user AS username'
        );

        if (
            $connection->database_name !== 'facturly_testing'
            || $connection->username !== 'facturly_test'
        ) {
            throw new \RuntimeException(
                'La conexión real no corresponde al entorno de pruebas.'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
        ]);
    }

    public function test_guest_cannot_read_profile(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_active_user_can_login_and_read_profile(): void
    {
        $user = User::factory()->owner()->create([
            'password' => 'PasswordDePrueba123!',
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'PasswordDePrueba123!',
        ])->assertNoContent();

        $this->assertAuthenticatedAs($user, 'web');

        $response = $this->getJson('/api/v1/me');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', UserRole::Owner->value)
            ->assertJsonPath('data.company.id', $user->company_id);

        $data = $response->json('data');

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
    }

    public function test_wrong_password_does_not_authenticate(): void
    {
        $user = User::factory()->create([
            'password' => 'PasswordCorrecta123!',
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'PasswordIncorrecta123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create([
            'password' => 'PasswordDePrueba123!',
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'PasswordDePrueba123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_user_of_inactive_company_cannot_login(): void
    {
        $company = Company::factory()->inactive()->create();

        $user = User::factory()->for($company)->create([
            'password' => 'PasswordDePrueba123!',
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'PasswordDePrueba123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_profile_rejects_user_deactivated_after_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertGuest('web');
    }

    public function test_profile_rejects_company_deactivated_after_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        $user->company->update(['is_active' => false]);

        $this->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertGuest('web');
    }

    public function test_profile_uses_authenticated_company_not_query_parameter(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $user = User::factory()
            ->for($companyA)
            ->accountant()
            ->create();

        $this->actingAs($user, 'web');

        $this->getJson(
            '/api/v1/me?company_id='.$companyB->id
        )
            ->assertOk()
            ->assertJsonPath('data.company.id', $companyA->id)
            ->assertJsonPath('data.role', UserRole::Accountant->value);
    }

    public function test_logout_removes_authenticated_session(): void
    {
        $user = User::factory()->create([
            'password' => 'PasswordDePrueba123!',
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'PasswordDePrueba123!',
        ])->assertNoContent();

        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/logout')
            ->assertNoContent();

        $this->assertGuest('web');

        // La siguiente solicitud debe resolver sus guards nuevamente,
        // sin reutilizar el usuario almacenado en memoria por Sanctum.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me')
            ->assertUnauthorized();
    }
}
