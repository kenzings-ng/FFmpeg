<?php declare(strict_types=1);

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

        // Luôn trả true dù email có tồn tại trong hệ thống hay không — nếu
        // trả về khác nhau giữa hai trường hợp, ai đó có thể dò ra email nào
        // đã đăng ký tài khoản (account enumeration).
        return true;
    }
}
