<?php

declare(strict_types=1);

namespace App\Video;

use Illuminate\Support\Facades\Storage;

/**
 * Chép file HLS từ thư mục làm việc (disk 'local') sang disk lưu trữ ('r2'
 * hoặc 'local'). Mọi tên file đã ngẫu nhiên, nên content type đoán theo đuôi.
 */
final class HlsStorage
{
    private const CONTENT_TYPES = [
        'm3u8' => 'application/vnd.apple.mpegurl',
        'ts' => 'video/mp2t',
        PosterVault::EXTENSION => 'application/octet-stream',
    ];

    public static function disk(): string
    {
        return config('video.hls_disk') === 'r2' ? 'r2' : 'local';
    }

    public function publishDirectory(string $localDir, string $diskName, string $targetDir): void
    {
        $local = Storage::disk('local');

        foreach ($local->files($localDir) as $path) {
            $target = "{$targetDir}/".basename($path);

            if ($diskName === 'local') {
                $local->move($path, $target);

                continue;
            }

            $stream = $local->readStream($path);

            try {
                Storage::disk($diskName)->writeStream($target, $stream, [
                    'ContentType' => self::CONTENT_TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
