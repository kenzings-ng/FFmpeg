<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Facades\Password;

final class ForgotPassword
{
    /**
     * @param  null  $_
     * @param  array{email: string}  $args
     */
    public function __invoke($_, array $args): bool
    {
        Password::sendResetLink(['email' => $args['email']]);

        // Always return true whether the email exists in the system or not — returning
        // different responses would allow account enumeration.
        return true;
    }
}
