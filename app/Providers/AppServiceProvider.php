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
        // App này chỉ có một cổng API: /graphql. Đăng nhập/refresh đi qua
        // mutation login/refreshToken (App\Services\PassportTokenIssuer gọi
        // thẳng AccessTokenController, không qua HTTP), nên không cần
        // /oauth/token, /oauth/authorize... của Passport lộ ra ngoài nữa.
        // Phải gọi ở register(), trước khi PassportServiceProvider::boot()
        // kiểm tra cờ này để quyết định có đăng ký route hay không.
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::enablePasswordGrant();

        Passport::tokensExpireIn(CarbonInterval::hour());
        // Không remember me: hết phiên sau 1 ngày. Remember me: 30 ngày (RememberMeTokenLifetime).
        Passport::refreshTokensExpireIn(CarbonInterval::day());

        Passport::tokensCan([
            'remember' => 'Keep me signed in',
        ]);

        // Mặc định ResetPassword trỏ tới route 'password.reset' của chính
        // app Laravel này — nhưng form nhập mật khẩu mới là trang FE (Nuxt,
        // domain khác hẳn), không phải route của backend. Phải tự build URL
        // trỏ sang FE, FE đọc token/email từ query string rồi gọi mutation
        // resetPassword.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return rtrim(config('services.frontend.url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        // Khác với reset password, xác thực email không cần FE làm gì (chỉ
        // cần bấm link), nên vẫn dùng route 'verification.verify' mặc định
        // của Laravel — chỉ cần tự định nghĩa route đó (xem routes/web.php),
        // vì app này không dùng Breeze/Fortify nên route đó chưa tồn tại sẵn.

        // Nội dung mặc định của 2 notification này là tiếng Anh (và nói về
        // "application" chung chung) — đổi sang tiếng Việt, giọng văn khớp
        // với phần còn lại của app.
        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Xác thực địa chỉ email')
                ->greeting('Xin chào!')
                ->line('Bấm nút bên dưới để xác thực địa chỉ email cho tài khoản FFmpeg Stream của bạn.')
                ->action('Xác thực email', $url)
                ->line('Nếu bạn không tạo tài khoản này, có thể bỏ qua email này.');
        });

        ResetPassword::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Yêu cầu đặt lại mật khẩu')
                ->greeting('Xin chào!')
                ->line('Bạn (hoặc ai đó) vừa yêu cầu đặt lại mật khẩu cho tài khoản này.')
                ->action('Đặt lại mật khẩu', $url)
                ->line('Link này sẽ hết hạn sau '.config('auth.passwords.users.expire').' phút.')
                ->line('Nếu bạn không yêu cầu đặt lại mật khẩu, có thể bỏ qua email này.');
        });
    }
}
