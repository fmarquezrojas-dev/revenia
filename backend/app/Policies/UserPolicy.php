<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->authorizeOwner($user);
    }

    public function create(User $user): Response
    {
        return $this->authorizeOwner($user);
    }

    public function update(User $actor, User $target): Response
    {
        if ($actor->company_id !== $target->company_id) {
            return Response::denyAsNotFound();
        }

        return $this->authorizeOwner($actor);
    }

    private function authorizeOwner(User $user): Response
    {
        if (
            ! $user->is_active
            || $user->company?->is_active !== true
        ) {
            return Response::deny(
                'La cuenta o la empresa no están activas.'
            );
        }

        if ($user->role !== UserRole::Owner) {
            return Response::deny(
                'Solo el dueño puede administrar usuarios.'
            );
        }

        return Response::allow();
    }
}
