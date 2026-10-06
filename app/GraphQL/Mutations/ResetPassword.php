<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

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
                // Model có cast 'password' => 'hashed' nên tự băm trong callback dưới.
                'password' => $args['password'],
            ],
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Link đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.'],
            ]);
        }

        return true;
    }
}
