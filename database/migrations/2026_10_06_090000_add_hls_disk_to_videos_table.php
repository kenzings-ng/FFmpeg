<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Storage disk containing HLS files for each video ('local' | 'r2'), allowing existing
            // videos on local disk to remain playable after switching video.hls_disk config to r2.
            $table->string('hls_disk', 16)->default('local')->after('hls_playlist_path');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('hls_disk');
        });
    }
};
