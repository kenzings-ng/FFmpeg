<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Video;
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

        if ($video->isOnR2() && $video->hlsPrefix()) {
            Storage::disk('r2')->deleteDirectory($video->hlsPrefix());
        }

        $video->delete();

        return true;
    }
}
