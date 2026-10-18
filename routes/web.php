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

// Verification email links (Illuminate\Auth\Notifications\VerifyEmail) point
// to the 'verification.verify' route by Laravel's default convention.
// 'signed' validates the signature + expiration (auth.verification.expire, default 60
// minutes) before reaching the controller.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
