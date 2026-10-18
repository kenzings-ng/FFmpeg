<?php

return [
    'ffmpeg' => [
        'ffmpeg.binaries' => env('FFMPEG_BIN', '/usr/bin/ffmpeg'), // Ensure binary path is accurate
        // 'ffmpeg.threads' previously here had no effect: php-ffmpeg/php-ffmpeg
        // does not read this key (nowhere in AbstractBinary/FFMpegDriver is it consumed),
        // so the server always runs with libx264's own default thread count regardless
        // of values set here. Encoding parameters (preset, CRF…) live in config/video.php,
        // applied in App\Video\HlsEncoder.
    ],
];
