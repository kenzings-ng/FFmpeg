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
 * Issues / refreshes access tokens WITHOUT routing through /oauth/token.
 *
 * This app uses a single API gateway (/graphql, see AppServiceProvider::register()
 * calling Passport::ignoreRoutes()). However, token issuance still requires Passport's
 * OAuth2 logic (league/oauth2-server): client verification, password hashing, access token
 * signing, refresh token rotation... Rather than duplicating that logic, this class directly calls
 * AccessTokenController::issueToken() by synthesizing a PSR-7 request from GraphQL arguments.
 */
class PassportTokenIssuer
{
    /**
     * @param  array<string, mixed>  $parameters  grant_type, client_id, and (username+password) or refresh_token
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(array $parameters, bool $rememberMe = false): array
    {
        if ($rememberMe) {
            // The "remember" scope is signed into the token. On subsequent refresh,
            // league/oauth2-server validates this scope.
            $parameters['scope'] = trim(($parameters['scope'] ?? '').' remember');

            // Must set BEFORE AuthorizationServer is instantiated,
            // because Passport reads refresh token TTL at that point.
            Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        }

        $psrFactory = new HttpFactory;
        $psrBridge = new PsrHttpFactory($psrFactory, $psrFactory, $psrFactory, $psrFactory);

        $psrRequest = $psrBridge->createRequest(
            LaravelRequest::create('/oauth/token', 'POST', $parameters),
        );

        try {
            $response = app(AccessTokenController::class)->issueToken($psrRequest, $psrFactory->createResponse());
        } catch (OAuthServerException $e) {
            // AccessTokenController throws OAuthServerException directly rather than returning
            // a 4xx HTTP response. Since the controller is invoked directly rather than through
            // an HTTP route, catch it here and convert to a clean ValidationException.
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
            'invalid_grant' => 'Invalid email or password, or the refresh token has expired or been revoked.',
            'invalid_scope' => 'This session does not have "remember me" enabled. Please log in again.',
            default => 'Unable to authenticate, please try again.',
        };
    }
}
