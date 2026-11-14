<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;

class AuthenticatedSessionController extends Controller
{
    public function store(Request $request): Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:200'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $authenticated = $this->sessionGuard()->attemptWhen(
            [
                'email' => $credentials['email'],
                'password' => $credentials['password'],
                'is_active' => true,
            ],
            fn (User $user): bool => $user->company?->is_active === true,
            false,
        );

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => [
                    'No se pudo iniciar sesión con los datos proporcionados.',
                ],
            ]);
        }

        $request->session()->regenerate();

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $this->sessionGuard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function sessionGuard(): SessionGuard
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException(
                'El guard web debe utilizar autenticación por sesión.'
            );
        }

        return $guard;
    }
}
