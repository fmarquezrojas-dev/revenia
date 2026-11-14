<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->company?->is_active === true;
    }

    public function view(User $user, Product $product): Response
    {
        if ($user->company_id !== $product->company_id) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user) ? Response::allow() : Response::deny();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user)
            && in_array($user->role, [UserRole::Owner, UserRole::Issuer], true);
    }

    public function update(User $user, Product $product): Response
    {
        if ($user->company_id !== $product->company_id) {
            return Response::denyAsNotFound();
        }

        return $this->create($user)
            ? Response::allow()
            : Response::deny('Tu rol solo permite consultar productos.');
    }
}
