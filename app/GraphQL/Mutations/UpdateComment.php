<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Comment;

final class UpdateComment
{
    /**
     * Quyền đã được @canFind(ability: "update") kiểm tra.
     *
     * @param  null  $_
     * @param  array{id: string, body: string}  $args
     */
    public function __invoke($_, array $args): Comment
    {
        $comment = Comment::findOrFail($args['id']);

        // Chỉ đánh dấu "đã chỉnh sửa" khi nội dung thật sự đổi.
        if ($comment->body !== $args['body']) {
            $comment->update(['body' => $args['body'], 'edited_at' => now()]);
            $comment->syncMentions();
        }

        return $comment;
    }
}
