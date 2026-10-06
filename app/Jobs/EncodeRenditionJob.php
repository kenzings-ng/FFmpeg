<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoRendition;
use App\Video\HlsEncoder;
use App\Video\HlsStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Encode MỘT mức chất lượng rồi đưa lên disk lưu trữ. Xếp trong chuỗi do
 * SegmentVideoJob tạo; lỗi hẳn thì callback catch của chuỗi đánh dấu FAILED.
 */
class EncodeRenditionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Một mức 1080p của tập phim ~24 phút trên 2 vCPU mất cỡ 15–25 phút.
     * Phải nhỏ hơn retry_after của queue database (config/queue.php).
     */
    public int $timeout = 10800;

    public function __construct(
        protected Video $video,
        protected VideoRendition $rendition,
        protected string $diskName,
    ) {
    }

    public function handle(HlsEncoder $encoder, HlsStorage $storage): void
    {
        $workDir = "tmp/encode/{$this->rendition->id}-".Str::random(8);

        try {
            $streamInf = $encoder->encodeRendition($this->video->original_path, $workDir, $this->rendition);
            $storage->publishDirectory($workDir, $this->diskName, $this->rendition->hls_dir);

            $this->rendition->update(['stream_inf' => $streamInf, 'encoded_at' => now()]);
        } finally {
            Storage::disk('local')->deleteDirectory($workDir);
        }
    }
}
