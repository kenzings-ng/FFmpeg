<?php

namespace App\Rules;

use App\Support\Username as Handle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * Rule for username arguments in GraphQL. For authenticated user (updateProfile),
 * their own current handle is considered valid.
 */
class Username implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('Invalid username.');

            return;
        }

        if ($problem = Handle::problem($value, Auth::guard('api')->user())) {
            $fail($problem);
        }
    }
}
