<?php

declare(strict_types=1);

namespace App\Video;

/**
 * Đọc playlist HLS (VOD, AES-128) do HlsEncoder sinh ra.
 */
final class HlsPlaylist
{
    /**
     * Master playlist → các variant theo thứ tự trong file.
     *
     * @return array<int, array{stream_inf: string, uri: string}>
     */
    public static function variants(string $master): array
    {
        $variants = [];
        $streamInf = null;

        foreach (self::lines($master) as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $streamInf = $line;
            } elseif ($line !== '' && ! str_starts_with($line, '#') && $streamInf) {
                $variants[] = ['stream_inf' => $streamInf, 'uri' => $line];
                $streamInf = null;
            }
        }

        return $variants;
    }

    /**
     * Variant playlist → các segment kèm thời lượng và IV (16 byte) để giải mã.
     *
     * @return array<int, array{uri: string, duration: float, iv: string}>
     */
    public static function segments(string $variant): array
    {
        $segments = [];
        $duration = null;
        $iv = null;
        $sequence = 0;

        foreach (self::lines($variant) as $line) {
            if (preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)/', $line, $m)) {
                $sequence = (int) $m[1];
            } elseif (str_starts_with($line, '#EXT-X-KEY:')) {
                $iv = preg_match('/IV=0x([0-9a-fA-F]+)/', $line, $m)
                    ? hex2bin(str_pad($m[1], 32, '0', STR_PAD_LEFT))
                    : null;
            } elseif (preg_match('/^#EXTINF:([\d.]+)/', $line, $m)) {
                $duration = (float) $m[1];
            } elseif ($line !== '' && ! str_starts_with($line, '#') && $duration !== null) {
                // Không có IV trong #EXT-X-KEY: IV = số thứ tự segment (big-endian 128-bit).
                $segments[] = ['uri' => $line, 'duration' => $duration, 'iv' => $iv ?? str_pad(pack('J', $sequence), 16, "\0", STR_PAD_LEFT)];
                $duration = null;
                $sequence++;
            }
        }

        return $segments;
    }

    /**
     * @return array<int, string>
     */
    public static function lines(string $playlist): array
    {
        return array_map('trim', preg_split('/\r\n|\n|\r/', $playlist));
    }
}
