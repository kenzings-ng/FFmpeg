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

    public function test_generates_unique_random_handle_when_empty(): void
    {
        $u1 = User::factory()->create(['name' => 'Bảo Nguyễn']);
        $u2 = User::factory()->create(['name' => 'Bao Nguyen']);

        $this->assertMatchesRegularExpression('/^user_[a-z0-9]+$/', $u1->username);
        $this->assertMatchesRegularExpression('/^user_[a-z0-9]+$/', $u2->username);
        $this->assertNotSame($u1->username, $u2->username);
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

        $this->assertSame('This username is already taken.', Username::problem('BAO'));
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
            ->assertGraphQLErrorMessage('You can only change your username 2 times in 14 days.');
        $this->assertSame('third', $me->fresh()->username);
        // Resubmitting current handle does not count as a change.
        $this->graphQL(self::UPDATE, ['u' => 'third'])->assertJsonPath('data.updateProfile.username', 'third');

        // Relinquished handle is held: others cannot claim it, original owner can (if change allowance remains).
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
            Username::mentionedIn('@Bao hello @an.nguyen. and @x_y, mail a@bob.com, @@bad, @bao'),
        );
    }
}
