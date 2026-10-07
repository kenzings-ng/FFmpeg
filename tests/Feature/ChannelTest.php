<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    private const CHANNEL = 'query ($u: String!) { userByUsername(username: $u) {
        id username bio email videos_count
        videos(first: 10) { data { id } paginatorInfo { total } }
    } }';

    private function video(User $owner, bool $public = true, string $status = 'ready'): Video
    {
        return Video::create(['title' => 'v', 'user_id' => $owner->id, 'is_public' => $public, 'status' => $status]);
    }

    public function test_channel_lists_only_public_ready_videos_newest_first(): void
    {
        $owner = User::factory()->create(['username' => 'kenh', 'bio' => 'Xin chào <3']);
        $old = $this->video($owner);
        $this->video($owner, public: false);
        $this->video($owner, status: 'processing');
        $new = $this->video($owner);
        $this->video(User::factory()->create());

        // Kể cả chủ kênh tự xem: trang kênh chỉ có video công khai (như YouTube).
        foreach ([User::factory()->create(), $owner] as $viewer) {
            Passport::actingAs($viewer);
            $this->graphQL(self::CHANNEL, ['u' => '@Kenh'])
                ->assertJsonPath('data.userByUsername.bio', 'Xin chào <3')
                ->assertJsonPath('data.userByUsername.videos_count', 2)
                ->assertJsonPath('data.userByUsername.videos.paginatorInfo.total', 2)
                ->assertJsonPath('data.userByUsername.videos.data.0.id', (string) $new->id)
                ->assertJsonPath('data.userByUsername.videos.data.1.id', (string) $old->id);
        }
    }

    public function test_channel_does_not_expose_email_of_others(): void
    {
        User::factory()->create(['username' => 'kenh']);
        Passport::actingAs(User::factory()->create());

        $this->graphQL(self::CHANNEL, ['u' => 'kenh'])->assertJsonPath('data.userByUsername.email', null);
    }

    public function test_old_handle_resolves_to_owner_while_held_then_stops(): void
    {
        $owner = User::factory()->create(['username' => 'cu']);
        $owner->changeUsername('moi');
        $owner->save();
        Passport::actingAs(User::factory()->create());

        $this->graphQL(self::CHANNEL, ['u' => 'cu'])->assertJsonPath('data.userByUsername.username', 'moi');
        $this->graphQL(self::CHANNEL, ['u' => 'khongco'])->assertJsonPath('data.userByUsername', null);

        // Hết thời gian giữ chỗ, có người lấy "cu": trả về người mới.
        $this->travel(15)->days();
        $this->graphQL(self::CHANNEL, ['u' => 'cu'])->assertJsonPath('data.userByUsername', null);
        $taker = User::factory()->create(['username' => 'cu']);
        $this->graphQL(self::CHANNEL, ['u' => 'cu'])->assertJsonPath('data.userByUsername.id', (string) $taker->id);
    }

    public function test_update_and_clear_bio(): void
    {
        Passport::actingAs($me = User::factory()->create());
        $update = 'mutation ($b: String) { updateProfile(bio: $b) { bio } }';

        $this->graphQL($update, ['b' => "  Dòng 1\nDòng 2  "])->assertJsonPath('data.updateProfile.bio', "Dòng 1\nDòng 2");
        $this->graphQL($update, ['b' => str_repeat('a', 1001)])->assertGraphQLValidationKeys(['bio']);
        $this->graphQL($update, ['b' => ''])->assertJsonPath('data.updateProfile.bio', null);
        // Không gửi bio: giữ nguyên.
        $me->forceFill(['bio' => 'giữ'])->save();
        $this->graphQL('mutation { updateProfile(name: "Tên") { bio } }')->assertJsonPath('data.updateProfile.bio', 'giữ');
    }

    public function test_channel_requires_login(): void
    {
        User::factory()->create(['username' => 'kenh']);

        $this->graphQL(self::CHANNEL, ['u' => 'kenh'])->assertGraphQLErrorMessage('Unauthenticated.');
    }
}
