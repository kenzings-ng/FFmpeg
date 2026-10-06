<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mỗi mức chất lượng của một lần encode: encode bằng job riêng
        // (EncodeRenditionJob) và có khóa AES-128 riêng.
        Schema::create('video_renditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            // Thư mục HLS của lần encode này (tên ngẫu nhiên). Encode lại ra
            // thư mục mới; bản cũ vẫn phát được tới khi bản mới xong.
            $table->string('hls_dir');
            $table->unsignedSmallInteger('height');
            // Tên file ngẫu nhiên: trên storage không lộ độ phân giải / video id.
            $table->string('playlist_name');
            $table->string('key_id', 64)->unique();
            $table->text('encrypted_key');
            // Dòng #EXT-X-STREAM-INF cho master playlist, có khi encode xong.
            $table->text('stream_inf')->nullable();
            $table->timestamp('encoded_at')->nullable();
            $table->timestamps();

            $table->index(['video_id', 'hls_dir']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_renditions');
    }
};
