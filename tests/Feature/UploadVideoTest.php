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

        $tempVideo = tempnam(sys_get_temp_dir(), 'test_vid').'.mp4';
        exec("ffmpeg -y -f lavfi -i testsrc=duration=1:size=320x240:rate=30 {$tempVideo} 2>/dev/null");

        // Giả lập client gửi file video thật nhưng đặt client filename là video.exe
        $file = new UploadedFile($tempVideo, 'video.exe', 'video/mp4', null, true);

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

        // Đuôi mở rộng phải là .mp4 (dựa trên nội dung mime), không bao giờ lấy .php từ client
        $this->assertSame("videos/{$video->id}/original.mp4", $video->original_path);
        Storage::disk('local')->assertExists($video->original_path);
    }
}
