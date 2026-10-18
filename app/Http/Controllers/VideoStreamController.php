<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Video\PosterVault;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve AES-128 encrypted HLS video stored on 'local' disk (VIDEO_HLS_DISK=local,
 * typically used during local development). Videos on R2 stream via Worker, not through here.
 *
 * Real files reside in storage/app, not public, and never served directly by nginx.
 * This route is the sole gateway to read files, deciding whether to serve based on
 * videos.is_public / signature (signed URL) from Video::hlsUrl().
 *
 * The "{key_id}.key" key for each rendition is never stored on disk: it is encrypted
 * via Crypt (APP_KEY) in video_renditions.encrypted_key, decrypted only in-memory
 * when constructing responses. Posters ("*.img") are stored encrypted and decrypted on serve.
 */
class VideoStreamController extends Controller
{
    public function show(Request $request, Video $video, string $file): Response
    {
        $this->authorizeAccess($request, $video);

        abort_if($video->isOnR2(), 404);

        if (Str::endsWith($file, '.key')) {
            return $this->serveKey($video, Str::beforeLast($file, '.key'));
        }

        if (Str::endsWith($file, '.m3u8')) {
            return $this->servePlaylist($request, $video, $file);
        }

        if (Str::endsWith($file, '.'.PosterVault::EXTENSION)) {
            return $this->servePoster($video, $file);
        }

        abort_unless(Str::endsWith($file, '.ts'), 404);

        $path = $this->resolvePath($video, $file);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'video/mp2t',
        ]);
    }

    /**
     * Public video: always accessible. Private video: requires a valid signed URL
     * (issued by Video::hlsUrl(), obtainable only by the owner via GraphQL).
     */
    private function authorizeAccess(Request $request, Video $video): void
    {
        if ($video->is_public) {
            return;
        }

        abort_unless($request->hasValidSignature(), 403, 'Link has expired or is invalid.');
    }

    private function serveKey(Video $video, string $keyId): Response
    {
        $rendition = $video->renditions()->where('key_id', $keyId)->firstOrFail();

        return response($rendition->key(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Posters are stored encrypted (PosterVault): decrypt and return as JPEG. */
    private function servePoster(Video $video, string $file): Response
    {
        abort_unless($video->poster_path && basename($video->poster_path) === $file, 404);

        $jpeg = PosterVault::fromConfig()->decrypt(Storage::disk('local')->get($video->poster_path), $video->hlsPrefix());

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function servePlaylist(Request $request, Video $video, string $file): Response
    {
        $path = $this->resolvePath($video, $file);
        abort_unless(Storage::disk('local')->exists($path), 404);

        // All URLs (key, segment, sub-playlist) rewritten into the manifest expire
        // at the exact same timestamp as the current playlist URL called by the client.
        //
        // Note: URL::temporarySignedRoute() treats an int as "seconds from now"
        // (see availableAt() in Illuminate\Support\InteractsWithTime), NOT an absolute
        // timestamp. The 'expires' query parameter is an absolute Unix timestamp,
        // so it must be wrapped in a Carbon instance before passing in, otherwise
        // expiration would inadvertently be computed decades into the future.
        $expiresAt = CarbonImmutable::createFromTimestamp((int) $request->query('expires'));

        $resolveUrl = fn (string $relatedFile): string => $video->is_public
            ? route('videos.hls', ['video' => $video->id, 'file' => $relatedFile])
            : URL::temporarySignedRoute(
                'videos.hls',
                $expiresAt,
                ['video' => $video->id, 'file' => $relatedFile],
            );

        $playlist = FFMpeg::dynamicHLSPlaylist()
            ->fromDisk('local')
            ->open($path)
            ->setKeyUrlResolver($resolveUrl)
            ->setMediaUrlResolver($resolveUrl)
            ->setPlaylistUrlResolver($resolveUrl);

        return $playlist->toResponse($request);
    }

    /**
     * {$file} only matches the [A-Za-z0-9_.-]+ route regex (no '/' allowed),
     * preventing path traversal outside this video's HLS directory.
     */
    private function resolvePath(Video $video, string $file): string
    {
        return dirname($video->hls_playlist_path).'/'.$file;
    }
}
