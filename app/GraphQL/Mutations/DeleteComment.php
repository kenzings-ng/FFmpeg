<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Comment;

final class DeleteComment
{
    /**
     * Quyền đã được @canFind(ability: "delete") kiểm tra. Trả lời của bình
     * luận gốc bị xóa theo nhờ khóa ngoại parent_id (cascade).
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
