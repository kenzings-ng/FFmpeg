<?php

return [
    'ffmpeg' => [
        'ffmpeg.binaries'  => env('FFMPEG_BIN','/usr/bin/ffmpeg'), // Đảm bảo đường dẫn chính xác
        // 'ffmpeg.threads' từng ở đây không có tác dụng: php-ffmpeg/php-ffmpeg
        // không đọc key này (không có chỗ nào trong AbstractBinary/FFMpegDriver
        // tiêu thụ nó), nên server luôn chạy với số thread mặc định của chính
        // libx264 bất kể giá trị đặt ở đây. Tham số encode (preset, CRF…)
        // nằm ở config/video.php, áp dụng trong App\Video\HlsEncoder.
    ],
];
