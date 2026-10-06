<?php

namespace Tests\Unit;

use App\Video\HlsPlaylist;
use PHPUnit\Framework\TestCase;

class HlsPlaylistTest extends TestCase
{
    public function test_reads_variants_from_master(): void
    {
        $variants = HlsPlaylist::variants("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=854x480\nA.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=2\nB.m3u8\n");

        $this->assertSame(['A.m3u8', 'B.m3u8'], array_column($variants, 'uri'));
        $this->assertSame('#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=854x480', $variants[0]['stream_inf']);
    }

    public function test_reads_segments_with_iv(): void
    {
        $segments = HlsPlaylist::segments(implode("\n", [
            '#EXTM3U',
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-KEY:METHOD=AES-128,URI="k.key",IV=0x0000000000000000000000000000abcd',
            '#EXTINF:6.0,',
            'a.ts',
            '#EXTINF:2.5,',
            'b.ts',
            '#EXT-X-ENDLIST',
        ]));

        $this->assertSame(['a.ts', 'b.ts'], array_column($segments, 'uri'));
        $this->assertSame([6.0, 2.5], array_column($segments, 'duration'));
        $this->assertSame('abcd', bin2hex(substr($segments[0]['iv'], -2)));
    }

    public function test_iv_defaults_to_media_sequence_number(): void
    {
        $segments = HlsPlaylist::segments("#EXT-X-MEDIA-SEQUENCE:5\n#EXT-X-KEY:METHOD=AES-128,URI=\"k.key\"\n#EXTINF:6.0,\na.ts\n#EXTINF:6.0,\nb.ts\n");

        $this->assertSame(str_repeat('00', 15).'05', bin2hex($segments[0]['iv']));
        $this->assertSame(str_repeat('00', 15).'06', bin2hex($segments[1]['iv']));
    }
}
