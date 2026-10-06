<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\VideoRendition;
use App\Video\HlsEncoder;
use App\Video\HlsPlaylist;
use App\Video\HlsStorage;
use App\Video\PosterVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;

/**
 * Chuyển video encode theo định dạng cũ (một khóa AES chung cho cả video, tên
 * file lộ độ phân giải / video id, ảnh bìa không mã hóa) sang định dạng mới
 * mà KHÔNG cần video gốc và không encode lại: giải mã từng segment rồi mã hóa
 * lại bằng khóa riêng của từng mức, đặt tên ngẫu nhiên, mã hóa ảnh bìa, ghi
 * vào thư mục mới trên disk hiện tại (config video.hls_disk), xong mới xóa bản cũ.
 */
class UpgradeVideoStorage extends Command
{
    protected $signature = 'videos:upgrade-storage {ids?* : Chỉ các video này}';

    protected $description = 'Chuyển video định dạng cũ sang định dạng lưu trữ riêng tư (khóa theo từng mức, tên ngẫu nhiên)';

    public function handle(HlsStorage $storage): int
    {
        $videos = Video::query()
            ->where('status', 'ready')
            ->whereNotNull('hls_playlist_path')
            ->whereNotNull('encrypted_key')
            ->whereDoesntHave('renditions')
            ->when($this->argument('ids'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        $failed = 0;

        foreach ($videos as $video) {
            try {
                $this->upgrade($storage, $video);
                $this->info("#{$video->id} {$video->title}: OK");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("#{$video->id} {$video->title}: {$e->getMessage()}");
            }
        }

        $this->line("Xong {$videos->count()} video, lỗi {$failed}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function upgrade(HlsStorage $storage, Video $video): void
    {
        $oldDiskName = $video->hls_disk ?: 'local';
        $oldDisk = Storage::disk($oldDiskName);
        $oldDir = dirname($video->hls_playlist_path);
        $oldKey = Crypt::decryptString($video->encrypted_key);

        $diskName = HlsStorage::disk();
        $newDir = 'hls/'.Str::random(32);
        $workDir = "tmp/upgrade/{$video->id}-".Str::random(8);
        $local = Storage::disk('local');

        try {
            $master = ['#EXTM3U'];

            foreach (HlsPlaylist::variants($oldDisk->get($video->hls_playlist_path)) as $variant) {
                $rendition = $this->reencryptVariant($oldDisk, $oldDir, $oldKey, $variant, $video, $newDir, $workDir);
                $master[] = $rendition->stream_inf;
                $master[] = $rendition->playlist_name;
            }

            throw_if(count($master) === 1, \RuntimeException::class, 'master playlist không có variant');

            $posterPath = null;

            if ($video->poster_path && $oldDisk->exists($video->poster_path)) {
                $posterPath = "{$newDir}/".HlsEncoder::randomName(PosterVault::EXTENSION);
                $local->put("{$workDir}/".basename($posterPath), PosterVault::fromConfig()->encrypt($oldDisk->get($video->poster_path), $newDir));
            }

            $masterPath = "{$newDir}/".HlsEncoder::randomName('m3u8');
            $local->put("{$workDir}/".basename($masterPath), implode("\n", $master)."\n");

            $storage->publishDirectory($workDir, $diskName, $newDir);

            $video->update([
                'hls_disk' => $diskName,
                'hls_playlist_path' => $masterPath,
                'poster_path' => $posterPath,
                'encrypted_key' => null,
            ]);
        } catch (\Throwable $e) {
            VideoRendition::where('video_id', $video->id)->where('hls_dir', $newDir)->delete();
            rescue(fn () => Storage::disk($diskName)->deleteDirectory($newDir), report: false);

            throw $e;
        } finally {
            $local->deleteDirectory($workDir);
        }

        $oldDisk->deleteDirectory($oldDir);
    }

    /**
     * @param  array{stream_inf: string, uri: string}  $variant
     */
    private function reencryptVariant($oldDisk, string $oldDir, string $oldKey, array $variant, Video $video, string $newDir, string $workDir): VideoRendition
    {
        $local = Storage::disk('local');
        preg_match('/RESOLUTION=(\d+)x(\d+)/', $variant['stream_inf'], $resolution);

        $rendition = VideoRendition::create([
            'video_id' => $video->id,
            'hls_dir' => $newDir,
            'height' => (int) ($resolution[2] ?? 0),
            'playlist_name' => HlsEncoder::randomName('m3u8'),
            'key_id' => Str::random(40),
            'encrypted_key' => Crypt::encryptString(HLSExporter::generateEncryptionKey()),
        ]);

        $oldVariant = $oldDisk->get("{$oldDir}/{$variant['uri']}");
        $segments = HlsPlaylist::segments($oldVariant);
        $newKey = $rendition->key();
        $newIv = random_bytes(16);
        $byUri = collect($segments)->keyBy('uri');

        $lines = [];
        $keyWritten = false;

        foreach (HlsPlaylist::lines($oldVariant) as $line) {
            if (str_starts_with($line, '#EXT-X-KEY:')) {
                if (! $keyWritten) {
                    $lines[] = '#EXT-X-KEY:METHOD=AES-128,URI="'.$rendition->keyFilename().'",IV=0x'.bin2hex($newIv);
                    $keyWritten = true;
                }

                continue;
            }

            if ($line === '' || str_starts_with($line, '#')) {
                $lines[] = $line;

                continue;
            }

            $segment = $byUri->get($line);
            $plain = openssl_decrypt($oldDisk->get("{$oldDir}/{$line}"), 'aes-128-cbc', $oldKey, OPENSSL_RAW_DATA, $segment['iv']);
            throw_if($plain === false, \RuntimeException::class, "không giải mã được {$line}");

            $name = HlsEncoder::randomName('ts');
            $local->put("{$workDir}/{$name}", openssl_encrypt($plain, 'aes-128-cbc', $newKey, OPENSSL_RAW_DATA, $newIv));
            $lines[] = $name;
        }

        $local->put("{$workDir}/{$rendition->playlist_name}", rtrim(implode("\n", $lines))."\n");
        $rendition->update(['stream_inf' => $variant['stream_inf'], 'encoded_at' => now()]);

        return $rendition;
    }
}
