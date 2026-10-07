<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comment_mentions', function (Blueprint $table) {
            // Handle đúng như lúc viết bình luận (không '@', chữ thường). Người được
            // nhắc đổi handle thì FE thay chữ này bằng handle hiện tại của user_id,
            // nên lần nhắc tên vẫn trỏ đúng người.
            $table->string('handle', 30)->nullable()->after('user_id');
        });

        // Lần nhắc tên đã có đều được viết bằng handle hiện tại của người đó.
        DB::table('comment_mentions')->whereNull('handle')->orderBy('comment_id')->each(function ($row) {
            DB::table('comment_mentions')
                ->where('comment_id', $row->comment_id)
                ->where('user_id', $row->user_id)
                ->update(['handle' => DB::table('users')->where('id', $row->user_id)->value('username')]);
        });

        Schema::table('comment_mentions', function (Blueprint $table) {
            $table->string('handle', 30)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('comment_mentions', function (Blueprint $table) {
            $table->dropColumn('handle');
        });
    }
};
