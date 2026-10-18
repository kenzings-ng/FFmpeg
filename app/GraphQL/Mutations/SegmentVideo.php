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
            throw new Error('Video đang trong quá trình xử lý, không thể yêu cầu segment lại lúc này.');
        }

        if (! $video->original_path || ! Storage::disk('local')->exists($video->original_path)) {
            throw new Error('Tệp gốc của video không tồn tại hoặc đã bị xóa.');
        }

        $video->update(['status' => 'pending']);

        SegmentVideoJob::dispatch($video);

        return $video;
    }
}
