<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // pending: vừa upload, job chưa chạy. processing: ffmpeg đang chạy.
            // ready: hls_playlist_path đã có, phát được. failed: job lỗi hết
            // số lần retry. Trước đây FE không có cách nào biết video đang xử
            // lý hay đã lỗi, chỉ đoán qua hls_url null.
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
