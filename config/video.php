<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HLS Storage Destination
    |--------------------------------------------------------------------------
    |
    | 'local': default, HLS files reside in storage/app and stream through
    |          VideoStreamController (PHP streams each segment).
    | 'r2':    encoded to a local temporary directory then uploaded to Cloudflare R2;
    |          viewers download segments directly from Worker at cdn_url (see
    |          cloudflare/video-cdn), while PHP only issues grants and returns AES keys.
    |
    | Only affects videos encoded AFTER changing: each video remembers its storage
    | disk in videos.hls_disk, so existing videos on local storage continue to play.
    |
    */

    'hls_disk' => env('VIDEO_HLS_DISK', 'local'),

    // Origin of the streaming Worker, e.g. https://cdn.example.com
    'cdn_url' => rtrim((string) env('VIDEO_CDN_URL', ''), '/'),

    /*
    |--------------------------------------------------------------------------
    | Video Stream Token (only used when hls_disk = r2)
    |--------------------------------------------------------------------------
    |
    | Laravel signs short-lived "grants" (HMAC-SHA256, unbound to IP) for authorized
    | viewers; the player exchanges the grant for a "stream token" at the Worker,
    | where the Worker binds the token to the client's observed IP subnet. The secret
    | MUST MATCH the Worker's STREAM_SECRET (wrangler secret put STREAM_SECRET).
    |
    */

    'stream_secret' => env('VIDEO_STREAM_SECRET'),

    'grant_ttl' => (int) env('VIDEO_GRANT_TTL', 120),

    // Allowed frontend Origin for AES key requests. Empty = skip Origin check.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VIDEO_ALLOWED_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Encode
    |--------------------------------------------------------------------------
    |
    | CRF (constant rate factor) + bitrate caps instead of static bitrates: static scenes
    | and flat colors (prevalent in anime) consume significantly less bandwidth,
    | while maxrate/bufsize prevent segment bitrate spikes that cause playback buffering.
    |
    | Only encodes renditions with height <= original video (no upscaling). If the original
    | video resolution is lower than the lowest rendition, exactly one rendition at native height is encoded.
    |
    */

    'renditions' => [
        ['height' => 480, 'maxrate' => 1400],
        ['height' => 720, 'maxrate' => 2800],
        ['height' => 1080, 'maxrate' => 5000],
    ],

    'crf' => (int) env('VIDEO_CRF', 22),

    // 2 vCPU server: 'veryfast' ensures a ~24min episode encodes within the job
    // timeout limit. Higher performance machines can use 'medium'/'slow' for smaller file sizes.
    'x264_preset' => env('VIDEO_X264_PRESET', 'veryfast'),

    // 'animation' for anime/cartoons; leave empty for live-action.
    'x264_tune' => env('VIDEO_X264_TUNE', 'animation'),

    'audio_kbps' => (int) env('VIDEO_AUDIO_KBPS', 128),

    'segment_length' => 6,

];
