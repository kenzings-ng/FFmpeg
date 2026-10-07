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
            // Chỉ 2 tầng: null = bình luận gốc; khác null = trả lời, LUÔN trỏ tới
            // bình luận gốc (trả lời một trả lời vẫn nằm trong luồng của gốc).
            // Xóa bình luận gốc thì xóa luôn mọi trả lời.
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            // Người được trả lời khi bấm "Phản hồi" trên một trả lời (hiện "@Tên").
            $table->foreignId('reply_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            // Bình luận gốc của một video (mới nhất trước) và trả lời của một gốc (cũ nhất trước).
            $table->index(['video_id', 'parent_id', 'created_at']);
            $table->index(['parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
