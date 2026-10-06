<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // videos/*: hls.js/trình phát HLS tải playlist + segment .ts bằng
    // fetch/XHR (không phải chỉ gắn thẻ <video src>), nên vẫn cần CORS dù
    // FE chạy ở domain khác. Không ảnh hưởng bảo mật: quyền xem video vẫn
    // do VideoStreamController tự kiểm tra (is_public hoặc chữ ký URL),
    // CORS chỉ quyết định JS có đọc được response hay không, không phải ai
    // được phép tải.
    'paths' => ['api/*', 'graphql', 'sanctum/csrf-cookie', 'storage/*', 'videos/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
