<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Video\StreamToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trả khóa AES-128 cho video lưu trên R2. Segment nằm trên CDN, còn khóa thì
 * không bao giờ rời khỏi DB (cột videos.encrypted_key, đã Crypt): ai tải được
 * file .ts mà không có stream token hợp lệ thì cũng không giải mã được.
 *
 * Video lưu trên local vẫn lấy khóa qua VideoStreamController như cũ.
 */
class VideoKeyController extends Controller
{
    public function show(Request $request, Video $video): Response
    {
        abort_unless($video->isOnR2() && $video->encrypted_key, 404);

        $allowedOrigins = config('video.allowed_origins');

        // hls.js tải khóa bằng XHR cross-origin nên trình duyệt luôn gửi
        // Origin; Safari phát HLS native thì không, nhưng có Referer. Hai
        // header này giả được (curl), chỉ chặn trang khác nhúng player.
        abort_if($allowedOrigins && ! in_array($this->requestOrigin($request), $allowedOrigins, true), 403);

        abort_unless(
            StreamToken::fromConfig()->verifyStream((string) $request->query('token'), $video->hlsPrefix()),
            403,
            'Token hết hạn hoặc không hợp lệ.',
        );

        return response(Crypt::decryptString($video->encrypted_key), 200, [
            'Content-Type' => 'application/octet-stream',
            // Không để proxy/CDN nào cache khóa.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function requestOrigin(Request $request): ?string
    {
        if ($origin = $request->headers->get('Origin')) {
            return $origin;
        }

        $referer = parse_url((string) $request->headers->get('Referer'));

        if (! isset($referer['scheme'], $referer['host'])) {
            return null;
        }

        return "{$referer['scheme']}://{$referer['host']}".(isset($referer['port']) ? ":{$referer['port']}" : '');
    }
}
