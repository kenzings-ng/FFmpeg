<?php

declare(strict_types=1);

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

        // Do not distinguish between "does not exist" and "unauthorized to view" (another
        // user's private video): avoid leaking video existence.
        if (! $video || Gate::forUser($user)->denies('view', $video)) {
            throw new Error('Video not found.');
        }

        $parentId = null;
        $replyToUserId = null;

        if (! empty($args['reply_to_id'])) {
            $target = Comment::where('video_id', $video->id)->find($args['reply_to_id']);

            if (! $target) {
                throw new Error('The comment you are replying to no longer exists.');
            }

            // Only 2 levels: replies are always attached to the root comment. Replying to a
            // reply records the replied user so the frontend can display "@Name".
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
