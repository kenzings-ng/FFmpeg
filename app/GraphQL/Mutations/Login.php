<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\PassportTokenIssuer;

final class Login
{
    /**
     * @param  null  $_
     * @param  array{email: string, password: string, remember_me?: bool}  $args
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function __invoke($_, array $args): array
    {
        return app(PassportTokenIssuer::class)->issue([
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'username' => $args['email'],
            'password' => $args['password'],
        ], rememberMe: $args['remember_me'] ?? false);
    }
}
