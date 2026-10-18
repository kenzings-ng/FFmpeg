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
            // Handle as written in the comment (without '@', lowercase). If the mentioned
            // user changes their handle, frontend replaces this string with user_id's current handle,
            // preserving mention resolution to the intended user.
            $table->string('handle', 30)->nullable()->after('user_id');
        });

        // Existing mentions were all written using the user's current handle at the time.
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
