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
 * Initial step of video processing: selects quality tiers, creates separate AES keys
 * for each tier, captures poster, and builds the job chain:
 *
 *   EncodeRenditionJob(480p) -> EncodeRenditionJob(720p) -> ... -> FinalizeVideoJob
 *
 * Each rendition is an independent job (short, retries only that tier). Video transitions
 * to READY only in FinalizeVideoJob when ALL tiers are completed.
 *
 * The job chain runs sequentially on 1 worker (supervisor numprocs=1): do not increase
 * worker count on low-resource instances; concurrent ffmpeg processes can be OOM-killed.
 */
class SegmentVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public Video $video) {}

    public function handle(HlsEncoder $encoder, PosterGenerator $posters): void
    {
        $this->video->update(['status' => 'processing']);

        $diskName = HlsStorage::disk();
        // Randomized directory name without exposing video ID. Each encode outputs to a
        // new directory: previous version (if any) remains playable until the new one completes.
        $hlsDir = 'hls/'.Str::random(32);

        try {
            $this->prepare($encoder, $posters, $diskName, $hlsDir);
        } catch (\Throwable $e) {
            // Next retry will use a new directory: clean up keys and poster created in this attempt.
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
     * A job in the chain failed permanently (exhausted tries): mark video FAILED, clean up
     * uploaded artifacts of this attempt. Original file is kept for subsequent resegmenting.
     */
    public static function abandon(int $videoId, string $hlsDir, string $diskName): void
    {
        $video = Video::find($videoId);
        if ($video && $video->hasValidHls($hlsDir)) {
            $video->update(['status' => 'ready']);
            Log::warning("Encode chain failed for video {$videoId}, preserving ready status as existing HLS is still valid.");
        } else {
            Video::whereKey($videoId)->update(['status' => 'failed']);
        }

        VideoRendition::where('video_id', $videoId)->where('hls_dir', $hlsDir)->delete();
        rescue(fn () => Storage::disk($diskName)->deleteDirectory($hlsDir), report: false);
    }

    /**
     * Poster generation is non-fatal: failure is logged and processing continues without a poster.
     * Encrypted before storing (PosterVault): storage never contains plain images.
     */
    private function storePoster(PosterGenerator $posters, string $diskName, string $hlsDir): ?string
    {
        return rescue(function () use ($posters, $diskName, $hlsDir) {
            $input = $this->video->original_path;
            $duration = (float) FFMpeg::fromDisk('local')
                ->openWithInputOptions($input, ['-protocol_whitelist', 'file'])
                ->getDurationInSeconds();
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
            Log::warning("Failed to generate poster for video {$this->video->id}: {$e->getMessage()}");

            return null;
        });
    }

    /**
     * Invoked by Laravel when the job exhausts all retries (--tries).
     */
    public function failed(\Throwable $e): void
    {
        $video = $this->video->fresh() ?? $this->video;
        if ($video->hasValidHls()) {
            $video->update(['status' => 'ready']);
            Log::warning("SegmentVideoJob failed for video {$video->id}, preserving ready status as existing HLS is still valid: {$e->getMessage()}");
        } else {
            $video->update(['status' => 'failed']);
        }
    }
}
