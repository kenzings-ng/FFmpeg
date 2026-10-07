<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Support\Username;
use Illuminate\Support\Facades\Auth;

final class UsernameAvailable
{
    /**
     * Kiểm tra handle khi đang gõ (đăng ký / đổi trong hồ sơ). Handle là
     * thông tin công khai nên trả lời "đã có người dùng" không làm lộ gì thêm.
     *
     * @param  null  $_
     * @param  array{username: string}  $args
     * @return array{username: string, available: bool, message: string|null}
     */
    public function __invoke($_, array $args): array
    {
        $problem = Username::problem($args['username'], Auth::guard('api')->user());

        return [
            'username' => Username::normalize($args['username']),
            'available' => $problem === null,
            'message' => $problem,
        ];
    }
}
