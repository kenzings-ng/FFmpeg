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

        // Simulate client sending a valid MP4 video but specifying client filename as video.exe
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

        // Extension must be .mp4 (inferred from MIME content), never client-supplied .exe
        $this->assertSame("videos/{$video->id}/original.mp4", $video->original_path);
        Storage::disk('local')->assertExists($video->original_path);
    }

    public function test_upload_video_falls_back_to_mp4_for_unknown_extension(): void
    {
        Queue::fake([SegmentVideoJob::class]);
        Storage::fake('local');

        $user = User::factory()->create();
        Passport::actingAs($user);

        // Valid video/* MIME type whose derived extension (3gp) is not in the whitelist
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

        // Fallback to .mp4
        $this->assertSame("videos/{$video->id}/original.mp4", $video->original_path);
        Storage::disk('local')->assertExists($video->original_path);
    }
}
