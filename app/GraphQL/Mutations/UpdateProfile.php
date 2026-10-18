<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

final class UpdateProfile
{
    /**
     * @param  null  $_
     * @param  array{name?: string, password?: string}  $args
     */
    public function __invoke($_, array $args): User
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        // Đổi handle (ghi lịch sử) và lưu hồ sơ cùng một transaction.
        DB::transaction(function () use ($user, $args) {
            if (isset($args['username'])) {
                $user->changeUsername($args['username']);
            }

            // bio: chuỗi rỗng (TrimStrings + ConvertEmptyStringsToNull → null) là xóa.
            if (array_key_exists('bio', $args)) {
                $user->bio = $args['bio'];
            }

            $user->fill(array_filter([
                'name' => $args['name'] ?? null,
                // Model có cast 'password' => 'hashed' nên tự băm.
                'password' => $args['password'] ?? null,
            ], fn ($value) => $value !== null))->save();

            // Khi đổi mật khẩu, thu hồi toàn bộ các phiên đăng nhập khác của user
            if (isset($args['password'])) {
                $currentToken = $user->token();
                $currentTokenId = $currentToken instanceof Token
                    ? $currentToken->id
                    : ($currentToken?->oauth_access_token_id ?? $currentToken?->id ?? null);

                $otherTokensQuery = $user->tokens()->where('revoked', false);
                if ($currentTokenId) {
                    $otherTokensQuery->whereKeyNot($currentTokenId);
                }

                $otherTokenIds = $otherTokensQuery->pluck('id');
                if ($otherTokenIds->isNotEmpty()) {
                    $user->tokens()->whereIn('id', $otherTokenIds)->update(['revoked' => true]);
                    Passport::refreshToken()->whereIn('access_token_id', $otherTokenIds)->update(['revoked' => true]);
                }
            }
        });

        return $user;
    }
}
