<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Video;

final class UpdateVideo
{
    /**
     * @param  null  $_
     * @param  array{id: string, title: string}  $args
     */
    public function __invoke($_, array $args): Video
    {
        $video = Video::findOrFail($args['id']);
        $video->update(['title' => $args['title']]);

        return $video;
    }
}
