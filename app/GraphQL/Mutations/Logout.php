<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

final class Logout
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args): bool
    {
        $user = Auth::guard('api')->user();
        if (! $user) {
            return false;
        }

        $currentToken = $user->token();
        if (! $currentToken) {
            return false;
        }

        $tokenId = $currentToken instanceof Token
            ? $currentToken->id
            : ($currentToken->oauth_access_token_id ?? $currentToken->id ?? null);

        if (! $tokenId) {
            if (method_exists($currentToken, 'revoke')) {
                $currentToken->revoke();
            }

            return true;
        }

        $token = $user->tokens()->find($tokenId) ?? Passport::token()->find($tokenId);
        if ($token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        } else {
            Passport::token()->whereKey($tokenId)->update(['revoked' => true]);
        }

        Passport::refreshToken()->where('access_token_id', $tokenId)->update(['revoked' => true]);

        return true;
    }
}
