<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class TreasuryPolicy
{
    public function view(User $user): bool
    {
        return $user->is_active && $user->company?->is_active === true;
    }

    public function register(User $user): bool
    {
        return $this->view($user) && in_array($user->role, [UserRole::Owner, UserRole::Issuer], true);
    }

    public function manage(User $user): bool
    {
        return $this->view($user) && $user->role === UserRole::Owner;
    }
}
