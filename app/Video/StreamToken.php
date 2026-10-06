<?php

declare(strict_types=1);

namespace App\Video;

use RuntimeException;

/**
 * Ký / kiểm tra token phát video lưu trên R2. Định dạng PHẢI khớp với
 * cloudflare/video-cdn/src/token.js:
 *
 *   grant  = "{exp}.{sig}"          sig = HMAC("grant\n{prefix}\n{exp}")
 *   stream = "{exp}.{net}.{sig}"    sig = HMAC("stream\n{prefix}\n{exp}\n{ipnet}")
 *   ảnh bìa = "{path}?exp={exp}&sig={sig}"   sig = HMAC("poster\n{path}\n{exp}")
 *
 * - prefix: thư mục HLS của video trên R2, vd. "hls/12-AbC…" (không có '/' ở hai đầu).
 * - ipnet:  dải IP Worker gắn vào token, vd. "1.2.3.0/24"; rỗng nếu tắt gắn IP.
 *           net = base64url(ipnet).
 * - sig:    base64url(HMAC-SHA256(secret, message)), không padding.
 *
 * Laravel chỉ CẤP grant (người có quyền xem) và KIỂM TRA stream token khi trả
 * khóa AES. Việc đổi grant -> stream token và kiểm tra IP là của Worker, vì
 * chỉ Worker thấy đúng IP mà các request segment sẽ dùng.
 */
final class StreamToken
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('VIDEO_STREAM_SECRET phải dài ít nhất 32 ký tự.');
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('video.stream_secret'));
    }

    /**
     * @return array{grant: string, expires_at: int}
     */
    public function grant(string $prefix, int $ttlSeconds): array
    {
        $exp = time() + $ttlSeconds;

        return [
            'grant' => $exp.'.'.$this->sign('grant', $prefix, (string) $exp),
            'expires_at' => $exp,
        ];
    }

    /**
     * URL ảnh bìa có chữ ký: thẻ <img> không gắn được stream token, nên ảnh bìa
     * dùng URL ký riêng (không gắn IP vì danh sách video có thể mở lại sau khi
     * đổi mạng). Chỉ người xem được video mới nhận được URL này qua GraphQL;
     * video bị xóa thì URL cũ tự hết hạn.
     *
     * Hạn làm tròn theo giờ (còn 1–2 giờ) để URL giữ nguyên trong cùng một giờ
     * và trình duyệt dùng lại được ảnh đã tải.
     *
     * @param  string  $path  Đường dẫn ảnh bìa trên R2, vd. "hls/AbC…/XyZ….img".
     * @return array{exp: int, sig: string}
     */
    public function signPoster(string $path): array
    {
        $exp = (intdiv(time(), 3600) + 2) * 3600;

        return ['exp' => $exp, 'sig' => $this->sign('poster', $path, (string) $exp)];
    }

    /**
     * Chỉ kiểm tra chữ ký và hạn dùng, không kiểm tra IP (xem ghi chú ở đầu class).
     */
    public function verifyStream(string $token, string $prefix): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || (int) $parts[0] < time()) {
            return false;
        }

        [$exp, $net, $sig] = $parts;

        $ipnet = self::base64UrlDecode($net);

        if ($ipnet === null) {
            return false;
        }

        return hash_equals($this->sign('stream', $prefix, $exp, $ipnet), $sig);
    }

    private function sign(string ...$parts): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', implode("\n", $parts), $this->secret, true));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
