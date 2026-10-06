<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nơi lưu HLS sau khi encode
    |--------------------------------------------------------------------------
    |
    | 'local': như cũ, HLS nằm ở storage/app và được phát qua
    |          VideoStreamController (PHP đọc từng segment).
    | 'r2':    encode ra thư mục tạm trên local rồi upload lên Cloudflare R2;
    |          người xem tải segment thẳng từ Worker trên cdn_url (xem
    |          cloudflare/video-cdn), PHP chỉ còn cấp grant và trả khóa AES.
    |
    | Chỉ ảnh hưởng video encode SAU khi đổi: mỗi video tự nhớ disk của nó ở
    | cột videos.hls_disk, nên video cũ trên local vẫn phát bình thường.
    |
    */

    'hls_disk' => env('VIDEO_HLS_DISK', 'local'),

    // Origin của Worker phát video, vd. https://cdn.example.com
    'cdn_url' => rtrim((string) env('VIDEO_CDN_URL', ''), '/'),

    /*
    |--------------------------------------------------------------------------
    | Token phát video (chỉ dùng khi hls_disk = r2)
    |--------------------------------------------------------------------------
    |
    | Laravel ký "grant" ngắn hạn (HMAC-SHA256, không gắn IP) cho người có quyền
    | xem; player đổi grant lấy "stream token" tại Worker, Worker gắn token với
    | dải IP mà chính nó thấy. Secret phải GIỐNG HỆT secret STREAM_SECRET của
    | Worker (wrangler secret put STREAM_SECRET).
    |
    */

    'stream_secret' => env('VIDEO_STREAM_SECRET'),

    'grant_ttl' => (int) env('VIDEO_GRANT_TTL', 120),

    // Origin của FE được phép lấy khóa AES. Rỗng = không kiểm tra Origin.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VIDEO_ALLOWED_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Encode
    |--------------------------------------------------------------------------
    |
    | CRF (chất lượng cố định) + trần bitrate thay cho bitrate cố định: cảnh
    | tĩnh / mảng màu phẳng (rất nhiều trong anime) tốn ít dung lượng hơn hẳn,
    | còn maxrate/bufsize giữ cho segment không vọt bitrate làm player giật.
    |
    | Chỉ encode các mức có chiều cao <= video gốc (không upscale). Video gốc
    | thấp hơn mức nhỏ nhất thì encode đúng 1 mức ở chiều cao gốc.
    |
    */

    'renditions' => [
        ['height' => 480, 'maxrate' => 1400],
        ['height' => 720, 'maxrate' => 2800],
        ['height' => 1080, 'maxrate' => 5000],
    ],

    'crf' => (int) env('VIDEO_CRF', 22),

    // Server 2 vCPU: 'veryfast' để một tập ~24 phút encode xong trong giới
    // hạn timeout. Máy mạnh hơn có thể đổi sang 'medium'/'slow' cho file nhỏ hơn.
    'x264_preset' => env('VIDEO_X264_PRESET', 'veryfast'),

    // 'animation' cho anime/hoạt hình; để rỗng với video quay thật.
    'x264_tune' => env('VIDEO_X264_TUNE', 'animation'),

    'audio_kbps' => (int) env('VIDEO_AUDIO_KBPS', 128),

    'segment_length' => 6,

];
