<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PassportTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Passport::client()->create([
            'name' => 'Test Password Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['http://localhost'],
            'grant_types' => ['password', 'refresh_token'],
            'revoked' => false,
        ]);

        config(['services.passport.password_client_id' => (string) $client->id]);
    }

    private function issueTokens(User $user, string $password = 'password123'): array
    {
        return app(PassportTokenIssuer::class)->issue([
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'username' => $user->email,
            'password' => $password,
        ]);
    }

    public function test_authenticated_logout_revokes_the_intended_access_token(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $tokens = $this->issueTokens($user);

        // Logout using current Bearer token
        $this->withToken($tokens['access_token'])
            ->graphQL('mutation { logout }')
            ->assertJsonPath('data.logout', true);

        // Access token in database is marked revoked = true
        $this->assertDatabaseHas('oauth_access_tokens', [
            'user_id' => $user->id,
            'revoked' => true,
        ]);

        // Requests with revoked access token are rejected
        auth()->forgetGuards();
        $this->withToken($tokens['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_associated_refresh_tokens_cannot_be_used_after_logout(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $tokens = $this->issueTokens($user);

        // Logout
        $this->withToken($tokens['access_token'])
            ->graphQL('mutation { logout }')
            ->assertJsonPath('data.logout', true);

        // Refresh token linked to access token is revoked
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'revoked' => true,
        ]);

        // Attempting to use refresh token after logout is rejected
        $this->graphQL('mutation ($rt: String!) { refreshToken(refresh_token: $rt) { access_token } }', [
            'rt' => $tokens['refresh_token'],
        ])->assertGraphQLValidationError('credentials', 'Invalid email or password, or the refresh token has expired or been revoked.');
    }

    public function test_password_reset_invalidates_all_active_sessions(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);

        // User has 2 active sessions from 2 different devices
        $session1 = $this->issueTokens($user, 'oldpassword123');
        $session2 = $this->issueTokens($user, 'oldpassword123');

        $this->assertDatabaseCount('oauth_access_tokens', 2);
        $this->assertEquals(0, Passport::token()->where('revoked', true)->count());

        // Reset password via email broker token
        $resetToken = Password::broker()->createToken($user);
        $this->graphQL('mutation ($e: String!, $t: String!, $p: String!) { resetPassword(email: $e, token: $t, password: $p) }', [
            'e' => $user->email,
            't' => $resetToken,
            'p' => 'newpassword123',
        ])->assertJsonPath('data.resetPassword', true);

        // All previous access tokens and refresh tokens are revoked
        $this->assertEquals(2, Passport::token()->where('revoked', true)->count());
        $this->assertEquals(2, Passport::refreshToken()->where('revoked', true)->count());

        // Both access tokens can no longer call the API
        $this->withToken($session1['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        $this->withToken($session2['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        // Old refresh tokens cannot be reused
        $this->graphQL('mutation ($rt: String!) { refreshToken(refresh_token: $rt) { access_token } }', [
            'rt' => $session1['refresh_token'],
        ])->assertGraphQLValidationError('credentials', 'Invalid email or password, or the refresh token has expired or been revoked.');

        // New login with updated password succeeds
        $newSession = $this->issueTokens($user, 'newpassword123');
        $this->assertNotEmpty($newSession['access_token']);
    }

    public function test_authenticated_password_change_invalidates_other_sessions_while_preserving_current(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);

        // Session 1 (current device) and Session 2 (other device)
        $currentSession = $this->issueTokens($user, 'oldpassword123');
        $otherSession = $this->issueTokens($user, 'oldpassword123');

        // Change password from current session
        $this->withToken($currentSession['access_token'])
            ->graphQL('mutation ($cp: String!, $np: String!) { updateProfile(current_password: $cp, password: $np) { id } }', [
                'cp' => 'oldpassword123',
                'np' => 'newpassword456',
            ])->assertJsonPath('data.updateProfile.id', (string) $user->id);

        // Current session remains authenticated
        $this->withToken($currentSession['access_token'])
            ->graphQL('query { me { id } }')
            ->assertJsonPath('data.me.id', (string) $user->id);

        // Other session is revoked immediately
        auth()->forgetGuards();
        $this->withToken($otherSession['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        // Refresh token of other session is revoked
        $this->graphQL('mutation ($rt: String!) { refreshToken(refresh_token: $rt) { access_token } }', [
            'rt' => $otherSession['refresh_token'],
        ])->assertGraphQLValidationError('credentials', 'Invalid email or password, or the refresh token has expired or been revoked.');

        // New login with updated password succeeds
        $newLogin = $this->issueTokens($user, 'newpassword456');
        $this->assertNotEmpty($newLogin['access_token']);
    }

    public function test_invalid_current_password_cannot_change_credentials(): void
    {
        $user = User::factory()->create(['password' => 'correctpassword']);
        $session = $this->issueTokens($user, 'correctpassword');

        // Attempting password change with incorrect current password
        $this->withToken($session['access_token'])
            ->graphQL('mutation ($cp: String!, $np: String!) { updateProfile(current_password: $cp, password: $np) { id } }', [
                'cp' => 'wrongpassword',
                'np' => 'newpassword789',
            ])->assertGraphQLValidationError('current_password', 'Current password is incorrect.');

        // Current session is unaffected
        $this->withToken($session['access_token'])
            ->graphQL('query { me { id } }')
            ->assertJsonPath('data.me.id', (string) $user->id);

        // Old password still works, new password cannot log in
        $this->issueTokens($user, 'correctpassword');
        $this->expectException(ValidationException::class);
        $this->issueTokens($user, 'newpassword789');
    }

    public function test_existing_login_refresh_token_and_profile_flows_work(): void
    {
        // 1. Register account
        $registerRes = $this->graphQL('mutation ($n: String!, $e: String!, $p: String!) {
            register(name: $n, email: $e, password: $p, password_confirmation: $p) {
                access_token refresh_token token_type expires_in
            }
        }', [
            'n' => 'Alice Test',
            'e' => 'alice.auth@example.com',
            'p' => 'password123',
        ]);
        $registerRes->assertJsonStructure(['data' => ['register' => ['access_token', 'refresh_token', 'token_type', 'expires_in']]]);
        $initialRefreshToken = $registerRes->json('data.register.refresh_token');

        // 2. Login
        $loginRes = $this->graphQL('mutation ($e: String!, $p: String!) {
            login(email: $e, password: $p) {
                access_token refresh_token
            }
        }', [
            'e' => 'alice.auth@example.com',
            'p' => 'password123',
        ]);
        $loginRes->assertJsonStructure(['data' => ['login' => ['access_token', 'refresh_token']]]);
        $loginAccessToken = $loginRes->json('data.login.access_token');
        $loginRefreshToken = $loginRes->json('data.login.refresh_token');

        // 3. Refresh token
        $refreshRes = $this->graphQL('mutation ($rt: String!) {
            refreshToken(refresh_token: $rt) {
                access_token refresh_token
            }
        }', ['rt' => $loginRefreshToken]);
        $refreshRes->assertJsonStructure(['data' => ['refreshToken' => ['access_token', 'refresh_token']]]);
        $refreshedAccessToken = $refreshRes->json('data.refreshToken.access_token');

        // 4. Update profile (without changing password) works normally and preserves session
        $this->withToken($refreshedAccessToken)
            ->graphQL('mutation ($name: String, $bio: String) {
                updateProfile(name: $name, bio: $bio) {
                    name bio
                }
            }', [
                'name' => 'Alice Updated',
                'bio' => 'Hello from Alice',
            ])
            ->assertJsonPath('data.updateProfile.name', 'Alice Updated')
            ->assertJsonPath('data.updateProfile.bio', 'Hello from Alice');

        // Token continues to be valid
        $this->withToken($refreshedAccessToken)
            ->graphQL('query { me { name } }')
            ->assertJsonPath('data.me.name', 'Alice Updated');
    }
}
