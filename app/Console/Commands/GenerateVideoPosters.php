<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Video\PosterGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Tạo ảnh bìa cho video đã READY từ chính HLS đã mã hóa (video gốc bị xóa sau
 * khi encode nên không chụp lại từ file gốc được): lấy segment ở mốc ~10% của
 * rendition cao nhất, giải mã AES-128 bằng khóa trong DB rồi chụp.
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
            ->whereNotNull('encrypted_key')
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
        $disk = Storage::disk($video->isOnR2() ? 'r2' : 'local');
        $dir = dirname($video->hls_playlist_path);

        $variants = $this->uris($disk->get($video->hls_playlist_path));
        throw_if(! $variants, \RuntimeException::class, 'master playlist không có variant');

        // Rendition cuối cùng = chất lượng cao nhất (encode từ thấp đến cao).
        [$segment, $offset, $ivHex] = $this->segmentAtTenPercent($disk->get("{$dir}/".end($variants)));

        $iv = hex2bin(str_pad($ivHex, 32, '0', STR_PAD_LEFT));
        $plain = openssl_decrypt($disk->get("{$dir}/{$segment}"), 'aes-128-cbc', Crypt::decryptString($video->encrypted_key), OPENSSL_RAW_DATA, $iv);
        throw_if($plain === false, \RuntimeException::class, 'không giải mã được segment');

        $tmpDir = sys_get_temp_dir().'/poster-'.$video->id.'-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700);

        try {
            file_put_contents("{$tmpDir}/segment.ts", $plain);
            $posters->generate("{$tmpDir}/segment.ts", "{$tmpDir}/".PosterGenerator::FILENAME, $offset);

            $posterPath = "{$dir}/".PosterGenerator::FILENAME;
            $disk->put($posterPath, file_get_contents("{$tmpDir}/".PosterGenerator::FILENAME), ['ContentType' => 'image/jpeg']);
            $video->update(['poster_path' => $posterPath]);
        } finally {
            array_map('unlink', glob("{$tmpDir}/*"));
            rmdir($tmpDir);
        }
    }

    /**
     * @return array{0: string, 1: float, 2: string} [segment, giây bên trong segment, IV hex]
     */
    private function segmentAtTenPercent(string $variantPlaylist): array
    {
        $segments = [];
        $duration = null;
        $iv = null;

        foreach (preg_split('/\r\n|\n|\r/', $variantPlaylist) as $line) {
            $line = trim($line);

            if (preg_match('/^#EXT-X-KEY:.*IV=0x([0-9a-fA-F]+)/', $line, $m)) {
                $iv = $m[1];
            } elseif (preg_match('/^#EXTINF:([\d.]+)/', $line, $m)) {
                $duration = (float) $m[1];
            } elseif ($line !== '' && ! str_starts_with($line, '#') && $duration !== null) {
                $segments[] = [$line, $duration, $iv];
                $duration = null;
            }
        }

        throw_if(! $segments || ! $segments[0][2], \RuntimeException::class, 'variant playlist không có segment mã hóa');

        $target = PosterGenerator::seekFor(array_sum(array_column($segments, 1)));
        $start = 0.0;

        foreach ($segments as [$name, $length, $segmentIv]) {
            if ($start + $length > $target) {
                return [$name, $target - $start, $segmentIv];
            }
            $start += $length;
        }

        return [$segments[0][0], 0.0, $segments[0][2]];
    }

    /**
     * @return array<int, string>
     */
    private function uris(string $playlist): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\n|\r/', $playlist)),
            fn ($line) => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }
}
