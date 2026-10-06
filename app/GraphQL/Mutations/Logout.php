<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;

final class Logout
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args): bool
    {
        // Theo tài liệu Passport: lấy ra Token model thật (không phải AccessToken
        // decode từ JWT) rồi mới có relation refreshToken() để revoke.
        $token = Passport::token()->find(Auth::guard('api')->user()->token()->oauth_access_token_id);

        $token->revoke();
        $token->refreshToken?->revoke();

        return true;
    }
}
