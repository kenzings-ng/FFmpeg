<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Ảnh bìa JPEG, cùng disk với HLS (videos.hls_disk).
            $table->string('poster_path')->nullable()->after('hls_disk');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }
};
