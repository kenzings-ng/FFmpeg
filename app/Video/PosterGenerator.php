<?php

declare(strict_types=1);

namespace App\Video;

use Symfony\Component\Process\Process;

/**
 * Chụp ảnh bìa JPEG từ một file video bằng ffmpeg.
 */
final class PosterGenerator
{
    public const FILENAME = 'poster.jpg';

    /** Cạnh dài nhất của ảnh bìa (px), giữ nguyên tỉ lệ (video dọc vẫn dọc). */
    private const MAX_SIZE = 960;

    /**
     * @param  float  $seekSeconds  Bắt đầu tìm khung hình từ giây này.
     */
    public function generate(string $inputAbsolutePath, string $outputAbsolutePath, float $seekSeconds = 0): void
    {
        $process = new Process([
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            '-y', '-v', 'error',
            '-ss', sprintf('%.3f', max(0, $seekSeconds)),
            '-i', $inputAbsolutePath,
            // thumbnail: chọn khung "đại diện" nhất trong 60 khung kế tiếp,
            // tránh chụp trúng khung đen / chuyển cảnh.
            '-vf', 'thumbnail=60,scale=w='.self::MAX_SIZE.':h='.self::MAX_SIZE
                .':force_original_aspect_ratio=decrease:force_divisible_by=2',
            '-frames:v', '1',
            '-q:v', '3',
            $outputAbsolutePath,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        if (! is_file($outputAbsolutePath) || filesize($outputAbsolutePath) === 0) {
            throw new \RuntimeException("ffmpeg không tạo được ảnh bìa từ {$inputAbsolutePath}");
        }
    }

    /**
     * Mốc bắt đầu chụp: 10% thời lượng (bỏ qua intro / logo đầu phim), tối đa 60 giây.
     */
    public static function seekFor(float $durationSeconds): float
    {
        return min(60.0, $durationSeconds * 0.1);
    }
}
