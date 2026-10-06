<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Jobs\SegmentVideoJob;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

final class UploadVideo
{
    /**
     * @param  null  $_
     * @param  array{title: string, file: UploadedFile, is_public?: bool}  $args
     */
    public function __invoke($_, array $args): Video
    {
        $user = Auth::guard('api')->user();

        /** @var UploadedFile $file */
        $file = $args['file'];

        // Video luôn thuộc về một user và mặc định private; owner tự bật
        // public sau qua mutation setVideoVisibility nếu muốn.
        $video = Video::create([
            'title' => $args['title'],
            'user_id' => $user->id,
            'is_public' => $args['is_public'] ?? false,
            // Phải set tường minh: 'pending' là default ở tầng migration
            // (DB), Eloquent không tự nạp lại default đó vào object PHP sau
            // khi create() — nếu bỏ qua, $video->status là null trong bộ nhớ
            // ngay tại response này (dù DB đã lưu đúng), và field GraphQL
            // Video.status (non-null) sẽ crash.
            'status' => 'pending',
        ]);

        // Lưu trên disk 'local' (storage/app), không public, không qua nginx.
        $extension = $file->getClientOriginalExtension() ?: 'mp4';
        $path = $file->storeAs("videos/{$video->id}", "original.{$extension}", 'local');

        $video->update(['original_path' => $path]);

        SegmentVideoJob::dispatch($video);

        return $video;
    }
}
