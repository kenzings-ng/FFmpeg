<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoRendition;
use App\Video\HlsEncoder;
use App\Video\HlsStorage;
use App\Video\PosterGenerator;
use App\Video\PosterVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/**
 * Bước đầu của xử lý video: chọn các mức chất lượng, tạo khóa AES riêng cho
 * từng mức, chụp ảnh bìa, rồi xếp chuỗi job:
 *
 *   EncodeRenditionJob(480p) → EncodeRenditionJob(720p) → … → FinalizeVideoJob
 *
 * Mỗi mức là một job riêng (ngắn, lỗi thì chỉ thử lại mức đó). Video chỉ
 * chuyển READY ở FinalizeVideoJob, khi MỌI mức đã xong.
 *
 * Chuỗi job chạy tuần tự trên 1 worker (supervisor numprocs=1): đừng tăng số
 * worker trên server 2 vCPU / 3.3GB RAM, nhiều ffmpeg cùng lúc từng bị OOM-killed.
 */
class SegmentVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(protected Video $video)
    {
    }

    public function handle(HlsEncoder $encoder, PosterGenerator $posters): void
    {
        $this->video->update(['status' => 'processing']);

        $diskName = HlsStorage::disk();
        // Tên thư mục ngẫu nhiên, không chứa video id. Mỗi lần encode ra thư
        // mục mới: bản cũ (nếu có) vẫn phát được tới khi bản mới xong.
        $hlsDir = 'hls/'.Str::random(32);

        try {
            $this->prepare($encoder, $posters, $diskName, $hlsDir);
        } catch (\Throwable $e) {
            // Lần thử sau dùng thư mục mới: dọn khóa / ảnh bìa đã tạo ở lần này.
            VideoRendition::where('video_id', $this->video->id)->where('hls_dir', $hlsDir)->delete();
            rescue(fn () => Storage::disk($diskName)->deleteDirectory($hlsDir), report: false);

            throw $e;
        }
    }

    private function prepare(HlsEncoder $encoder, PosterGenerator $posters, string $diskName, string $hlsDir): void
    {
        $renditions = collect($encoder->targetHeights($this->video->original_path))
            ->map(fn (int $height) => VideoRendition::create([
                'video_id' => $this->video->id,
                'hls_dir' => $hlsDir,
                'height' => $height,
                'playlist_name' => HlsEncoder::randomName('m3u8'),
                'key_id' => Str::random(40),
                'encrypted_key' => Crypt::encryptString(HLSExporter::generateEncryptionKey()),
            ]));

        $posterPath = $this->storePoster($posters, $diskName, $hlsDir);
        $videoId = $this->video->id;

        Bus::chain([
            ...$renditions->map(fn (VideoRendition $rendition) => new EncodeRenditionJob($this->video, $rendition, $diskName)),
            new FinalizeVideoJob($this->video, $hlsDir, $diskName, $posterPath),
        ])->catch(function (\Throwable $e) use ($videoId, $hlsDir, $diskName) {
            SegmentVideoJob::abandon($videoId, $hlsDir, $diskName);
        })->dispatch();
    }

    /**
     * Một job trong chuỗi lỗi hẳn (đã hết số lần thử): video FAILED, dọn phần
     * đã upload của lần encode này. Video gốc được giữ để gọi segmentVideo lại.
     */
    public static function abandon(int $videoId, string $hlsDir, string $diskName): void
    {
        Video::whereKey($videoId)->update(['status' => 'failed']);
        VideoRendition::where('video_id', $videoId)->where('hls_dir', $hlsDir)->delete();
        rescue(fn () => Storage::disk($diskName)->deleteDirectory($hlsDir), report: false);
    }

    /**
     * Ảnh bìa là phụ: lỗi thì chỉ ghi log, video vẫn xử lý tiếp (không có ảnh bìa).
     * Được mã hóa trước khi lưu (PosterVault): storage không chứa ảnh rõ.
     */
    private function storePoster(PosterGenerator $posters, string $diskName, string $hlsDir): ?string
    {
        return rescue(function () use ($posters, $diskName, $hlsDir) {
            $input = $this->video->original_path;
            $duration = (float) FFMpeg::fromDisk('local')->open($input)->getDurationInSeconds();
            $tmp = tempnam(sys_get_temp_dir(), 'poster').'.jpg';

            try {
                $posters->generate(Storage::disk('local')->path($input), $tmp, PosterGenerator::seekFor($duration));
                $path = "{$hlsDir}/".HlsEncoder::randomName(PosterVault::EXTENSION);
                Storage::disk($diskName)->put($path, PosterVault::fromConfig()->encrypt(file_get_contents($tmp), $hlsDir));

                return $path;
            } finally {
                @unlink($tmp);
            }
        }, function (\Throwable $e) {
            Log::warning("Không tạo được ảnh bìa cho video {$this->video->id}: {$e->getMessage()}");

            return null;
        });
    }

    /**
     * Laravel gọi khi job đã thử lại đủ số lần (--tries) mà vẫn lỗi.
     */
    public function failed(\Throwable $e): void
    {
        $this->video->update(['status' => 'failed']);
    }
}
