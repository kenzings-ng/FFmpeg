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

        // Entire /graphql endpoint
        RateLimiter::for('graphql', function (Request $request) {
            return Limit::perMinute(60)->by($request->user('api')?->id ?: $request->ip());
        });

        // POST /oauth/token (login + refresh): brute force protection
        RateLimiter::for('oauth-token', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // uploadVideo / segmentVideo: resource-heavy ffmpeg tasks
        RateLimiter::for('graphql-video', function (Request $request) {
            return Limit::perHour(20)->by($request->user('api')?->id ?: $request->ip());
        });

        // HLS streaming: each playback can generate dozens of requests (segments + keys +
        // sub-playlists), so this limit is significantly higher than other APIs.
        RateLimiter::for('video-stream', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        // Username availability check while typing (debounced on frontend).
        RateLimiter::for('username-check', function (Request $request) {
            return Limit::perMinute(30)->by($request->user('api')?->id ?: $request->ip());
        });

        // Post comment / reply: anti-spam.
        RateLimiter::for('comment', function (Request $request) {
            return Limit::perMinute(10)->by($request->user('api')?->id ?: $request->ip());
        });

        // AES key via Worker: requests originate from Cloudflare IPs (shared across all viewers),
        // so rate limit by stream token. Each playback session only requires a few keys.
        RateLimiter::for('video-key', function (Request $request) {
            return Limit::perMinute(60)->by('key:'.sha1((string) $request->query('token')));
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
