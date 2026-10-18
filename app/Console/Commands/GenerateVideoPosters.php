<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Video\HlsEncoder;
use App\Video\HlsPlaylist;
use App\Video\PosterGenerator;
use App\Video\PosterVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generate video posters for READY videos directly from the encrypted HLS (since original video
 * files are deleted after encoding and cannot be recaptured): extract the segment around the 10% mark
 * of the highest rendition, decrypt using that rendition's key, and capture the frame. The poster
 * is encrypted before saving (PosterVault).
 */
class GenerateVideoPosters extends Command
{
    protected $signature = 'videos:posters {ids?* : Only these video IDs} {--force : Regenerate even if video already has a poster}';

    protected $description = 'Generate posters for processed videos that do not have one';

    public function handle(PosterGenerator $posters): int
    {
        $videos = Video::query()
            ->where('status', 'ready')
            ->whereNotNull('hls_playlist_path')
            ->whereHas('renditions')
            ->when($this->argument('ids'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->unless($this->option('force'), fn ($q) => $q->whereNull('poster_path'))
            ->get();

        $failed = 0;

        foreach ($videos as $video) {
            try {
                $this->generate($posters, $video);
                $this->info("#{$video->id} {$video->title}: OK");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("#{$video->id} {$video->title}: {$e->getMessage()}");
            }
        }

        $this->line("Processed {$videos->count()} videos, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function generate(PosterGenerator $posters, Video $video): void
    {
        $disk = Storage::disk($video->hls_disk ?: 'local');
        $dir = $video->hlsPrefix();
        $rendition = $video->renditions()->where('hls_dir', $dir)->orderByDesc('height')->firstOrFail();

        $segments = HlsPlaylist::segments($disk->get("{$dir}/{$rendition->playlist_name}"));
        throw_if(! $segments, \RuntimeException::class, 'Playlist has no segments.');

        $target = PosterGenerator::seekFor(array_sum(array_column($segments, 'duration')));
        [$segment, $offset] = [$segments[0], 0.0];
        $start = 0.0;

        foreach ($segments as $candidate) {
            if ($start + $candidate['duration'] > $target) {
                [$segment, $offset] = [$candidate, $target - $start];
                break;
            }
            $start += $candidate['duration'];
        }

        $plain = openssl_decrypt($disk->get("{$dir}/{$segment['uri']}"), 'aes-128-cbc', $rendition->key(), OPENSSL_RAW_DATA, $segment['iv']);
        throw_if($plain === false, \RuntimeException::class, 'Failed to decrypt segment.');

        $tmpDir = sys_get_temp_dir().'/poster-'.$video->id.'-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700);

        try {
            file_put_contents("{$tmpDir}/segment.ts", $plain);
            $posters->generate("{$tmpDir}/segment.ts", "{$tmpDir}/poster.jpg", $offset);

            $posterPath = "{$dir}/".HlsEncoder::randomName(PosterVault::EXTENSION);
            $disk->put($posterPath, PosterVault::fromConfig()->encrypt(file_get_contents("{$tmpDir}/poster.jpg"), $dir));

            $old = $video->poster_path;
            $video->update(['poster_path' => $posterPath]);

            if ($old && $old !== $posterPath) {
                $disk->delete($old);
            }
        } finally {
            array_map('unlink', glob("{$tmpDir}/*"));
            rmdir($tmpDir);
        }
    }
}
