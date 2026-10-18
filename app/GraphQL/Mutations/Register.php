<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\Services\PassportTokenIssuer;

final class Register
{
    /**
     * @param  null  $_
     * @param  array{name: string, email: string, password: string, remember_me?: bool}  $args
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function __invoke($_, array $args): array
    {
        $user = User::create([
            'name' => $args['name'],
            // If omitted, User::booted() auto-generates one from the name.
            'username' => $args['username'] ?? null,
            'email' => $args['email'],
            // Model has 'password' => 'hashed' cast so it hashes automatically without Hash::make().
            'password' => $args['password'],
        ]);

        // Do not block login if email is unverified (see notes in graphql/auth/auth.graphql) —
        // send verification notification without enforcing verification before account usage.
        $user->sendEmailVerificationNotification();

        // Log in immediately after registration and return tokens like login() so the frontend
        // does not need to dispatch a separate mutation.
        return app(PassportTokenIssuer::class)->issue([
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'username' => $user->email,
            'password' => $args['password'],
        ], rememberMe: $args['remember_me'] ?? false);
    }
}
