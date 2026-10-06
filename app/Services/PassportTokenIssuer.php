<?php

namespace App\Services;

use Carbon\CarbonInterval;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Exceptions\OAuthServerException;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Phát hành / làm mới access token mà KHÔNG đi qua route /oauth/token.
 *
 * App này chỉ có một cổng API duy nhất là /graphql (xem AppServiceProvider::register()
 * gọi Passport::ignoreRoutes()). Nhưng việc issue token vẫn cần đúng logic OAuth2 của
 * Passport (league/oauth2-server): kiểm tra client, hash mật khẩu, ký access token,
 * tạo/xoay refresh token... Thay vì chép lại logic đó, class này gọi thẳng
 * AccessTokenController::issueToken() như một lời gọi PHP bình thường — tự dựng một
 * PSR-7 request từ tham số GraphQL rồi đưa thẳng vào, không có request HTTP thật nào
 * được gửi đi cả.
 */
class PassportTokenIssuer
{
    /**
     * @param  array<string, mixed>  $parameters  grant_type, client_id, và (username+password) hoặc refresh_token
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(array $parameters, bool $rememberMe = false): array
    {
        if ($rememberMe) {
            // Scope "remember" được ký vào token nên khi refresh sau này,
            // league/oauth2-server sẽ từ chối nếu token gốc không có scope
            // này — bắt buộc client phải tự gửi lại remember_me mỗi lần
            // refresh nếu muốn giữ phiên dài.
            $parameters['scope'] = trim(($parameters['scope'] ?? '').' remember');

            // Phải set TRƯỚC khi AuthorizationServer được dựng (ngay dưới),
            // vì Passport đọc TTL của refresh token tại thời điểm đó.
            Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        }

        $psrFactory = new HttpFactory();
        $psrBridge = new PsrHttpFactory($psrFactory, $psrFactory, $psrFactory, $psrFactory);

        $psrRequest = $psrBridge->createRequest(
            LaravelRequest::create('/oauth/token', 'POST', $parameters),
        );

        try {
            $response = app(AccessTokenController::class)->issueToken($psrRequest, $psrFactory->createResponse());
        } catch (OAuthServerException $e) {
            // AccessTokenController không trả response 4xx như request HTTP
            // bình thường — nó throw thẳng exception này (bọc League's
            // OAuthServerException, xem Laravel\Passport\Http\Controllers\
            // HandlesOAuthErrors). Vì gọi controller trực tiếp (không qua
            // route), Laravel không có dịp tự render exception đó thành
            // response REST như khi dùng route /oauth/token thật, nên phải
            // tự bắt ở đây và đổi thành lỗi GraphQL rõ ràng.
            $leagueException = $e->getPrevious();

            throw ValidationException::withMessages([
                'credentials' => [$this->translateError(
                    $leagueException instanceof LeagueOAuthServerException ? $leagueException->getErrorType() : null,
                )],
            ]);
        }

        return json_decode($response->getContent(), true) ?? [];
    }

    private function translateError(?string $error): string
    {
        return match ($error) {
            'invalid_grant' => 'Email hoặc mật khẩu không đúng, hoặc refresh token đã hết hạn/bị thu hồi.',
            'invalid_scope' => 'Phiên này không bật "remember me" nên không thể refresh thành phiên 30 ngày. Vui lòng đăng nhập lại.',
            default => 'Không đăng nhập được, vui lòng thử lại.',
        };
    }
}
