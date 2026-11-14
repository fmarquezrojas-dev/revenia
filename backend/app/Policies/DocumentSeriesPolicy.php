<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DocumentSeries;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner && $user->is_active && $user->company?->is_active === true;
    }

    public function view(User $user, DocumentSeries $series): Response
    {
        if ($user->company_id !== $series->company_id) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user) ? Response::allow() : Response::deny();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DocumentSeries $series): Response
    {
        if ($user->company_id !== $series->company_id) {
            return Response::denyAsNotFound();
        }

        return $this->create($user)
            ? Response::allow()
            : Response::deny('Solo el propietario puede administrar series.');
    }
}
