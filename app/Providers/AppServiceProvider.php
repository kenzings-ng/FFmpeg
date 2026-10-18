<?php

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // This app exposes only one API gateway: /graphql. Authentication and token refresh
        // go through mutations login/refreshToken (App\Services\PassportTokenIssuer calls
        // AccessTokenController directly, bypassing HTTP), so Passport's default routes
        // (/oauth/token, /oauth/authorize...) do not need to be exposed.
        // Must be called in register(), before PassportServiceProvider::boot()
        // checks this flag to decide whether to register routes.
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::enablePasswordGrant();

        Passport::tokensExpireIn(CarbonInterval::hour());
        // Without remember me: session expires after 1 day. Remember me: 30 days (RememberMeTokenLifetime).
        Passport::refreshTokensExpireIn(CarbonInterval::day());

        Passport::tokensCan([
            'remember' => 'Keep me signed in',
        ]);

        // By default, ResetPassword points to the application's own 'password.reset' route —
        // but the password reset form is hosted on the frontend (Nuxt, on a distinct domain),
        // not a backend route. Build a URL targeting the frontend, where the frontend reads
        // token/email from the query string and triggers the resetPassword mutation.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return rtrim(config('services.frontend.url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        // Unlike reset password, email verification requires no frontend handling (user only
        // clicks the link), so it uses Laravel's default 'verification.verify' route — defined
        // explicitly in routes/web.php because this app does not use Breeze/Fortify.
        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verify Email Address')
                ->greeting('Hello!')
                ->line('Please click the button below to verify your email address for your FFmpeg Stream account.')
                ->action('Verify Email', $url)
                ->line('If you did not create an account, no further action is required.');
        });

        ResetPassword::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Reset Password Notification')
                ->greeting('Hello!')
                ->line('You are receiving this email because we received a password reset request for your account.')
                ->action('Reset Password', $url)
                ->line('This password reset link will expire in '.config('auth.passwords.users.expire').' minutes.')
                ->line('If you did not request a password reset, no further action is required.');
        });
    }
}
