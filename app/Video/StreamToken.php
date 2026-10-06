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
