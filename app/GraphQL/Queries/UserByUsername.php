<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\User;

final class UserByUsername
{
    /**
     * Trang kênh /@handle. Handle cũ (vừa đổi, còn giữ chỗ) trả về chủ cũ —
     * FE thấy user.username khác handle đã hỏi thì chuyển sang URL mới.
     *
     * @param  null  $_
     * @param  array{username: string}  $args
     */
    public function __invoke($_, array $args): ?User
    {
        return User::findByHandle($args['username']);
    }
}
