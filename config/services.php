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
        // Used to construct links in verification and password reset emails (Nuxt frontend
        // runs on a separate domain, not the API domain).
        'url' => env('FRONTEND_URL', env('APP_URL')),
    ],

    'passport' => [
        // Public client (no secret) used for the password grant. Used exclusively
        // within App\Services\PassportTokenIssuer — clients of /graphql (web/mobile)
        // never need to be aware of client_id or OAuth semantics.
        'password_client_id' => env('PASSPORT_PASSWORD_CLIENT_ID'),
    ],

];
