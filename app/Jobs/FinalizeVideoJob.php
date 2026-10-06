<?php

namespace App\Jobs;

use App\Models\Video;
use App\Video\HlsEncoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Cuối chuỗi encode: mọi mức đã xong → ghi master playlist, chuyển video sang
 * READY, dọn bản HLS cũ (nếu là encode lại) và xóa video gốc.
 */
class FinalizeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Video $video,
        protected string $hlsDir,
        protected string $diskName,
        protected ?string $posterPath,
    ) {
    }

    public function handle(): void
    {
        $renditions = $this->video->renditions()
            ->where('hls_dir', $this->hlsDir)
            ->orderBy('height')
            ->get();

        throw_if(
            $renditions->isEmpty() || $renditions->contains(fn ($r) => ! $r->stream_inf),
            \RuntimeException::class,
            "Video {$this->video->id}: còn mức chất lượng chưa encode xong",
        );

        $lines = ['#EXTM3U'];

        foreach ($renditions as $rendition) {
            $lines[] = $rendition->stream_inf;
            $lines[] = $rendition->playlist_name;
        }

        $masterPath = "{$this->hlsDir}/".HlsEncoder::randomName('m3u8');
        Storage::disk($this->diskName)->put($masterPath, implode("\n", $lines)."\n", ['ContentType' => 'application/vnd.apple.mpegurl']);

        $previous = $this->video->only(['hls_disk', 'hls_playlist_path']);

        $this->video->update([
            'status' => 'ready',
            'hls_disk' => $this->diskName,
            'hls_playlist_path' => $masterPath,
            'poster_path' => $this->posterPath,
            // Định dạng cũ dùng một khóa chung cho cả video; giờ mỗi mức một khóa (video_renditions).
            'encrypted_key' => null,
        ]);

        // Encode lại: dọn bản HLS cũ và khóa của nó.
        if ($previous['hls_playlist_path'] && dirname($previous['hls_playlist_path']) !== $this->hlsDir) {
            rescue(fn () => Storage::disk($previous['hls_disk'] ?: 'local')->deleteDirectory(dirname($previous['hls_playlist_path'])));
        }
        $this->video->renditions()->where('hls_dir', '!=', $this->hlsDir)->delete();

        $original = $this->video->original_path;

        if ($original && Storage::disk('local')->exists($original)) {
            Storage::disk('local')->delete($original);
        } else {
            Log::warning("Original video not found for deletion: {$original}");
        }
    }
}
