<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phát video HLS đã mã hóa AES-128.
 *
 * File thật nằm trên disk 'local' (storage/app), không public, không bao giờ
 * đi qua nginx. Route này là cửa duy nhất để đọc file, và quyết định serve
 * hay không dựa trên videos.is_public / chữ ký (signed URL) của Video::hlsUrl().
 *
 * secret.key không bao giờ nằm trên disk ở dạng thô: được Crypt (APP_KEY) mã
 * hóa trong cột videos.encrypted_key, chỉ giải mã trong bộ nhớ khi trả response.
 */
class VideoStreamController extends Controller
{
    public function show(Request $request, Video $video, string $file): Response
    {
        $this->authorizeAccess($request, $video);

        if ($file === 'secret.key') {
            return $this->serveKey($video);
        }

        if (Str::endsWith($file, '.m3u8')) {
            return $this->servePlaylist($request, $video, $file);
        }

        if ($file === 'poster.jpg') {
            abort_unless($video->poster_path && ! $video->isOnR2(), 404);

            return Storage::disk('local')->response($video->poster_path, null, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=86400',
            ]);
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

    private function serveKey(Video $video): Response
    {
        abort_unless($video->encrypted_key, 404);

        return response(Crypt::decryptString($video->encrypted_key), 200, [
            'Content-Type' => 'application/octet-stream',
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
