<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Comment;

final class DeleteComment
{
    /**
     * Authorization is verified by @canFind(ability: "delete"). Replies to the
     * root comment are automatically deleted via the parent_id cascade foreign key.
     *
     * @param  null  $_
     * @param  array{id: string}  $args
     */
    public function __invoke($_, array $args): bool
    {
        Comment::findOrFail($args['id'])->delete();

        return true;
    }
}
