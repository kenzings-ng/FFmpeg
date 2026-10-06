<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    /**
     * Xem chi tiết / phát video: public, hoặc chủ sở hữu.
     */
    public function view(User $user, Video $video): bool
    {
        return $video->is_public || $video->user_id === $user->id;
    }

    /**
     * Đổi private/public, sửa title, trigger lại segmentVideo: chỉ chủ sở hữu.
     */
    public function update(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }

    /**
     * Xoá video: chỉ chủ sở hữu. Tách riêng khỏi update() để sau này có thể
     * cho phép vai trò khác (vd. đồng biên tập) update mà không được xoá.
     */
    public function delete(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }
}
