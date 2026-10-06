<?php

declare(strict_types=1);

namespace App\Video;

use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/**
 * Encode một video (trên disk 'local') thành HLS nhiều mức chất lượng, mã hóa
 * AES-128, ghi vào một thư mục cũng trên disk 'local'. Không biết gì về R2:
 * SegmentVideoJob tự upload thư mục kết quả nếu cần.
 */
final class HlsEncoder
{
    public const MASTER_PLAYLIST = 'playlist.m3u8';

    public const KEY_FILENAME = 'secret.key';

    /**
     * @return array<int, int> Chiều cao các rendition đã encode, từ thấp đến cao.
     */
    public function encode(string $inputPath, string $outputDir, string $encryptionKey): array
    {
        $heights = $this->targetHeights($inputPath);
        $masterLines = ['#EXTM3U'];

        // QUAN TRỌNG: encode TỪNG rendition trong MỘT tiến trình ffmpeg RIÊNG,
        // tuần tự — không gọi nhiều addFormat() rồi save() một lần.
        // pbmedia/laravel-ffmpeg gộp mọi addFormat() của CÙNG MỘT save() vào
        // một lệnh ffmpeg duy nhất chạy song song bằng filter_complex, nên
        // RAM/CPU cộng dồn theo số rendition. Trên server 2 vCPU / 3.3GB RAM,
        // encode nhiều rendition cùng lúc từng khiến ffmpeg bị OOM-killed
        // (signal 9, đã kiểm chứng). Tách tuần tự giữ RAM đỉnh bằng đúng 1
        // rendition, đổi lại tổng thời gian xử lý lâu hơn.
        //
        // supervisor/laravel-worker.conf.example chạy đúng 1 worker (numprocs=1), nên
        // nhiều user upload cùng lúc cũng chỉ xếp hàng trong bảng `jobs`.
        foreach ($heights as $height) {
            $maxrate = $this->maxrateFor($height);

            // Không được bắt đầu bằng dấu chấm: nginx chặn mọi path chứa '/.'.
            $tempMasterPath = "{$outputDir}/master-{$height}.m3u8";

            FFMpeg::fromDisk('local')
                ->open($inputPath)
                ->exportForHLS()
                ->setSegmentLength((int) config('video.segment_length'))
                ->setKeyFrameInterval(48)
                ->withEncryptionKey($encryptionKey, self::KEY_FILENAME)
                ->useSegmentFilenameGenerator(function ($name, $format, $key, $segments, $playlist) use ($outputDir, $height) {
                    $segments("{$outputDir}/{$height}p_%05d.ts");
                    $playlist("{$outputDir}/{$height}p.m3u8");
                })
                ->addFormat($this->format($maxrate), fn ($media) => $media->scale(-2, $height))
                ->toDisk('local')
                ->save($tempMasterPath);

            // save() với 1 format vẫn sinh một playlist "master" chỉ có 1 dòng
            // #EXT-X-STREAM-INF trỏ tới playlist thật của rendition đó.
            $lines = collect(preg_split('/\r\n|\n|\r/', Storage::disk('local')->get($tempMasterPath)))
                ->map(fn ($line) => trim($line))
                ->reject(fn ($line) => in_array($line, ['#EXTM3U', '#EXT-X-ENDLIST', ''], true))
                ->values();

            Storage::disk('local')->delete($tempMasterPath);

            foreach ($lines as $line) {
                $masterLines[] = str_starts_with($line, '#EXT-X-STREAM-INF:')
                    ? $this->withMeasuredBandwidth($line, "{$outputDir}/{$height}p.m3u8")
                    : $line;
            }
        }

        Storage::disk('local')->put("{$outputDir}/".self::MASTER_PLAYLIST, implode("\n", $masterLines)."\n");

        return $heights;
    }

    /**
     * Các mức <= chiều cao gốc (không upscale). Gốc thấp hơn mức nhỏ nhất:
     * encode 1 mức ở đúng chiều cao gốc (làm tròn xuống số chẵn cho x264).
     *
     * @return array<int, int>
     */
    private function targetHeights(string $inputPath): array
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
