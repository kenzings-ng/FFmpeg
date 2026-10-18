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
            // original_url / hls_url store internal paths rather than public URLs,
            // renamed to accurately reflect their updated purpose.
            $table->dropColumn(['original_url', 'hls_url']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_public')->after('title')->default(false);
            // Relative path on 'local' disk (storage/app), not a public URL.
            $table->string('original_path')->nullable()->after('is_public');
            $table->string('hls_playlist_path')->nullable()->after('original_path');
            // AES-128 key encrypting HLS segments, encrypted via Crypt (APP_KEY)
            // before storage — keys are never stored in plaintext.
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
