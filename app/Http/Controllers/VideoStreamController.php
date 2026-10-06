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
 * Phát video HLS đã mã hóa AES-128 lưu trên disk 'local' (VIDEO_HLS_DISK=local,
 * thường dùng khi dev). Video trên R2 phát qua Worker, không qua đây.
 *
 * File thật nằm trong storage/app, không public, không bao giờ đi qua nginx.
 * Route này là cửa duy nhất để đọc file, và quyết định serve hay không dựa
 * trên videos.is_public / chữ ký (signed URL) của Video::hlsUrl().
 *
 * Khóa "{key_id}.key" của từng mức không bao giờ nằm trên disk: được Crypt
 * (APP_KEY) mã hóa trong video_renditions.encrypted_key, chỉ giải mã trong bộ
 * nhớ khi trả response. Ảnh bìa ("*.img") lưu mã hóa, giải mã khi trả.
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
     * Video public: luôn cho xem. Video private: bắt buộc URL có chữ ký hợp lệ
     * (được Video::hlsUrl() cấp, chỉ owner lấy được qua GraphQL).
     */
    private function authorizeAccess(Request $request, Video $video): void
    {
        if ($video->is_public) {
            return;
        }

        abort_unless($request->hasValidSignature(), 403, 'Link đã hết hạn hoặc không hợp lệ.');
    }

    private function serveKey(Video $video, string $keyId): Response
    {
        $rendition = $video->renditions()->where('key_id', $keyId)->firstOrFail();

        return response($rendition->key(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Ảnh bìa được lưu ở dạng mã hóa (PosterVault): giải mã rồi trả JPEG. */
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

        // Mọi URL (key, segment, sub-playlist) được nhúng lại trong manifest đều
        // hết hạn cùng lúc với chính URL playlist mà client vừa gọi.
        //
        // Lưu ý: URL::temporarySignedRoute() nếu nhận một int sẽ hiểu đó là "số
        // giây kể từ bây giờ" (xem availableAt() trong Illuminate\Support\InteractsWithTime),
        // KHÔNG phải một mốc thời gian tuyệt đối. 'expires' trong query string lại
        // là unix timestamp tuyệt đối, nên bắt buộc phải bọc qua Carbon trước khi
        // truyền vào, nếu không hạn dùng sẽ bị tính nhầm thành hàng chục năm.
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
     * {$file} chỉ khớp regex [A-Za-z0-9_.-]+ ở route (không có dấu '/'), nên
     * không thể escape ra khỏi thư mục hls của chính video này.
     */
    private function resolvePath(Video $video, string $file): string
    {
        return dirname($video->hls_playlist_path).'/'.$file;
    }
}
