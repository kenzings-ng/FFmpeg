<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Video;
use App\Video\HlsStorage;
use Illuminate\Support\Facades\Storage;

final class DeleteVideo
{
    /**
     * @param  null  $_
     * @param  array{id: string}  $args
     */
    public function __invoke($_, array $args): bool
    {
        $video = Video::findOrFail($args['id']);

        // Xoá cả thư mục trên disk (file gốc nếu còn, toàn bộ HLS đã mã hóa)
        // trước khi xoá record, để không rác lại file mồ côi.
        Storage::disk('local')->deleteDirectory("videos/{$video->id}");

        // Thư mục HLS (tên ngẫu nhiên) của mọi lần encode: bản đang phát và
        // bản đang encode dở (nếu có).
        $current = $video->hlsPrefix();

        if ($current) {
            Storage::disk($video->hls_disk ?: 'local')->deleteDirectory($current);
        }

        foreach ($video->renditions()->distinct()->pluck('hls_dir')->reject(fn ($dir) => $dir === $current) as $dir) {
            Storage::disk(HlsStorage::disk())->deleteDirectory($dir);
        }

        $video->delete();

        return true;
    }
}
