<?php

namespace Tests\Unit;

use App\Video\PosterVault;
use PHPUnit\Framework\TestCase;

class PosterVaultTest extends TestCase
{
    private const SECRET = 'test-secret-test-secret-test-secret-1234';

    public function test_encrypts_and_decrypts_round_trip(): void
    {
        $vault = new PosterVault(self::SECRET);
        $jpeg = random_bytes(2048);

        $stored = $vault->encrypt($jpeg, 'hls/abc');

        $this->assertStringNotContainsString(substr($jpeg, 0, 64), $stored);
        $this->assertSame($jpeg, $vault->decrypt($stored, 'hls/abc'));
    }

    public function test_key_is_bound_to_the_hls_directory(): void
    {
        $vault = new PosterVault(self::SECRET);
        $stored = $vault->encrypt('jpeg', 'hls/abc');

        $this->expectException(\RuntimeException::class);
        $vault->decrypt($stored, 'hls/other');
    }

    public function test_rejects_tampered_payload(): void
    {
        $vault = new PosterVault(self::SECRET);
        $stored = $vault->encrypt('jpeg', 'hls/abc');
        $stored[14] = chr(ord($stored[14]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $vault->decrypt($stored, 'hls/abc');
    }
}
