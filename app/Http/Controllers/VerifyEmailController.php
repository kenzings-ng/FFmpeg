<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/**
 * Nhận request khi người dùng bấm link trong email xác thực.
 *
 * Không dùng Illuminate\Foundation\Auth\EmailVerificationRequest như scaffold
 * mặc định của Laravel (Breeze/Fortify) vì request đó đòi $request->user()
 * đã đăng nhập qua session guard 'web' — app này không có session login,
 * user chỉ xác thực bằng Bearer token (Passport). Người bấm link từ email
 * có thể đang mở trình duyệt không hề đăng nhập, nên phải tự tra user theo
 * {id} trong URL và chỉ tin vào chữ ký (đã được middleware 'signed' kiểm
 * tra trước khi vào tới đây).
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim(config('services.frontend.url'), '/');

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away("{$frontendUrl}/email-verified?status=invalid");
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->away("{$frontendUrl}/email-verified?status=ok");
    }
}
