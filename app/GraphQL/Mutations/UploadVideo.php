<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Jobs\SegmentVideoJob;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

final class UploadVideo
{
    /**
     * @param  null  $_
     * @param  array{title: string, file: UploadedFile, is_public?: bool}  $args
     */
    public function __invoke($_, array $args): Video
    {
        $user = Auth::guard('api')->user();

        /** @var UploadedFile $file */
        $file = $args['file'];

        // Video always belongs to a user and defaults to private; owner can toggle
        // public later via setVideoVisibility mutation if desired.
        $video = Video::create([
            'title' => $args['title'],
            'user_id' => $user->id,
            'is_public' => $args['is_public'] ?? false,
            // Must set explicitly: 'pending' is the DB migration default; Eloquent does
            // not reload defaults into PHP object after create() — if omitted, $video->status
            // is null in memory for this response, causing GraphQL non-null Video.status to crash.
            'status' => 'pending',
        ]);

        // Stored on 'local' disk (storage/app), non-public, never served directly by web server.
        $allowedExtensions = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'wmv', 'flv', 'ts'];
        $guessed = strtolower((string) $file->extension());
        $extension = in_array($guessed, $allowedExtensions, true) ? $guessed : 'mp4';
        $path = $file->storeAs("videos/{$video->id}", "original.{$extension}", 'local');

        $video->update(['original_path' => $path]);

        SegmentVideoJob::dispatch($video);

        return $video;
    }
}
