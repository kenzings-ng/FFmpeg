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

        // Đăng xuất bằng Bearer token hiện tại
        $this->withToken($tokens['access_token'])
            ->graphQL('mutation { logout }')
            ->assertJsonPath('data.logout', true);

        // Access token trong cơ sở dữ liệu đã bị đánh dấu revoked = true
        $this->assertDatabaseHas('oauth_access_tokens', [
            'user_id' => $user->id,
            'revoked' => true,
        ]);

        // Yêu cầu sau khi đăng xuất với access token này bị từ chối
        auth()->forgetGuards();
        $this->withToken($tokens['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_associated_refresh_tokens_cannot_be_used_after_logout(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $tokens = $this->issueTokens($user);

        // Đăng xuất
        $this->withToken($tokens['access_token'])
            ->graphQL('mutation { logout }')
            ->assertJsonPath('data.logout', true);

        // Refresh token liên kết với access token này đã bị thu hồi
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'revoked' => true,
        ]);

        // Cố gắng dùng refresh token sau khi logout bị từ chối
        $this->graphQL('mutation ($rt: String!) { refreshToken(refresh_token: $rt) { access_token } }', [
            'rt' => $tokens['refresh_token'],
        ])->assertGraphQLValidationError('credentials', 'Email hoặc mật khẩu không đúng, hoặc refresh token đã hết hạn/bị thu hồi.');
    }

    public function test_password_reset_invalidates_all_active_sessions(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);

        // Người dùng có 2 phiên đang hoạt động từ 2 thiết bị khác nhau
        $session1 = $this->issueTokens($user, 'oldpassword123');
        $session2 = $this->issueTokens($user, 'oldpassword123');

        $this->assertDatabaseCount('oauth_access_tokens', 2);
        $this->assertEquals(0, Passport::token()->where('revoked', true)->count());

        // Đặt lại mật khẩu qua email token
        $resetToken = Password::broker()->createToken($user);
        $this->graphQL('mutation ($e: String!, $t: String!, $p: String!) { resetPassword(email: $e, token: $t, password: $p) }', [
            'e' => $user->email,
            't' => $resetToken,
            'p' => 'newpassword123',
        ])->assertJsonPath('data.resetPassword', true);

        // Toàn bộ access tokens và refresh tokens trước đó đều bị thu hồi
        $this->assertEquals(2, Passport::token()->where('revoked', true)->count());
        $this->assertEquals(2, Passport::refreshToken()->where('revoked', true)->count());

        // Cả 2 access tokens đều không thể tiếp tục gọi API
        $this->withToken($session1['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        $this->withToken($session2['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        // Refresh tokens cũ đều không thể dùng lại
        $this->graphQL('mutation ($rt: String!) { refreshToken(refresh_token: $rt) { access_token } }', [
            'rt' => $session1['refresh_token'],
        ])->assertGraphQLValidationError('credentials', 'Email hoặc mật khẩu không đúng, hoặc refresh token đã hết hạn/bị thu hồi.');

        // Đăng nhập lại với mật khẩu mới thành công
        $newSession = $this->issueTokens($user, 'newpassword123');
        $this->assertNotEmpty($newSession['access_token']);
    }

    public function test_authenticated_password_change_invalidates_other_sessions_while_preserving_current(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);

        // Phiên 1 (thiết bị hiện tại) và Phiên 2 (thiết bị khác)
        $currentSession = $this->issueTokens($user, 'oldpassword123');
        $otherSession = $this->issueTokens($user, 'oldpassword123');

        // Đổi mật khẩu từ phiên hiện tại
        $this->withToken($currentSession['access_token'])
            ->graphQL('mutation ($cp: String!, $np: String!) { updateProfile(current_password: $cp, password: $np) { id } }', [
                'cp' => 'oldpassword123',
                'np' => 'newpassword456',
            ])->assertJsonPath('data.updateProfile.id', (string) $user->id);

        // Phiên hiện tại vẫn tiếp tục sử dụng được (không bị gián đoạn UX)
        $this->withToken($currentSession['access_token'])
            ->graphQL('query { me { id } }')
            ->assertJsonPath('data.me.id', (string) $user->id);

        // Phiên khác đã bị thu hồi ngay lập tức
        auth()->forgetGuards();
        $this->withToken($otherSession['access_token'])
            ->graphQL('query { me { id } }')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        // Refresh token của phiên khác không thể dùng được nữa
        $this->graphQL('mutation ($rt: String!) { refreshToken(refresh_token: $rt) { access_token } }', [
            'rt' => $otherSession['refresh_token'],
        ])->assertGraphQLValidationError('credentials', 'Email hoặc mật khẩu không đúng, hoặc refresh token đã hết hạn/bị thu hồi.');

        // Đăng nhập mới bằng mật khẩu mới thành công
        $newLogin = $this->issueTokens($user, 'newpassword456');
        $this->assertNotEmpty($newLogin['access_token']);
    }

    public function test_invalid_current_password_cannot_change_credentials(): void
    {
        $user = User::factory()->create(['password' => 'correctpassword']);
        $session = $this->issueTokens($user, 'correctpassword');

        // Cố gắng đổi mật khẩu với mật khẩu hiện tại sai
        $this->withToken($session['access_token'])
            ->graphQL('mutation ($cp: String!, $np: String!) { updateProfile(current_password: $cp, password: $np) { id } }', [
                'cp' => 'wrongpassword',
                'np' => 'newpassword789',
            ])->assertGraphQLValidationError('current_password', 'Mật khẩu hiện tại không đúng.');

        // Phiên hiện tại không bị ảnh hưởng
        $this->withToken($session['access_token'])
            ->graphQL('query { me { id } }')
            ->assertJsonPath('data.me.id', (string) $user->id);

        // Mật khẩu cũ vẫn hoạt động, mật khẩu mới không thể đăng nhập
        $this->issueTokens($user, 'correctpassword');
        $this->expectException(ValidationException::class);
        $this->issueTokens($user, 'newpassword789');
    }

    public function test_existing_login_refresh_token_and_profile_flows_work(): void
    {
        // 1. Đăng ký tài khoản
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

        // 2. Đăng nhập
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

        // 4. Cập nhật hồ sơ (không đổi mật khẩu) vẫn hoạt động bình thường và không làm mất phiên
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

        // Token vẫn tiếp tục dùng được
        $this->withToken($refreshedAccessToken)
            ->graphQL('query { me { name } }')
            ->assertJsonPath('data.me.name', 'Alice Updated');
    }
}
