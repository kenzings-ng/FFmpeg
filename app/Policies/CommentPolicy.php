<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Viewing a comment requires viewing access to the video: if the video is private,
     * its comments are also hidden from other users.
     */
    public function view(User $user, Comment $comment): bool
    {
        return $user->can('view', $comment->video);
    }

    /** Edit: author only. */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    /** Delete: author or video owner (moderating comments on their own video). */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id || $comment->video->user_id === $user->id;
    }
}
