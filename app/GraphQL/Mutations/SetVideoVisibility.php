<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Video;

final class SetVideoVisibility
{
    /**
     * @param  null  $_
     * @param  array{id: string, is_public: bool}  $args
     */
    public function __invoke($_, array $args): Video
    {
        $video = Video::findOrFail($args['id']);
        $video->update(['is_public' => $args['is_public']]);

        return $video;
    }
}
