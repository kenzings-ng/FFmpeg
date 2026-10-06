<?php

namespace App\Http\Controllers;

use App\Models\VideoRendition;
use App\Video\StreamToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trả khóa AES-128 của một mức chất lượng (video lưu trên R2).
 *
 * Playlist trên R2 chỉ ghi khóa bằng tên ngẫu nhiên "{key_id}.key" (tương đối),
 * nên trên storage không có URL API hay video id nào. Người xem tải khóa từ
 * Worker; Worker kiểm tra token + IP rồi chuyển request sang đây. Ở đây kiểm
 * tra lại chữ ký / hạn của stream token và khóa phải thuộc đúng thư mục HLS
 * mà token được cấp.
 *
 * Khóa không bao giờ rời khỏi DB (video_renditions.encrypted_key, đã Crypt):
 * ai tải được file .ts trên R2 mà không có token hợp lệ cũng không giải mã được.
 */
class VideoKeyController extends Controller
{
    public function show(Request $request, string $keyId): Response
    {
        $rendition = VideoRendition::with('video')->where('key_id', $keyId)->first();

        abort_unless($rendition && $rendition->video?->isOnR2(), 404);

        abort_unless(
            StreamToken::fromConfig()->verifyStream((string) $request->query('token'), $rendition->hls_dir),
            403,
            'Token hết hạn hoặc không hợp lệ.',
        );

        return response($rendition->key(), 200, [
            'Content-Type' => 'application/octet-stream',
            // Không để proxy/CDN nào cache khóa.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
