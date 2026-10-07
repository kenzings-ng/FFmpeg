<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Username;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class UsernameTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    private const UPDATE = 'mutation ($u: String) { updateProfile(username: $u) { username } }';

    public function test_generates_unique_handle_from_vietnamese_name(): void
    {
        $this->assertSame('baonguyen', User::factory()->create(['name' => 'Bảo Nguyễn'])->username);
        $this->assertSame('baonguyen2', User::factory()->create(['name' => 'Bao Nguyen'])->username);
        $this->assertSame('dangduc', User::factory()->create(['name' => 'Đặng Đức'])->username);
        $this->assertSame('usera', User::factory()->create(['name' => 'A'])->username);
        $this->assertSame('useradmin', User::factory()->create(['name' => 'Admin'])->username);
        $this->assertSame('user2', User::factory()->create(['name' => '李雷'])->username);
    }

    public function test_format_rules(): void
    {
        foreach (['ab', str_repeat('a', 31), '.bao', 'bao.', 'bao..ng', 'bảo', 'bao nguyen', 'bao-ng', 'admin'] as $bad) {
            $this->assertNotNull(Username::problem($bad), $bad);
        }
        foreach (['bao', 'Bao.Nguyen', '@bao_ng', 'b4o.n_g'] as $good) {
            $this->assertNull(Username::problem($good), $good);
        }
    }

    public function test_handle_is_case_insensitive_unique(): void
    {
        User::factory()->create(['username' => 'bao']);

        $this->assertSame('Tên người dùng này đã có người dùng.', Username::problem('BAO'));
        $this->graphQL('{ usernameAvailable(username: "@Bao") { username available message } }')
            ->assertJsonPath('data.usernameAvailable.username', 'bao')
            ->assertJsonPath('data.usernameAvailable.available', false);
    }

    public function test_change_handle_is_limited_and_old_handle_is_held(): void
    {
        Passport::actingAs($me = User::factory()->create(['username' => 'first']));

        $this->graphQL(self::UPDATE, ['u' => 'Second'])->assertJsonPath('data.updateProfile.username', 'second');
        $this->graphQL(self::UPDATE, ['u' => 'third'])->assertJsonPath('data.updateProfile.username', 'third');
        $this->graphQL(self::UPDATE, ['u' => 'fourth'])
            ->assertGraphQLErrorMessage('Bạn chỉ được đổi tên người dùng 2 lần trong 14 ngày.');
        $this->assertSame('third', $me->fresh()->username);
        // Gửi lại đúng handle hiện tại: không tính là đổi.
        $this->graphQL(self::UPDATE, ['u' => 'third'])->assertJsonPath('data.updateProfile.username', 'third');

        // Handle vừa bỏ được giữ: người khác không lấy được, chủ cũ thì được (khi còn lượt).
        $this->assertNotNull(Username::problem('first', User::factory()->create()));
        $this->assertNull(Username::problem('first', $me));

        $this->travel(15)->days();
        $this->assertNull(Username::problem('first', User::factory()->create()));
        $this->graphQL(self::UPDATE, ['u' => 'fourth'])->assertJsonPath('data.updateProfile.username', 'fourth');
    }

    public function test_cannot_take_someone_elses_handle(): void
    {
        User::factory()->create(['username' => 'taken']);
        Passport::actingAs(User::factory()->create());

        $this->graphQL(self::UPDATE, ['u' => 'Taken'])->assertGraphQLValidationKeys(['username']);
    }

    public function test_mentions_in_text_ignore_emails(): void
    {
        $this->assertSame(
            ['bao', 'an.nguyen', 'x_y'],
            Username::mentionedIn('@Bao chào @an.nguyen. và @x_y, mail a@bob.com, @@bad, @bao'),
        );
    }
}
