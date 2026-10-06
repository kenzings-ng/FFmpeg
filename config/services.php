<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'frontend' => [
        // Dùng để build link trong email xác thực/quên mật khẩu (Nuxt chạy
        // ở domain khác, không phải domain của API).
        'url' => env('FRONTEND_URL', env('APP_URL')),
    ],

    'passport' => [
        // Client public (không secret) dùng cho password grant. Chỉ dùng nội
        // bộ trong App\Services\PassportTokenIssuer — client của /graphql
        // (web/mobile) không bao giờ cần biết tới client_id/OAuth.
        'password_client_id' => env('PASSPORT_PASSWORD_CLIENT_ID'),
    ],

];
