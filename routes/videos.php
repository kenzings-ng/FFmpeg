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

// Khóa AES-128 của video lưu trên R2 (segment phát từ CDN). Cần stream token.
Route::get('/videos/{video}/key', [VideoKeyController::class, 'show'])
    ->name('videos.key');
