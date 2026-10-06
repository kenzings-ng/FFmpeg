<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // original_url / hls_url chứa đường dẫn nội bộ chứ không phải URL
            // công khai nữa, nên đổi tên cho đúng với vai trò mới.
            $table->dropColumn(['original_url', 'hls_url']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_public')->after('title')->default(false);
            // Đường dẫn tương đối trên disk 'local' (storage/app), không phải URL công khai.
            $table->string('original_path')->nullable()->after('is_public');
            $table->string('hls_playlist_path')->nullable()->after('original_path');
            // Khóa AES-128 mã hóa segment HLS, được bọc lại bằng Crypt (APP_KEY)
            // trước khi lưu — không bao giờ lưu khóa ở dạng thô.
            $table->text('encrypted_key')->nullable()->after('hls_playlist_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['is_public', 'original_path', 'hls_playlist_path', 'encrypted_key']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->string('original_url')->nullable();
            $table->string('hls_url')->nullable();
        });
    }
};
