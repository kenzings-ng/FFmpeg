<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

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

        $user->fill(array_filter([
            'name' => $args['name'] ?? null,
            // Model có cast 'password' => 'hashed' nên tự băm.
            'password' => $args['password'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return $user;
    }
}
