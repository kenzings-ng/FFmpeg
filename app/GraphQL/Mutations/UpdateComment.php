<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Comment;

final class UpdateComment
{
    /**
     * Authorization is verified by @canFind(ability: "update").
     *
     * @param  null  $_
     * @param  array{id: string, body: string}  $args
     */
    public function __invoke($_, array $args): Comment
    {
        $comment = Comment::findOrFail($args['id']);

        // Only mark as edited when the body actually changes.
        if ($comment->body !== $args['body']) {
            $comment->update(['body' => $args['body'], 'edited_at' => now()]);
            $comment->syncMentions();
        }

        return $comment;
    }
}
