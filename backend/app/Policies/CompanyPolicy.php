<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CompanyPolicy
{
    public function view(User $user, Company $company): Response
    {
        return $this->authorizeOwner($user, $company);
    }

    public function update(User $user, Company $company): Response
    {
        return $this->authorizeOwner($user, $company);
    }

    private function authorizeOwner(
        User $user,
        Company $company,
    ): Response {
        if ($user->company_id !== $company->id) {
            return Response::denyAsNotFound();
        }

        if (! $user->is_active || ! $company->is_active) {
            return Response::deny(
                'La cuenta o la empresa no están activas.'
            );
        }

        if ($user->role !== UserRole::Owner) {
            return Response::deny(
                'Solo el dueño puede administrar los datos de la empresa.'
            );
        }

        return Response::allow();
    }
}
