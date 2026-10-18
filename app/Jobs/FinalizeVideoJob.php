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
 * Final step of encode pipeline: all renditions complete -> write master playlist,
 * mark video READY, clean up old HLS (if re-encoded), and delete original file.
 */
class FinalizeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected Video $video,
        protected string $hlsDir,
        protected string $diskName,
        protected ?string $posterPath,
    ) {}

    public function handle(): void
    {
        $renditions = $this->video->renditions()
            ->where('hls_dir', $this->hlsDir)
            ->orderBy('height')
            ->get();

        throw_if(
            $renditions->isEmpty() || $renditions->contains(fn ($r) => ! $r->stream_inf),
            \RuntimeException::class,
            "Video {$this->video->id}: some quality tiers have not finished encoding",
        );

        $lines = ['#EXTM3U'];

        foreach ($renditions as $rendition) {
            $lines[] = $rendition->stream_inf;
            $lines[] = $rendition->playlist_name;
        }

        $masterPath = "{$this->hlsDir}/".HlsEncoder::randomName('m3u8');
        $targetDisk = Storage::disk($this->diskName);
        $targetDisk->put($masterPath, implode("\n", $lines)."\n", ['ContentType' => 'application/vnd.apple.mpegurl']);

        // Verify master playlist and all rendition playlists exist on target disk
        $allPlaylistsExist = $targetDisk->exists($masterPath)
            && $renditions->every(fn ($r) => $targetDisk->exists("{$this->hlsDir}/{$r->playlist_name}"));

        // Verify segment files (.ts) exist on target disk
        $hasSegments = collect($targetDisk->files($this->hlsDir))->contains(fn ($file) => str_ends_with($file, '.ts'));

        throw_unless(
            $allPlaylistsExist && $hasSegments,
            \RuntimeException::class,
            "Video {$this->video->id}: incomplete playlists or segments on {$this->diskName}",
        );

        $previous = $this->video->only(['hls_disk', 'hls_playlist_path']);

        $this->video->update([
            'status' => 'ready',
            'hls_disk' => $this->diskName,
            'hls_playlist_path' => $masterPath,
            'poster_path' => $this->posterPath,
            // Legacy format used a single key; now each tier has its own key (video_renditions).
            'encrypted_key' => null,
        ]);

        // Re-encode: clean up old HLS files and keys.
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
