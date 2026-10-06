<?php

declare(strict_types=1);

namespace App\Video;

use App\Models\VideoRendition;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/**
 * Encode MỘT mức chất lượng của video (trên disk 'local') thành HLS mã hóa
 * AES-128, ghi vào một thư mục cũng trên disk 'local'. Mỗi mức chạy trong
 * EncodeRenditionJob riêng; không biết gì về R2 hay master playlist.
 *
 * Tên file đầu ra đều ngẫu nhiên (playlist lấy theo rendition, segment tự
 * sinh): ai xem được storage cũng không biết đó là video nào, độ phân giải nào.
 */
final class HlsEncoder
{
    /** Tên tạm của file khóa trong playlist lúc ffmpeg chạy, được thay bằng khóa thật của rendition. */
    private const TEMP_KEY_FILENAME = 'k.key';

    /**
     * @return string Dòng #EXT-X-STREAM-INF (BANDWIDTH đo từ segment thật) cho master playlist.
     */
    public function encodeRendition(string $inputPath, string $outputDir, VideoRendition $rendition): string
    {
        $height = $rendition->height;
        $base = "{$outputDir}/r{$height}";
        // Không được bắt đầu bằng dấu chấm: nginx chặn mọi path chứa '/.'.
        $tempMasterPath = "{$base}-master.m3u8";

        // Một lệnh ffmpeg cho đúng một rendition: pbmedia/laravel-ffmpeg gộp
        // mọi addFormat() của cùng một save() vào MỘT tiến trình ffmpeg chạy
        // song song (filter_complex), RAM cộng dồn theo số rendition — trên
        // server 2 vCPU / 3.3GB RAM từng bị OOM-killed (signal 9).
        FFMpeg::fromDisk('local')
            ->open($inputPath)
            ->exportForHLS()
            ->setSegmentLength((int) config('video.segment_length'))
            ->setKeyFrameInterval(48)
            ->withEncryptionKey($rendition->key(), self::TEMP_KEY_FILENAME)
            ->useSegmentFilenameGenerator(function ($name, $format, $key, $segments, $playlist) use ($base) {
                $segments("{$base}_%05d.ts");
                $playlist("{$base}.m3u8");
            })
            ->addFormat($this->format($this->maxrateFor($height)), fn ($media) => $media->scale(-2, $height))
            ->toDisk('local')
            ->save($tempMasterPath);

        $disk = Storage::disk('local');

        // save() với 1 format vẫn sinh một playlist "master" chỉ có 1 dòng
        // #EXT-X-STREAM-INF trỏ tới playlist thật của rendition đó.
        $streamInf = collect(preg_split('/\r\n|\n|\r/', $disk->get($tempMasterPath)))
            ->map(fn ($line) => trim($line))
            ->first(fn ($line) => str_starts_with($line, '#EXT-X-STREAM-INF:'));
        $disk->delete($tempMasterPath);

        throw_unless($streamInf, \RuntimeException::class, "ffmpeg không sinh playlist cho mức {$height}p");

        $streamInf = $this->withMeasuredBandwidth($streamInf, "{$base}.m3u8");
        $this->obfuscate("{$base}.m3u8", "{$outputDir}/{$rendition->playlist_name}", $rendition);

        return $streamInf;
    }

