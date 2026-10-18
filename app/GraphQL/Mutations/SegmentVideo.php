<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Jobs\SegmentVideoJob;
use App\Models\Video;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class SegmentVideo
{
    /**
     * @param  null  $_
     * @param  array{id: string}  $args
     */
    public function __invoke($_, array $args): Video
    {
        $video = Video::findOrFail($args['id']);

        Gate::authorize('update', $video);

        if (in_array($video->status, ['pending', 'processing'], true)) {
            throw new Error('Video is currently processing and cannot be resegmented at this time.');
        }

        if (! $video->original_path || ! Storage::disk('local')->exists($video->original_path)) {
            throw new Error('The original video file does not exist or has been deleted.');
        }

        $video->update(['status' => 'pending']);

        SegmentVideoJob::dispatch($video);

        return $video;
    }
}
