<?php

namespace App\Rules;

use App\Support\Username as Handle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * Rule cho argument username trong GraphQL. Đang đăng nhập (updateProfile) thì
 * handle hiện tại của chính mình được coi là hợp lệ.
 */
class Username implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Tên người dùng không hợp lệ.');

            return;
        }

        if ($problem = Handle::problem($value, Auth::guard('api')->user())) {
            $fail($problem);
        }
    }
}
