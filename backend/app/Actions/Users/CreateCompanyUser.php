<?php

namespace App\Actions\Users;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateCompanyUser
{
    public function handle(User $actor, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $data): User {
                $company = Company::query()
                    ->whereKey($actor->company_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $currentActor = $company->users()
                    ->whereKey($actor->id)
                    ->firstOrFail();

                $currentActor->setRelation('company', $company);

                Gate::forUser($currentActor)->authorize(
                    'create',
                    User::class,
                );

                $limit = (int) config('revenia.users_per_company');

                if ($company->users()->count() >= $limit) {
                    throw ValidationException::withMessages([
                        'users' => [
                            "La empresa alcanzó el límite de {$limit} usuarios.",
                        ],
                    ]);
                }

                return $company->users()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => $data['role'],
                    'is_active' => true,
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains(
                $exception->getMessage(),
                'users_email_unique',
            )) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => ['El correo ya está registrado.'],
            ]);
        }
    }
}
