<?php

declare(strict_types=1);

namespace App\Video;

use RuntimeException;

/**
 * Sign and verify stream tokens for videos stored on R2. The format MUST match
 * cloudflare/video-cdn/src/token.js:
 *
 *   grant  = "{exp}.{sig}"          sig = HMAC("grant\n{prefix}\n{exp}")
 *   stream = "{exp}.{net}.{sig}"    sig = HMAC("stream\n{prefix}\n{exp}\n{ipnet}")
 *   poster = "{path}?exp={exp}&sig={sig}"   sig = HMAC("poster\n{path}\n{exp}")
 *
 * - prefix: HLS folder path on R2, e.g. "hls/12-AbC…" (without leading/trailing slashes).
 * - ipnet:  IP subnet bound by Worker to the token, e.g. "1.2.3.0/24"; empty if IP binding disabled.
 *           net = base64url(ipnet).
 * - sig:    base64url(HMAC-SHA256(secret, message)), unpadded.
 *
 * Laravel only ISSUES grants (authorized viewers) and VERIFIES stream tokens when serving
 * AES keys. Exchanging grants for stream tokens and validating IP subnets is done by the Cloudflare Worker,
 * as only the Worker sees the client IP used for segment requests.
 */
final class StreamToken
{
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
     * Signed poster URL: <img> tags cannot pass Authorization headers or custom stream tokens,
     * so posters use individually signed URLs (without IP binding so video listings persist across
     * network switches). Only authorized viewers receive this URL via GraphQL;
     * if the video is deleted, previous URLs expire automatically.
     *
     * Expiration is rounded to the hour (valid for 1–2 hours) so the URL remains stable within
     * the hour, allowing browser caching.
     *
     * @param  string  $path  Poster file path on R2, e.g. "hls/AbC…/XyZ….img".
     * @return array{exp: int, sig: string}
     */
    public function signPoster(string $path): array
    {
        $exp = (intdiv(time(), 3600) + 2) * 3600;

        return ['exp' => $exp, 'sig' => $this->sign('poster', $path, (string) $exp)];
    }

    /**
     * Only verifies signature and expiration, does not verify IP (see notes at top of class).
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
