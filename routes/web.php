<?php

use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\VideoControler;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Route::get('/', [VideoControler::class, 'index']);

// Link trong email xác thực (Illuminate\Auth\Notifications\VerifyEmail) trỏ
// tới route tên 'verification.verify' theo quy ước mặc định của Laravel.
// 'signed' kiểm tra chữ ký + hạn dùng (auth.verification.expire, mặc định 60
// phút) trước khi vào tới controller.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');