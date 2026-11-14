<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->company?->is_active === true;
    }

    public function view(User $user, Document $document): Response
    {
        return $user->company_id !== $document->company_id ? Response::denyAsNotFound() : ($this->viewAny($user) ? Response::allow() : Response::deny());
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && in_array($user->role, [UserRole::Owner, UserRole::Issuer], true);
    }

    public function update(User $user, Document $document): Response
    {
        return $user->company_id !== $document->company_id ? Response::denyAsNotFound() : ($this->create($user) ? Response::allow() : Response::deny());
    }

    public function delete(User $user, Document $document): Response
    {
        return $this->update($user, $document);
    }

    public function downloadPdf(User $user, Document $document): Response
    {
        return $this->view($user, $document);
    }

    public function sendEmail(User $user, Document $document): Response
    {
        return $this->update($user, $document);
    }

    public function regeneratePdf(User $user, Document $document): Response
    {
        if ($user->company_id !== $document->company_id) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user) && $user->role === UserRole::Owner ? Response::allow() : Response::deny();
    }

    public function createPayment(User $user, Document $document): Response
    {
        return $this->update($user, $document);
    }

    public function reconcilePayment(User $user, Document $document): Response
    {
        return $this->regeneratePdf($user, $document);
    }
}
