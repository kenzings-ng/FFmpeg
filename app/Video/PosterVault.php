<?php

declare(strict_types=1);

namespace App\Video;

use RuntimeException;

/**
 * Encrypt video posters before storage (AES-256-GCM), ensuring unauthorized access
 * to storage (R2) does not reveal video frames. The key is derived from VIDEO_STREAM_SECRET
 * + HLS directory path, and never stored on storage. Format MUST match
 * cloudflare/video-cdn/src/poster.js:
 *
 *   key  = HMAC-SHA256(secret, "poster\n{hls_dir}")   (32 bytes)
 *   file = iv (12 bytes) || ciphertext || tag (16 bytes)
 */
final class PosterVault
{
    public const EXTENSION = 'img';

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('VIDEO_STREAM_SECRET must be at least 32 characters.');
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
            throw new RuntimeException('Failed to encrypt poster.');
        }

        return $iv.$ciphertext.$tag;
    }

    public function decrypt(string $payload, string $hlsDir): string
    {
        if (strlen($payload) < 28) {
            throw new RuntimeException('Poster payload corrupted.');
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
            throw new RuntimeException('Failed to decrypt poster.');
        }

        return $jpeg;
    }

    private function key(string $hlsDir): string
    {
        return hash_hmac('sha256', "poster\n{$hlsDir}", $this->secret, true);
    }
}
