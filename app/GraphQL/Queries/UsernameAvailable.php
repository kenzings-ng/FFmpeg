<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Support\Username;
use Illuminate\Support\Facades\Auth;

final class UsernameAvailable
{
    /**
     * Check handle availability while typing (registration / profile update).
     * Handles are public identifiers, so returning availability does not leak private info.
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
