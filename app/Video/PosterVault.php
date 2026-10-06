<?php

declare(strict_types=1);

namespace App\Video;

use RuntimeException;

/**
 * Mã hóa ảnh bìa trước khi lưu (AES-256-GCM), để ai vào được storage (R2) cũng
 * không xem được khung hình của video. Khóa sinh từ VIDEO_STREAM_SECRET + thư
 * mục HLS, không nằm trên storage. Định dạng PHẢI khớp với
 * cloudflare/video-cdn/src/poster.js:
 *
 *   key  = HMAC-SHA256(secret, "poster\n{hls_dir}")   (32 byte)
 *   file = iv (12 byte) || ciphertext || tag (16 byte)
 */
final class PosterVault
{
    public const EXTENSION = 'img';

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

    public function encrypt(string $jpeg, string $hlsDir): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($jpeg, 'aes-256-gcm', $this->key($hlsDir), OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Không mã hóa được ảnh bìa.');
        }

        return $iv.$ciphertext.$tag;
    }

    public function decrypt(string $payload, string $hlsDir): string
    {
        if (strlen($payload) < 28) {
            throw new RuntimeException('Ảnh bìa hỏng.');
        }

        $jpeg = openssl_decrypt(
            substr($payload, 12, -16),
            'aes-256-gcm',
            $this->key($hlsDir),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, -16),
        );

        if ($jpeg === false) {
            throw new RuntimeException('Không giải mã được ảnh bìa.');
        }

        return $jpeg;
    }

    private function key(string $hlsDir): string
    {
        return hash_hmac('sha256', "poster\n{$hlsDir}", $this->secret, true);
    }
}
