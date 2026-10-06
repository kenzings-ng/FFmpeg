<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Toàn bộ endpoint /graphql
        RateLimiter::for('graphql', function (Request $request) {
            return Limit::perMinute(60)->by($request->user('api')?->id ?: $request->ip());
        });

        // POST /oauth/token (login + refresh): chống dò mật khẩu
        RateLimiter::for('oauth-token', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // uploadVideo / segmentVideo: tác vụ ffmpeg tốn tài nguyên
        RateLimiter::for('graphql-video', function (Request $request) {
            return Limit::perHour(20)->by($request->user('api')?->id ?: $request->ip());
        });

        // Phát HLS: mỗi lần play có thể là hàng chục request (segment + key +
        // sub-playlist), nên giới hạn rộng hơn hẳn so với các API khác.
        RateLimiter::for('video-stream', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('videos')
                ->group(base_path('routes/videos.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
