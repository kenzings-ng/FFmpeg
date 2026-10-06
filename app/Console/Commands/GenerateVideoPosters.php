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
 * Tạo ảnh bìa cho video đã READY từ chính HLS đã mã hóa (video gốc bị xóa sau
 * khi encode nên không chụp lại từ file gốc được): lấy segment ở mốc ~10% của
 * mức cao nhất, giải mã bằng khóa của mức đó rồi chụp. Ảnh được mã hóa trước
 * khi lưu (PosterVault).
 */
class GenerateVideoPosters extends Command
{
    protected $signature = 'videos:posters {ids?* : Chỉ các video này} {--force : Tạo lại kể cả video đã có ảnh bìa}';

    protected $description = 'Tạo ảnh bìa cho video đã xử lý mà chưa có ảnh bìa';

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

        $this->line("Xong {$videos->count()} video, lỗi {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function generate(PosterGenerator $posters, Video $video): void
    {
        $disk = Storage::disk($video->hls_disk ?: 'local');
        $dir = $video->hlsPrefix();
        $rendition = $video->renditions()->where('hls_dir', $dir)->orderByDesc('height')->firstOrFail();

        $segments = HlsPlaylist::segments($disk->get("{$dir}/{$rendition->playlist_name}"));
        throw_if(! $segments, \RuntimeException::class, 'playlist không có segment');

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
        throw_if($plain === false, \RuntimeException::class, 'không giải mã được segment');

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
