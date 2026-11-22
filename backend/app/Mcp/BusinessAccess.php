<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\PersonalAccessToken;

final class BusinessAccess
{
    public const SCOPE = 'revenia:business:read';

    public const NAME = 'revenia-codex-local';

    public static function tokens(User $user): MorphMany
    {
        return $user->morphMany(PersonalAccessToken::class, 'tokenable')->where('name', self::NAME);
    }

    public static function requireCredential(): string
    {
        $secret = getenv('REVENIA_MCP_TOKEN');
        if (! is_string($secret) || ! preg_match('/\Arv_mcp_[a-f0-9]{64}\z/', $secret)) {
            throw new AuthorizationException;
        }

        return $secret;
    }

    public static function user(string $secret): User
    {
        $token = PersonalAccessToken::findToken($secret);
        if (! $token || $token->name !== self::NAME || $token->tokenable_type !== (new User)->getMorphClass()
            || ! $token->expires_at?->isFuture()) {
            throw new AuthorizationException;
        }

        $user = User::with('company')->find($token->tokenable_id);
        if (! $user || $token->abilities !== [self::SCOPE, 'company:'.$user->company_id]) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
