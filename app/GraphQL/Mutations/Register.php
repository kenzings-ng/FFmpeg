<?php declare(strict_types=1);

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
            'email' => $args['email'],
            // Model có cast 'password' => 'hashed' nên tự băm, không cần
            // gọi Hash::make() ở đây.
            'password' => $args['password'],
        ]);

        // Không chặn đăng nhập nếu email chưa xác thực (xem ghi chú trong
        // graphql/auth/auth.graphql) — chỉ gửi email, không bắt buộc verify
        // trước khi dùng được tài khoản.
        $user->sendEmailVerificationNotification();

        // Đăng ký xong đăng nhập luôn, trả thẳng token như login() để FE
        // không phải gọi thêm một mutation nữa.
        return app(PassportTokenIssuer::class)->issue([
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'username' => $user->email,
            'password' => $args['password'],
        ], rememberMe: $args['remember_me'] ?? false);
    }
}
