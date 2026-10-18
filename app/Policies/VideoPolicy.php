<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    /**
     * View details / stream video: public, or the video owner.
     */
    public function view(User $user, Video $video): bool
    {
        return $video->is_public || $video->user_id === $user->id;
    }

    /**
     * Toggle private/public, edit title, retrigger segmentVideo: video owner only.
     */
    public function update(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }

    /**
     * Delete video: video owner only. Kept separate from update() to allow
     * potential future roles (e.g. co-editors) to update without permission to delete.
     */
    public function delete(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }
}
