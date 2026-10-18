<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SegmentVideoJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class UploadVideoTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_upload_video_uses_content_derived_extension_and_ignores_dangerous_client_extension(): void
    {
        Queue::fake([SegmentVideoJob::class]);
        Storage::fake('local');

        $user = User::factory()->create();
        Passport::actingAs($user);

        // Giả lập client gửi file video MP4 nhưng đặt client filename là video.exe
        $file = UploadedFile::fake()->create('video.exe', 100, 'video/mp4');

        $operations = [
            'query' => 'mutation ($title: String!, $file: Upload!) {
                uploadVideo(title: $title, file: $file) {
                    id title status
                }
            }',
            'variables' => [
                'title' => 'Test Video',
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $res = $this->multipartGraphQL($operations, $map, ['0' => $file]);
        $res->assertJsonPath('data.uploadVideo.title', 'Test Video');

        $videoId = $res->json('data.uploadVideo.id');
        $video = Video::findOrFail($videoId);

        // Đuôi mở rộng phải là .mp4 (dựa trên nội dung mime), không bao giờ lấy .exe từ client
        $this->assertSame("videos/{$video->id}/original.mp4", $video->original_path);
        Storage::disk('local')->assertExists($video->original_path);
    }

    public function test_upload_video_falls_back_to_mp4_for_unknown_extension(): void
    {
        Queue::fake([SegmentVideoJob::class]);
        Storage::fake('local');

        $user = User::factory()->create();
        Passport::actingAs($user);

        // File có mime video/* hợp lệ nhưng đuôi mở rộng suy ra (3gp) không nằm trong whitelist
        $file = UploadedFile::fake()->create('clip.3gp', 100, 'video/3gpp');

        $operations = [
            'query' => 'mutation ($title: String!, $file: Upload!) {
                uploadVideo(title: $title, file: $file) {
                    id title status
                }
            }',
            'variables' => [
                'title' => '3GP Video Fallback',
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $res = $this->multipartGraphQL($operations, $map, ['0' => $file]);
        $res->assertJsonPath('data.uploadVideo.title', '3GP Video Fallback');

        $videoId = $res->json('data.uploadVideo.id');
        $video = Video::findOrFail($videoId);

        // Không cho phép .zip, fallback về .mp4
        $this->assertSame("videos/{$video->id}/original.mp4", $video->original_path);
        Storage::disk('local')->assertExists($video->original_path);
    }
}
