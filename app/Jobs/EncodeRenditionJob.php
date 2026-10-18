<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoRendition;
use App\Video\HlsEncoder;
use App\Video\HlsStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Encode ONE rendition level and publish to storage disk. Sequenced in the job batch/chain
 * created by SegmentVideoJob; if it permanently fails, the chain's catch callback marks the video FAILED.
 */
class EncodeRenditionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A 1080p rendition of a ~24-minute video on 2 vCPUs takes roughly 15–25 minutes.
     * Must be lower than retry_after configured for the database queue (config/queue.php).
     */
    public int $timeout = 10800;

    public function __construct(
        protected Video $video,
        protected VideoRendition $rendition,
        protected string $diskName,
    ) {}

    public function handle(HlsEncoder $encoder, HlsStorage $storage): void
    {
        $workDir = "tmp/encode/{$this->rendition->id}-".Str::random(8);

        try {
            $streamInf = $encoder->encodeRendition($this->video->original_path, $workDir, $this->rendition);
            $storage->publishDirectory($workDir, $this->diskName, $this->rendition->hls_dir);

            $this->rendition->update(['stream_inf' => $streamInf, 'encoded_at' => now()]);
        } finally {
            Storage::disk('local')->deleteDirectory($workDir);
        }
    }
}
