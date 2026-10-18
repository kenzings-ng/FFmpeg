<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // pending: just uploaded, job queued. processing: ffmpeg actively transcoding.
            // ready: hls_playlist_path is ready and playable. failed: job failed after
            // exhausting all retries. Previously the frontend could not distinguish between
            // in-progress and failed videos, inferring only from null hls_url.
            $table->string('status')->default('pending')->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
