<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Xem bình luận = xem được video: video chuyển sang private thì bình luận
     * cũng ẩn với người khác.
     */
    public function view(User $user, Comment $comment): bool
    {
        return $user->can('view', $comment->video);
    }

    /** Sửa: chỉ người viết. */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    /** Xóa: người viết, hoặc chủ video (kiểm duyệt bình luận trên video của mình). */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id || $comment->video->user_id === $user->id;
    }
}
