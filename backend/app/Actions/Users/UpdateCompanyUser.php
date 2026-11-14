<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateCompanyUser
{
    public function handle(
        User $actor,
        User $target,
        array $data,
    ): User {
        return DB::transaction(function () use (
            $actor,
            $target,
            $data,
        ): User {
            $company = Company::query()
                ->whereKey($actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentActor = $company->users()
                ->whereKey($actor->id)
                ->firstOrFail();

            $currentActor->setRelation('company', $company);

            $currentTarget = $company->users()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($currentActor)->authorize(
                'update',
                $currentTarget,
            );

            $nextRole = UserRole::from($data['role']);
            $nextActive = (bool) $data['is_active'];

            $isActiveOwner = $currentTarget->is_active
                && $currentTarget->role === UserRole::Owner;

            $willRemainActiveOwner = $nextActive
                && $nextRole === UserRole::Owner;

            if ($isActiveOwner && ! $willRemainActiveOwner) {
                $activeOwners = $company->users()
                    ->where('role', UserRole::Owner->value)
                    ->where('is_active', true)
                    ->count();

                if ($activeOwners <= 1) {
                    $field = $nextRole !== UserRole::Owner
                        ? 'role'
                        : 'is_active';

                    throw ValidationException::withMessages([
                        $field => [
                            'La empresa debe conservar al menos un dueño activo.',
                        ],
                    ]);
                }
            }

            $currentTarget->fill([
                'name' => $data['name'],
                'role' => $nextRole,
                'is_active' => $nextActive,
            ]);

            $currentTarget->save();

            return $currentTarget->refresh();
        }, 3);
    }
}
