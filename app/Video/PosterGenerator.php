<?php

declare(strict_types=1);

namespace App\Video;

use Symfony\Component\Process\Process;

/**
 * Captures JPEG poster image from a video file using ffmpeg.
 */
final class PosterGenerator
{
    public const FILENAME = 'poster.jpg';

    /** Maximum dimension of poster (px), preserving aspect ratio. */
    private const MAX_SIZE = 960;

    /**
     * @param  float  $seekSeconds  Timestamp in seconds to start looking for candidate frame.
     */
    public function generate(string $inputAbsolutePath, string $outputAbsolutePath, float $seekSeconds = 0): void
    {
        $process = new Process([
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            '-y', '-v', 'error',
            '-protocol_whitelist', 'file',
            '-ss', sprintf('%.3f', max(0, $seekSeconds)),
            '-i', $inputAbsolutePath,
            // thumbnail: picks the most representative frame within the next 60 frames,
            // avoiding black frames or scene transitions.
            '-vf', 'thumbnail=60,scale=w='.self::MAX_SIZE.':h='.self::MAX_SIZE
                .':force_original_aspect_ratio=decrease:force_divisible_by=2',
            '-frames:v', '1',
            '-q:v', '3',
            $outputAbsolutePath,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        if (! is_file($outputAbsolutePath) || filesize($outputAbsolutePath) === 0) {
            throw new \RuntimeException("ffmpeg failed to generate poster from {$inputAbsolutePath}");
        }
    }

    /**
     * Seek timestamp: 10% of total duration (skipping intros/logos), capped at 60 seconds.
     */
    public static function seekFor(float $durationSeconds): float
    {
        return min(60.0, $durationSeconds * 0.1);
    }
}
