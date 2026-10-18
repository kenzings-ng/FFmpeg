<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Video;
use App\Video\HlsStorage;
use Illuminate\Support\Facades\Storage;

final class DeleteVideo
{
    /**
     * @param  null  $_
     * @param  array{id: string}  $args
     */
    public function __invoke($_, array $args): bool
    {
        $video = Video::findOrFail($args['id']);

        // Delete the entire directory on disk (original file if still present, all encrypted HLS files)
        // before deleting the record, avoiding orphaned files.
        Storage::disk('local')->deleteDirectory("videos/{$video->id}");

        // The HLS directories (randomized names) from all encodings: the currently playing version
        // and any work-in-progress rendition directories (if any).
        $current = $video->hlsPrefix();

        if ($current) {
            Storage::disk($video->hls_disk ?: 'local')->deleteDirectory($current);
        }

        foreach ($video->renditions()->distinct()->pluck('hls_dir')->reject(fn ($dir) => $dir === $current) as $dir) {
            Storage::disk(HlsStorage::disk())->deleteDirectory($dir);
        }

        $video->delete();

        return true;
    }
}
