<?php

use App\Http\Controllers\VideoKeyController;
use App\Http\Controllers\VideoStreamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Video Streaming Routes
|--------------------------------------------------------------------------
|
| Phát HLS (đã mã hóa AES-128) cho từng video. Không dùng nhóm 'web' để
| tránh overhead session/CSRF không cần thiết cho endpoint đọc file.
| Quyền truy cập được VideoStreamController tự kiểm tra (public / signed URL).
|
*/

Route::get('/videos/{video}/hls/{file}', [VideoStreamController::class, 'show'])
    ->where('file', '[A-Za-z0-9_.-]+')
    ->name('videos.hls');

// Khóa AES-128 của từng mức chất lượng (video trên R2). Worker gọi thay cho
// người xem, kèm stream token. Mọi request tới từ IP của Cloudflare nên giới
// hạn theo token thay vì theo IP (xem RouteServiceProvider: 'video-key').
Route::get('/videos/keys/{keyId}', [VideoKeyController::class, 'show'])
    ->where('keyId', '[A-Za-z0-9]+')
    ->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':video-stream')
    ->middleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':video-key')
    ->name('videos.key');
