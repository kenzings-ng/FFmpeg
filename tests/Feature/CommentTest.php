<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    private const CREATE = 'mutation ($video: ID!, $body: String!, $reply: ID) {
        createComment(video_id: $video, body: $body, reply_to_id: $reply) {
            id body parent_id reply_to_user { id } user { id }
        }
    }';

    private function video(User $owner, bool $public = true): Video
    {
        return Video::create(['title' => 'v', 'user_id' => $owner->id, 'is_public' => $public, 'status' => 'ready']);
    }

    private function comment(Video $video, User $user, ?Comment $parent = null): Comment
    {
        return Comment::create([
            'video_id' => $video->id,
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'body' => 'hi',
        ]);
    }

    public function test_viewer_can_comment_on_public_video_and_body_is_trimmed(): void
    {
        $video = $this->video(User::factory()->create());
        Passport::actingAs($viewer = User::factory()->create());

        $this->graphQL(self::CREATE, ['video' => $video->id, 'body' => '  a < b <3  '])
            ->assertJsonPath('data.createComment.body', 'a < b <3')
            ->assertJsonPath('data.createComment.parent_id', null)
            ->assertJsonPath('data.createComment.user.id', (string) $viewer->id);
    }

    public function test_cannot_comment_on_private_video_of_someone_else(): void
    {
        $video = $this->video(User::factory()->create(), public: false);
        Passport::actingAs(User::factory()->create());

        $this->graphQL(self::CREATE, ['video' => $video->id, 'body' => 'x'])
            ->assertGraphQLErrorMessage('Video not found.');
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_reply_to_a_reply_stays_in_the_top_level_thread(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner);
        $root = $this->comment($video, $owner);
        $reply = $this->comment($video, $replier = User::factory()->create(), $root);
        Passport::actingAs(User::factory()->create());

        $this->graphQL(self::CREATE, ['video' => $video->id, 'body' => 'x', 'reply' => $reply->id])
            ->assertJsonPath('data.createComment.parent_id', (string) $root->id)
            ->assertJsonPath('data.createComment.reply_to_user.id', (string) $replier->id);

        $this->graphQL(self::CREATE, ['video' => $video->id, 'body' => 'y', 'reply' => $root->id])
            ->assertJsonPath('data.createComment.parent_id', (string) $root->id)
            ->assertJsonPath('data.createComment.reply_to_user', null);
    }

    public function test_cannot_reply_to_comment_of_another_video(): void
    {
        $owner = User::factory()->create();
        $other = $this->comment($this->video($owner), $owner);
        Passport::actingAs($owner);

        $this->graphQL(self::CREATE, ['video' => $this->video($owner)->id, 'body' => 'x', 'reply' => $other->id])
            ->assertGraphQLErrorMessage('The comment you are replying to no longer exists.');
    }

    public function test_body_is_required_and_limited(): void
    {
        $video = $this->video(User::factory()->create());
        Passport::actingAs(User::factory()->create());

        // Whitespace only: TrimStrings + ConvertEmptyStringsToNull turns it into null,
        // GraphQL rejects immediately (String!) before the "required" validation rule.
        $this->graphQL(self::CREATE, ['video' => $video->id, 'body' => '   '])->assertJsonStructure(['errors']);
        $this->assertDatabaseCount('comments', 0);
        $this->graphQL(self::CREATE, ['video' => $video->id, 'body' => str_repeat('a', 2001)])->assertGraphQLValidationKeys(['body']);
    }

    public function test_lists_top_level_newest_first_with_counts_and_replies_oldest_first(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner);
        $old = $this->comment($video, $owner);
        $new = $this->comment($video, $owner);
        $first = $this->comment($video, $owner, $old);
        $second = $this->comment($video, $owner, $old);
        Passport::actingAs(User::factory()->create());

        $this->graphQL('query ($id: ID!) { video(id: $id) {
                comments_count
                comments(first: 10) { data { id replies_count } paginatorInfo { total } }
            } }', ['id' => $video->id])
            ->assertJsonPath('data.video.comments_count', 4)
            ->assertJsonPath('data.video.comments.paginatorInfo.total', 2)
            ->assertJsonPath('data.video.comments.data.0.id', (string) $new->id)
            ->assertJsonPath('data.video.comments.data.1.replies_count', 2);

        $this->graphQL('query ($id: ID!) { comment(id: $id) { replies(first: 10) { data { id } } } }', ['id' => $old->id])
            ->assertJsonPath('data.comment.replies.data.0.id', (string) $first->id)
            ->assertJsonPath('data.comment.replies.data.1.id', (string) $second->id);
    }

    public function test_comments_of_private_video_are_hidden_from_others(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner, public: false);
        $comment = $this->comment($video, $owner);
        Passport::actingAs(User::factory()->create());

        $this->graphQL('query ($id: ID!) { comment(id: $id) { id } }', ['id' => $comment->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_only_author_can_edit_and_edit_marks_comment_as_edited(): void
    {
        $owner = User::factory()->create();
        $comment = $this->comment($this->video($owner), $author = User::factory()->create());
        $edit = 'mutation ($id: ID!, $body: String!) { updateComment(id: $id, body: $body) { body edited_at } }';

        Passport::actingAs($owner);
        $this->graphQL($edit, ['id' => $comment->id, 'body' => 'hacked'])
            ->assertGraphQLErrorMessage('This action is unauthorized.');

        Passport::actingAs($author);
        $this->graphQL($edit, ['id' => $comment->id, 'body' => 'hi'])->assertJsonPath('data.updateComment.edited_at', null);
        $this->graphQL($edit, ['id' => $comment->id, 'body' => 'edited'])->assertJsonPath('data.updateComment.body', 'edited');
        $this->assertNotNull($comment->fresh()->edited_at);
    }

    public function test_author_or_video_owner_can_delete_and_replies_go_with_the_root(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner);
        $root = $this->comment($video, $author = User::factory()->create());
        $this->comment($video, $owner, $root);
        $delete = 'mutation ($id: ID!) { deleteComment(id: $id) }';

        Passport::actingAs(User::factory()->create());
        $this->graphQL($delete, ['id' => $root->id])->assertGraphQLErrorMessage('This action is unauthorized.');

        Passport::actingAs($owner);
        $this->graphQL($delete, ['id' => $root->id])->assertJsonPath('data.deleteComment', true);
        $this->assertDatabaseCount('comments', 0);

        $this->comment($video, $author);
        Passport::actingAs($author);
        $this->graphQL($delete, ['id' => Comment::first()->id])->assertJsonPath('data.deleteComment', true);
    }

    public function test_deleting_video_deletes_its_comments(): void
    {
        $owner = User::factory()->create();
        $video = $this->video($owner);
        $this->comment($video, $owner, $this->comment($video, $owner));

        $video->delete();

        $this->assertDatabaseCount('comments', 0);
    }

    public function test_mentions_are_resolved_to_existing_users_and_resynced_on_edit(): void
    {
        $owner = User::factory()->create(['username' => 'owner']);
        $bob = User::factory()->create(['username' => 'bob']);
        $video = $this->video($owner);
        Passport::actingAs(User::factory()->create());

        $id = $this->graphQL('mutation ($v: ID!) { createComment(video_id: $v, body: "hi @Bob and @nobody, mail a@owner.com") { id mentions { handle user { id } } } }', ['v' => $video->id])
            ->assertJsonPath('data.createComment.mentions', [['handle' => 'bob', 'user' => ['id' => (string) $bob->id]]])
            ->json('data.createComment.id');

        $this->graphQL('mutation ($id: ID!) { updateComment(id: $id, body: "@owner only") { mentions { handle user { id } } } }', ['id' => $id])
            ->assertJsonPath('data.updateComment.mentions', [['handle' => 'owner', 'user' => ['id' => (string) $owner->id]]]);
    }

    public function test_mentions_by_user_display_name_like_facebook(): void
    {
        $owner = User::factory()->create(['name' => 'Nguyễn Văn Bảo', 'username' => 'user_12345']);
        $video = $this->video($owner);
        Passport::actingAs(User::factory()->create());

        $this->graphQL('mutation ($v: ID!) { createComment(video_id: $v, body: "hello @Nguyễn Văn Bảo!") { mentions { handle user { id username name } } } }', ['v' => $video->id])
            ->assertJsonPath('data.createComment.mentions', [[
                'handle' => 'Nguyễn Văn Bảo',
                'user' => [
                    'id' => (string) $owner->id,
                    'username' => 'user_12345',
                    'name' => 'Nguyễn Văn Bảo',
                ],
            ]]);
    }

    public function test_mention_prefers_full_display_name_over_handle_prefix(): void
    {
        // User with username 'john' and user with display name 'John Doe'
        $johnUser = User::factory()->create(['name' => 'Johnny', 'username' => 'john']);
        $johnDoeUser = User::factory()->create(['name' => 'John Doe', 'username' => 'johndoe']);
        $video = $this->video($johnUser);
        Passport::actingAs(User::factory()->create());

        // Comment only mentions @John Doe, must not mistakenly mention user 'john'
        $this->graphQL('mutation ($v: ID!) { createComment(video_id: $v, body: "Hello @John Doe how are you?") { mentions { handle user { id username } } } }', ['v' => $video->id])
            ->assertJsonPath('data.createComment.mentions', [[
                'handle' => 'John Doe',
                'user' => [
                    'id' => (string) $johnDoeUser->id,
                    'username' => 'johndoe',
                ],
            ]]);
    }

    public function test_mention_supports_display_names_longer_than_60_characters(): void
    {
        $longName = 'Nguyễn Thị '.str_repeat('Hoa ', 15).'Bảo'; // ~75 characters
        $longUser = User::factory()->create(['name' => $longName, 'username' => 'long_name_user']);
        $video = $this->video($longUser);
        Passport::actingAs(User::factory()->create());

        $this->graphQL('mutation ($v: ID!) { createComment(video_id: $v, body: "hello @'.$longName.'!") { mentions { handle user { id username } } } }', ['v' => $video->id])
            ->assertJsonPath('data.createComment.mentions', [[
                'handle' => $longName,
                'user' => [
                    'id' => (string) $longUser->id,
                    'username' => 'long_name_user',
                ],
            ]]);
    }

    public function test_mention_finds_display_name_when_more_than_50_users_share_first_word(): void
    {
        $owner = User::factory()->create(['name' => 'Video Owner', 'username' => 'owner_user']);
        $video = $this->video($owner);

        // Create 55 other users with the same "Nguyễn" surname
        for ($i = 1; $i <= 55; $i++) {
            User::factory()->create([
                'name' => "Nguyễn Người Thứ {$i}",
                'username' => "nguyen_user_{$i}",
            ]);
        }

        // Target user also has "Nguyễn" surname and is not a video participant
        $targetUser = User::factory()->create([
            'name' => 'Nguyễn Văn Đặc Biệt',
            'username' => 'nguyen_dac_biet',
        ]);

        Passport::actingAs(User::factory()->create());
        $this->graphQL('mutation ($v: ID!) { createComment(video_id: $v, body: "mentioning @Nguyễn Văn Đặc Biệt") { mentions { handle user { id username } } } }', ['v' => $video->id])
            ->assertJsonPath('data.createComment.mentions', [[
                'handle' => 'Nguyễn Văn Đặc Biệt',
                'user' => [
                    'id' => (string) $targetUser->id,
                    'username' => 'nguyen_dac_biet',
                ],
            ]]);
    }

    public function test_mention_keeps_pointing_to_the_same_user_after_handle_change(): void
    {
        $owner = User::factory()->create();
        $bob = User::factory()->create(['username' => 'bob']);
        $comment = Comment::create(['video_id' => $this->video($owner)->id, 'user_id' => $owner->id, 'body' => 'hello @bob']);
        $comment->syncMentions();

        // Bob changes handle; 14 days later another user claims handle "bob".
        $bob->changeUsername('bobby');
        $bob->save();
        $this->travel(15)->days();
        User::factory()->create(['username' => 'bob']);

        Passport::actingAs($owner);
        $this->graphQL('query ($id: ID!) { comment(id: $id) { body mentions { handle user { id username } } } }', ['id' => $comment->id])
            ->assertJsonPath('data.comment.body', 'hello @bob')
            ->assertJsonPath('data.comment.mentions', [['handle' => 'bob', 'user' => ['id' => (string) $bob->id, 'username' => 'bobby']]]);
    }

    public function test_mention_suggestions_rank_participants_first_and_exclude_self(): void
    {
        $owner = User::factory()->create(['name' => 'Video Owner', 'username' => 'chu']);
        $video = $this->video($owner);
        $this->comment($video, $talker = User::factory()->create(['name' => 'Binh An', 'username' => 'zz_binh']));
        User::factory()->create(['name' => 'Someone Else', 'username' => 'binh.other']);
        User::factory()->create(['name' => 'No Match', 'username' => 'binhxa']);
        Passport::actingAs($me = User::factory()->create(['name' => 'Binh Me', 'username' => 'binhme']));
        $this->comment($video, $me);
        $q = 'query ($v: ID!, $q: String) { mentionSuggestions(video_id: $v, query: $q) { username } }';

        // Empty query: only participants (most recent comment first) + video owner, excluding self.
        $this->assertSame(['chu', 'zz_binh'], array_column($this->graphQL($q, ['v' => $video->id])->json('data.mentionSuggestions'), 'username'));

        // Query "binh": participants matching name first, then other users matching handle prefix (shorter first).
        $this->assertSame(
            ['zz_binh', 'binhxa', 'binh.other'],
            array_column($this->graphQL($q, ['v' => $video->id, 'q' => 'binh'])->json('data.mentionSuggestions'), 'username'),
        );

        // Case- and accent-insensitive, including "Đ" -> "d"; matches prefix of any word in name.
        User::factory()->create(['name' => 'Lê Đặng Lâm', 'username' => 'lam']);
        $this->assertSame(['lam'], array_column($this->graphQL($q, ['v' => $video->id, 'q' => 'Dang'])->json('data.mentionSuggestions'), 'username'));

        // "_" is treated as a literal character, not a LIKE wildcard.
        $this->assertSame(['zz_binh'], array_column($this->graphQL($q, ['v' => $video->id, 'q' => 'zz_'])->json('data.mentionSuggestions'), 'username'));
    }

    public function test_mention_suggestions_require_access_to_video(): void
    {
        $video = $this->video(User::factory()->create(), public: false);
        Passport::actingAs(User::factory()->create());

        $this->graphQL('query ($v: ID!) { mentionSuggestions(video_id: $v, query: "a") { username } }', ['v' => $video->id])
            ->assertGraphQLErrorMessage('Video not found.');
    }
}
