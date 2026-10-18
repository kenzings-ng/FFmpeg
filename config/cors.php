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

    // videos/*: hls.js and HLS players load playlists and .ts segments via
    // fetch/XHR (rather than just setting <video src>), so CORS headers are required
    // when frontend runs on a different domain. Does not impact security: video viewing
    // permissions are verified by VideoStreamController (is_public or signed URL);
    // CORS only dictates whether browser JavaScript can read the response, not who can fetch it.
    'paths' => ['api/*', 'graphql', 'sanctum/csrf-cookie', 'storage/*', 'videos/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
