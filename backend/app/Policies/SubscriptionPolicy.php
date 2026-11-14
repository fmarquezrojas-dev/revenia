<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class SubscriptionPolicy
{
    public function view(User $user): bool
    {
        return $user->is_active && $user->company?->is_active === true;
    }

    public function manage(User $user): bool
    {
        return $this->view($user) && $user->role === UserRole::Owner;
    }
}