    /**
     * Đổi tên mọi segment sang tên ngẫu nhiên, trỏ khóa về file khóa của
     * rendition, rồi ghi playlist dưới tên ngẫu nhiên của rendition.
     */
    private function obfuscate(string $playlistPath, string $targetPath, VideoRendition $rendition): void
    {
        $disk = Storage::disk('local');
        $directory = dirname($playlistPath);

        $lines = array_map(function (string $line) use ($disk, $directory, $rendition) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '#EXT-X-KEY:')) {
                return str_replace('URI="'.self::TEMP_KEY_FILENAME.'"', 'URI="'.$rendition->keyFilename().'"', $trimmed);
            }

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                return $trimmed;
            }

            $name = self::randomName('ts');
            $disk->move("{$directory}/{$trimmed}", "{$directory}/{$name}");

            return $name;
        }, preg_split('/\r\n|\n|\r/', $disk->get($playlistPath)));

        $disk->put($targetPath, rtrim(implode("\n", $lines))."\n");

        if ($playlistPath !== $targetPath) {
            $disk->delete($playlistPath);
        }
    }

    public static function randomName(string $extension): string
    {
        return Str::random(22).".{$extension}";
    }

    /**
     * Các mức <= chiều cao gốc (không upscale). Gốc thấp hơn mức nhỏ nhất:
     * encode 1 mức ở đúng chiều cao gốc (làm tròn xuống số chẵn cho x264).
     *
     * @return array<int, int>
     */
    public function targetHeights(string $inputPath): array
    {
        $sourceHeight = FFMpeg::fromDisk('local')
            ->open($inputPath)
            ->getVideoStream()
            ->getDimensions()
            ->getHeight();

        $heights = collect(config('video.renditions'))
            ->pluck('height')
            ->filter(fn (int $height) => $height <= $sourceHeight)
            ->sort()
            ->values()
            ->all();

        return $heights ?: [max(2, $sourceHeight - $sourceHeight % 2)];
    }

    private function maxrateFor(int $height): int
    {
        $renditions = collect(config('video.renditions'))->sortBy('height');

        return (int) ($renditions->first(fn (array $r) => $r['height'] >= $height) ?? $renditions->last())['maxrate'];
    }

    private function format(int $maxrateKbps): X264
    {
        $parameters = [
            '-preset', (string) config('video.x264_preset'),
            '-crf', (string) config('video.crf'),
            '-maxrate', "{$maxrateKbps}k",
            '-bufsize', ($maxrateKbps * 2).'k',
            // Keyframe mỗi 2 giây bất kể fps, để ranh giới segment của mọi
            // rendition trùng nhau — player đổi chất lượng không bị giật.
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            // Ép định dạng màu chuẩn cho HLS, tránh nguồn 4:4:4/10-bit đội
            // RAM/CPU lên và nhiều thiết bị không giải mã được.
            '-pix_fmt', 'yuv420p',
        ];

        if ($tune = config('video.x264_tune')) {
            array_push($parameters, '-tune', (string) $tune);
        }

        return (new X264('aac'))
            // 0 = không đặt -b:v: dùng CRF + maxrate ở trên, và php-ffmpeg
            // chỉ chạy 1 pass (bitrate cố định mặc định của X264 là 2 pass).
            ->setKiloBitrate(0)
            ->setAudioKiloBitrate((int) config('video.audio_kbps'))
            ->setAdditionalParameters($parameters);
    }

    /**
     * BANDWIDTH ffmpeg ghi vào master playlist không đáng tin khi encode CRF
     * (không có bitrate mục tiêu). Đo lại từ chính các segment vừa encode:
     * BANDWIDTH = segment có bitrate cao nhất, AVERAGE-BANDWIDTH = trung bình.
     */
    private function withMeasuredBandwidth(string $streamInfLine, string $variantPath): string
    {
        $disk = Storage::disk('local');
        $directory = dirname($variantPath);
        $duration = null;
        $totalBits = 0;
        $totalSeconds = 0.0;
        $peak = 0;

        foreach (preg_split('/\r\n|\n|\r/', $disk->get($variantPath)) as $line) {
            $line = trim($line);

            if (preg_match('/^#EXTINF:([\d.]+)/', $line, $match)) {
                $duration = (float) $match[1];
            } elseif ($line !== '' && ! str_starts_with($line, '#') && $duration) {
                $bits = $disk->size("{$directory}/{$line}") * 8;
                $totalBits += $bits;
                $totalSeconds += $duration;
                $peak = max($peak, (int) ceil($bits / $duration));
                $duration = null;
            }
        }

        if ($totalSeconds <= 0) {
            return $streamInfLine;
        }

        $average = (int) ceil($totalBits / $totalSeconds);
        $attributes = preg_replace('/,?(AVERAGE-)?BANDWIDTH=\d+/', '', substr($streamInfLine, strlen('#EXT-X-STREAM-INF:')));

        return "#EXT-X-STREAM-INF:BANDWIDTH={$peak},AVERAGE-BANDWIDTH={$average}".($attributes !== '' ? ','.ltrim($attributes, ',') : '');
    }
}
