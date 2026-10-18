<?php

declare(strict_types=1);

namespace App\Video;

use App\Models\VideoRendition;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/**
 * Encodes ONE quality tier of a video (on 'local' disk) to AES-128 encrypted HLS,
 * writing to a directory also on 'local' disk. Each tier runs in its own
 * EncodeRenditionJob; decoupled from R2 or master playlist.
 *
 * Output filenames are randomized (playlist per rendition, segment names generated):
 * storage inspection does not reveal video identity or resolution.
 */
final class HlsEncoder
{
    /** Temporary key filename in playlist during ffmpeg execution, replaced by actual rendition key. */
    private const TEMP_KEY_FILENAME = 'k.key';

    /**
     * @return string #EXT-X-STREAM-INF line (BANDWIDTH measured from actual segments) for master playlist.
     */
    public function encodeRendition(string $inputPath, string $outputDir, VideoRendition $rendition): string
    {
        $height = $rendition->height;
        $base = "{$outputDir}/r{$height}";
        // Must not start with a dot: web server rules often block paths containing '/.'.
        $tempMasterPath = "{$base}-master.m3u8";

        // One ffmpeg invocation per rendition: pbmedia/laravel-ffmpeg bundles all
        // addFormat() calls in a single save() into ONE concurrent ffmpeg process
        // (filter_complex), compounding RAM per rendition.
        FFMpeg::fromDisk('local')
            ->openWithInputOptions($inputPath, ['-protocol_whitelist', 'file'])
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

        // save() with 1 format still outputs a single-variant master playlist
        // with 1 #EXT-X-STREAM-INF line pointing to the actual rendition playlist.
        $streamInf = collect(preg_split('/\r\n|\n|\r/', $disk->get($tempMasterPath)))
            ->map(fn ($line) => trim($line))
            ->first(fn ($line) => str_starts_with($line, '#EXT-X-STREAM-INF:'));
        $disk->delete($tempMasterPath);

        throw_unless($streamInf, \RuntimeException::class, "ffmpeg did not generate a playlist for {$height}p tier");

        $streamInf = $this->withMeasuredBandwidth($streamInf, "{$base}.m3u8");
        $this->obfuscate("{$base}.m3u8", "{$outputDir}/{$rendition->playlist_name}", $rendition);

        return $streamInf;
    }

    /**
     * Renames all segments to randomized names, updates key URI to rendition key file,
     * and writes playlist under the rendition's randomized playlist name.
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
     * Target quality tiers <= source height (no upscaling). If source is below lowest tier:
     * encode 1 tier at source height (rounded down to even number for x264).
     *
     * @return array<int, int>
     */
    public function targetHeights(string $inputPath): array
    {
        $sourceHeight = FFMpeg::fromDisk('local')
            ->openWithInputOptions($inputPath, ['-protocol_whitelist', 'file'])
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
            // Keyframe every 2 seconds regardless of fps, ensuring segment boundaries
            // across all renditions align for smooth bitrate switching.
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
            // Enforce standard color format for HLS, avoiding 4:4:4/10-bit sources
            // that spike resource usage and fail on playback devices.
            '-pix_fmt', 'yuv420p',
        ];

        if ($tune = config('video.x264_tune')) {
            array_push($parameters, '-tune', (string) $tune);
        }

        return (new X264('aac'))
            // 0 = do not set -b:v: uses CRF + maxrate above; php-ffmpeg
            // runs 1 pass only (fixed bitrate default in X264 uses 2 passes).
            ->setKiloBitrate(0)
            ->setAudioKiloBitrate((int) config('video.audio_kbps'))
            ->setInitialParameters(['-protocol_whitelist', 'file'])
            ->setAdditionalParameters($parameters);
    }

    /**
     * ffmpeg-reported BANDWIDTH in master playlist is inaccurate under CRF encoding.
     * Re-measure from actual encoded segments:
     * BANDWIDTH = peak segment bitrate, AVERAGE-BANDWIDTH = overall average.
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
