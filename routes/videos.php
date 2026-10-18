<?php

use App\Http\Controllers\VideoKeyController;
use App\Http\Controllers\VideoStreamController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Video Streaming Routes
|--------------------------------------------------------------------------
|
| Serve AES-128 encrypted HLS video streams. Does not use 'web' group middleware
| to avoid unnecessary session/CSRF overhead for static file reading endpoints.
| Access authorization is checked in VideoStreamController (public / signed URL).
|
*/

Route::get('/videos/{video}/hls/{file}', [VideoStreamController::class, 'show'])
    ->where('file', '[A-Za-z0-9_.-]+')
    ->name('videos.hls');

// AES-128 key for each rendition level (videos stored on R2). The Cloudflare Worker calls
// this on behalf of viewers, passing the stream token. Requests originate from Cloudflare IPs,
// so rate limiting is token-based rather than IP-based (see RouteServiceProvider: 'video-key').
Route::get('/videos/keys/{keyId}', [VideoKeyController::class, 'show'])
    ->where('keyId', '[A-Za-z0-9]+')
    ->withoutMiddleware(ThrottleRequests::class.':video-stream')
    ->middleware(ThrottleRequests::class.':video-key')
    ->name('videos.key');
