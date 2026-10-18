<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

final class ResetPassword
{
    /**
     * @param  null  $_
     * @param  array{email: string, token: string, password: string}  $args
     */
    public function __invoke($_, array $args): bool
    {
        $status = Password::reset(
            [
                'email' => $args['email'],
                'token' => $args['token'],
                // Model casts 'password' => 'hashed', automatically hashing in callback below.
                'password' => $args['password'],
            ],
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();

                // Revoke all existing sessions/tokens of the user after password reset
                $tokenIds = $user->tokens()->where('revoked', false)->pluck('id');
                if ($tokenIds->isNotEmpty()) {
                    $user->tokens()->whereIn('id', $tokenIds)->update(['revoked' => true]);
                    Passport::refreshToken()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
                }
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Password reset link is invalid or has expired.'],
            ]);
        }

        return true;
    }
}
