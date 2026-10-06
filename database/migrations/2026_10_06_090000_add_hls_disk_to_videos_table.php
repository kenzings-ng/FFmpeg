<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Disk chứa HLS của từng video ('local' | 'r2'), để video cũ trên
            // local vẫn phát được sau khi config video.hls_disk đổi sang r2.
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
