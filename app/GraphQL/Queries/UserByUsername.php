<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\User;

final class UserByUsername
{
    /**
     * Channel page /@handle. Legacy handles (recently changed, still reserved) resolve to
     * former owner so frontend can redirect to the new handle URL.
     *
     * @param  null  $_
     * @param  array{username: string}  $args
     */
    public function __invoke($_, array $args): ?User
    {
        return User::findByHandle($args['username']);
    }
}
