<?php

namespace Tests\Unit;

use App\Video\StreamToken;
use PHPUnit\Framework\TestCase;

class StreamTokenTest extends TestCase
{
    private const SECRET = 'test-secret-test-secret-test-secret-1234';

    /** Stream token như Worker cấp (cloudflare/video-cdn/src/token.js). */
    private function streamToken(string $prefix, int $exp, string $ipnet): string
    {
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $sig = $b64(hash_hmac('sha256', "stream\n{$prefix}\n{$exp}\n{$ipnet}", self::SECRET, true));

        return "{$exp}.{$b64($ipnet)}.{$sig}";
    }

    public function test_accepts_valid_stream_token_for_its_directory_only(): void
    {
        $tokens = new StreamToken(self::SECRET);
        $token = $this->streamToken('hls/abc', time() + 60, '203.0.113.0/24');

        $this->assertTrue($tokens->verifyStream($token, 'hls/abc'));
        $this->assertFalse($tokens->verifyStream($token, 'hls/other'));
    }

    public function test_rejects_expired_or_tampered_tokens(): void
    {
        $tokens = new StreamToken(self::SECRET);

        $this->assertFalse($tokens->verifyStream($this->streamToken('hls/abc', time() - 1, ''), 'hls/abc'));
        $this->assertFalse($tokens->verifyStream($this->streamToken('hls/abc', time() + 60, '').'x', 'hls/abc'));
        $this->assertFalse($tokens->verifyStream('garbage', 'hls/abc'));
    }

    public function test_grant_has_expected_shape(): void
    {
        $grant = (new StreamToken(self::SECRET))->grant('hls/abc', 120);

        $this->assertMatchesRegularExpression('/^\d+\.[A-Za-z0-9_-]{43}$/', $grant['grant']);
        $this->assertEqualsWithDelta(time() + 120, $grant['expires_at'], 2);
    }

    public function test_poster_signature_is_bound_to_path_and_stable_within_the_hour(): void
    {
        $tokens = new StreamToken(self::SECRET);
        $signed = $tokens->signPoster('hls/abc/x.img');

        $this->assertSame($signed, $tokens->signPoster('hls/abc/x.img'));
        $this->assertNotSame($signed['sig'], $tokens->signPoster('hls/abc/y.img')['sig']);
        $this->assertSame(0, $signed['exp'] % 3600);
        $this->assertGreaterThan(time() + 3600, $signed['exp']);
    }

    public function test_secret_must_be_long_enough(): void
    {
        $this->expectException(\RuntimeException::class);
        new StreamToken('short');
    }
}
