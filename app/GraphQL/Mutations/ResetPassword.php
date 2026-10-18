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
                // Model có cast 'password' => 'hashed' nên tự băm trong callback dưới.
                'password' => $args['password'],
            ],
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();

                // Thu hồi toàn bộ session/token hiện có của user sau khi đặt lại mật khẩu
                $tokenIds = $user->tokens()->where('revoked', false)->pluck('id');
                if ($tokenIds->isNotEmpty()) {
                    $user->tokens()->whereIn('id', $tokenIds)->update(['revoked' => true]);
                    Passport::refreshToken()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
                }
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
