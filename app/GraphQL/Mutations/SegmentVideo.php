<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Jobs\SegmentVideoJob;
use App\Models\Video;

final class SegmentVideo
{
    /**
     * @param  null  $_
     * @param  array{id: string}  $args
     */
    public function __invoke($_, array $args): Video
    {
        $video = Video::findOrFail($args['id']);
        // Về 'pending' ngay khi nhận yêu cầu, kể cả đang gọi lại sau khi
        // 'failed' hay đã 'ready' — Job sẽ tự chuyển sang 'processing' khi
        // thật sự bắt đầu chạy (có thể phải đợi trong hàng đợi một lúc nếu
        // worker đang bận video khác).
        $video->update(['status' => 'pending']);

        SegmentVideoJob::dispatch($video);

        return $video;
    }
}
