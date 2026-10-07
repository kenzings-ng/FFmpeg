<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Comment;
use App\Models\Video;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class CreateComment
{
    /**
     * @param  null  $_
     * @param  array{video_id: string, body: string, reply_to_id?: string|null}  $args
     */
    public function __invoke($_, array $args): Comment
    {
        $user = Auth::guard('api')->user();
        $video = Video::find($args['video_id']);

        // Không phân biệt "không tồn tại" với "không có quyền xem" (video private
        // của người khác): không để lộ video nào đang tồn tại.
        if (! $video || Gate::forUser($user)->denies('view', $video)) {
            throw new Error('Không tìm thấy video.');
        }

        $parentId = null;
        $replyToUserId = null;

        if (! empty($args['reply_to_id'])) {
            $target = Comment::where('video_id', $video->id)->find($args['reply_to_id']);

            if (! $target) {
                throw new Error('Bình luận bạn trả lời không còn tồn tại.');
            }

            // Chỉ 2 tầng: trả lời luôn gắn vào bình luận gốc. Trả lời một trả
            // lời thì ghi lại người được trả lời để FE hiện "@Tên".
            $parentId = $target->parent_id ?? $target->id;
            $replyToUserId = $target->parent_id ? $target->user_id : null;
        }

        $comment = Comment::create([
            'video_id' => $video->id,
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'reply_to_user_id' => $replyToUserId,
            'body' => $args['body'],
        ]);
        $comment->syncMentions();

        return $comment;
    }
}
