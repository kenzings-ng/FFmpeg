<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Strict 2-level threading: null = root comment; non-null = reply, ALWAYS pointing to
            // the root comment (replying to a reply still attaches to the root thread).
            // Deleting the root comment cascades to delete all replies.
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            // User replied to when clicking "Reply" on a nested reply (displays "@Name").
            $table->foreignId('reply_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            // Root comments for a video (newest first) and replies under a root (oldest first).
            $table->index(['video_id', 'parent_id', 'created_at']);
            $table->index(['parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
