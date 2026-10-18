<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SegmentVideoJob;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoRendition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class SegmentVideoTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    private const MUTATION = 'mutation ($id: ID!) {
        segmentVideo(id: $id) {
            id status
        }
    }';

    public function test_ready_video_without_original_file_cannot_be_resegmented(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Passport::actingAs($user);

        // Video đang ready, nhưng original_path không còn trên disk (đã bị FinalizeVideoJob dọn)
        $video = Video::create([
            'title' => 'Ready Video',
            'user_id' => $user->id,
            'is_public' => true,
            'status' => 'ready',
            'original_path' => 'videos/1/original.mp4',
        ]);

        $this->graphQL(self::MUTATION, ['id' => (string) $video->id])
            ->assertGraphQLErrorMessage('Tệp gốc của video không tồn tại hoặc đã bị xóa.');

        // Trạng thái video vẫn giữ nguyên là ready, không bị hạ thành pending hay failed
        $this->assertSame('ready', $video->fresh()->status);
    }

    public function test_processing_video_cannot_be_resegmented(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Passport::actingAs($user);

        Storage::disk('local')->put('videos/2/original.mp4', 'dummy video content');

        $video = Video::create([
            'title' => 'Processing Video',
            'user_id' => $user->id,
            'is_public' => true,
            'status' => 'processing',
            'original_path' => 'videos/2/original.mp4',
        ]);

        $this->graphQL(self::MUTATION, ['id' => (string) $video->id])
            ->assertGraphQLErrorMessage('Video đang trong quá trình xử lý, không thể yêu cầu segment lại lúc này.');

        // Trạng thái giữ nguyên là processing
        $this->assertSame('processing', $video->fresh()->status);
    }

    public function test_failed_video_with_original_file_can_be_resegmented(): void
    {
        Queue::fake([SegmentVideoJob::class]);
        Storage::fake('local');
        $user = User::factory()->create();
        Passport::actingAs($user);

        Storage::disk('local')->put('videos/3/original.mp4', 'dummy video content');

        $video = Video::create([
            'title' => 'Failed Video',
            'user_id' => $user->id,
            'is_public' => true,
            'status' => 'failed',
            'original_path' => 'videos/3/original.mp4',
        ]);

        $this->graphQL(self::MUTATION, ['id' => (string) $video->id])
            ->assertJsonPath('data.segmentVideo.id', (string) $video->id)
            ->assertJsonPath('data.segmentVideo.status', 'PENDING');

        $this->assertSame('pending', $video->fresh()->status);
        Queue::assertPushed(SegmentVideoJob::class, fn (SegmentVideoJob $job) => $video->is($job->video));
    }

    public function test_non_owner_cannot_resegment_video(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        Passport::actingAs($otherUser);

        Storage::disk('local')->put('videos/4/original.mp4', 'dummy video content');

        $video = Video::create([
            'title' => 'Owner Video',
            'user_id' => $owner->id,
            'is_public' => true,
            'status' => 'failed',
            'original_path' => 'videos/4/original.mp4',
        ]);

        $this->graphQL(self::MUTATION, ['id' => (string) $video->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->assertSame('failed', $video->fresh()->status);
    }

    public function test_abandoning_job_preserves_ready_status_if_valid_hls_exists(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $oldHlsDir = 'hls/old-valid-dir';
        $newHlsDir = 'hls/new-failed-dir';

        Storage::disk('local')->put("{$oldHlsDir}/master.m3u8", "#EXTM3U\n");
        Storage::disk('local')->put("{$oldHlsDir}/r720.m3u8", "#EXTM3U\n");
        Storage::disk('local')->put("{$oldHlsDir}/seg0.ts", "ts\n");

        $video = Video::create([
            'title' => 'Ready Video With Old HLS',
            'user_id' => $user->id,
            'is_public' => true,
            'status' => 'processing',
            'hls_disk' => 'local',
            'hls_playlist_path' => "{$oldHlsDir}/master.m3u8",
            'original_path' => 'videos/5/original.mp4',
        ]);

        VideoRendition::create([
            'video_id' => $video->id,
            'hls_dir' => $oldHlsDir,
            'height' => 720,
            'playlist_name' => 'r720.m3u8',
            'stream_inf' => '#EXT-X-STREAM-INF:BANDWIDTH=2800000',
            'key_id' => 'k1',
            'encrypted_key' => 'enc1',
        ]);

        // Tạo rendition của lần chạy mới bị thất bại
        VideoRendition::create([
            'video_id' => $video->id,
            'hls_dir' => $newHlsDir,
            'height' => 720,
            'playlist_name' => 'r720_new.m3u8',
            'stream_inf' => null,
            'key_id' => 'k2',
            'encrypted_key' => 'enc2',
        ]);

        SegmentVideoJob::abandon($video->id, $newHlsDir, 'local');

        // Video được khôi phục về ready thay vì bị hạ thành failed
        $this->assertSame('ready', $video->fresh()->status);
        // Rendition của lần chạy mới bị xóa, rendition cũ vẫn giữ nguyên
        $this->assertDatabaseMissing('video_renditions', ['hls_dir' => $newHlsDir]);
        $this->assertDatabaseHas('video_renditions', ['hls_dir' => $oldHlsDir]);
    }
}
