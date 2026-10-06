<?php

namespace App\Jobs;

use App\Models\Video;
use App\Video\HlsEncoder;
use App\Video\PosterGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class SegmentVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Một tập anime ~24 phút encode 3 mức trên 2 vCPU mất cỡ 30–45 phút, video
     * dài hơn còn lâu hơn. Phải nhỏ hơn retry_after của queue database
     * (config/queue.php), nếu không job đang chạy sẽ bị worker khác lấy lại.
     */
    public int $timeout = 10800;

    public function __construct(protected Video $video)
    {
    }

    public function handle(HlsEncoder $encoder, PosterGenerator $posters): void
    {
        $this->video->update(['status' => 'processing']);

        $inputPath = $this->video->original_path; // Đường dẫn tương đối trên disk 'local'
        $workDir = "videos/{$this->video->id}/hls";
        $targetDisk = config('video.hls_disk') === 'r2' ? 'r2' : 'local';
        // Thư mục trên R2 có phần ngẫu nhiên: không đoán được đường dẫn của
        // video khác từ id, và mỗi lần encode lại ra thư mục mới (không đè
        // lên file đang được CDN cache).
        $remoteDir = $targetDisk === 'r2' ? "hls/{$this->video->id}-".Str::random(24) : null;

        try {
            // Khóa AES-128 mã hóa từng segment .ts. Không bao giờ ghi khóa thô
            // xuống disk: chỉ lưu bản đã Crypt::encryptString() trong DB.
            $encryptionKey = HLSExporter::generateEncryptionKey();

            Storage::disk('local')->deleteDirectory($workDir);
            $encoder->encode($inputPath, $workDir, $encryptionKey);
            $hasPoster = $this->generatePoster($posters, $inputPath, $workDir);

            if ($remoteDir) {
                $this->uploadToR2($workDir, $remoteDir);
                Storage::disk('local')->deleteDirectory($workDir);
            }

            $previous = $this->video->only(['hls_disk', 'hls_playlist_path']);
            $playlistDir = $remoteDir ?? $workDir;

            $this->video->update([
                'status' => 'ready',
                'hls_disk' => $targetDisk,
                'hls_playlist_path' => "{$playlistDir}/".HlsEncoder::MASTER_PLAYLIST,
                'poster_path' => $hasPoster ? "{$playlistDir}/".PosterGenerator::FILENAME : null,
                'encrypted_key' => Crypt::encryptString($encryptionKey),
            ]);

            // Encode lại một video từng nằm trên R2: dọn bản HLS cũ.
            if ($previous['hls_disk'] === 'r2' && $previous['hls_playlist_path']
                && dirname($previous['hls_playlist_path']) !== $playlistDir) {
                Storage::disk('r2')->deleteDirectory(dirname($previous['hls_playlist_path']));
            }

            // Xóa video gốc sau khi phân giải xong
            if (Storage::disk('local')->exists($inputPath)) {
                Storage::disk('local')->delete($inputPath);
            } else {
                Log::warning('Original video not found for deletion: '.$inputPath);
            }
        } catch (\Throwable $e) {
            // Lần thử này hỏng giữa chừng: dọn phần đã upload dở lên R2 (lần
            // thử sau dùng thư mục ngẫu nhiên khác nên sẽ thành rác mồ côi).
            if ($remoteDir) {
                rescue(fn () => Storage::disk('r2')->deleteDirectory($remoteDir), report: false);
            }

            // Laravel sẽ tự retry (xem --tries trên queue worker) trước khi
            // thật sự coi là fail hẳn; mỗi lần thử lại, handle() ở trên lại
            // set về 'processing'. Nếu hết số lần retry, failed() bên dưới
            // mới chốt lại thành 'failed' để FE biết mà báo người dùng.
            throw $e;
        }
    }

    /**
     * Ảnh bìa là phụ: lỗi thì chỉ ghi log, video vẫn READY (không có ảnh bìa).
     */
    private function generatePoster(PosterGenerator $posters, string $inputPath, string $workDir): bool
    {
        return rescue(function () use ($posters, $inputPath, $workDir) {
            $duration = FFMpeg::fromDisk('local')->open($inputPath)->getDurationInSeconds();

            $posters->generate(
                Storage::disk('local')->path($inputPath),
                Storage::disk('local')->path("{$workDir}/".PosterGenerator::FILENAME),
                PosterGenerator::seekFor((float) $duration),
            );

            return true;
        }, false);
    }

    /**
     * Upload toàn bộ HLS lên R2. Variant playlist được sửa URI của khóa AES
     * thành endpoint của Laravel (VideoKeyController): khóa không bao giờ nằm
     * trên R2, chỉ trả cho ai có stream token hợp lệ.
     */
    private function uploadToR2(string $workDir, string $remoteDir): void
    {
        $local = Storage::disk('local');
        $r2 = Storage::disk('r2');
        $keyUrl = route('videos.key', ['video' => $this->video->id]);

        foreach ($local->files($workDir) as $path) {
            $name = basename($path);

            if (str_ends_with($name, '.m3u8')) {
                $contents = str_replace(
                    'URI="'.HlsEncoder::KEY_FILENAME.'"',
                    'URI="'.$keyUrl.'"',
                    $local->get($path),
                );
                $r2->put("{$remoteDir}/{$name}", $contents, ['ContentType' => 'application/vnd.apple.mpegurl']);

                continue;
            }

            $stream = $local->readStream($path);
            $contentType = str_ends_with($name, '.jpg') ? 'image/jpeg' : 'video/mp2t';

            try {
                $r2->writeStream("{$remoteDir}/{$name}", $stream, ['ContentType' => $contentType]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /**
     * Laravel gọi khi job đã thử lại đủ số lần (--tries) mà vẫn lỗi.
     */
    public function failed(\Throwable $e): void
    {
        $this->video->update(['status' => 'failed']);
    }
}
