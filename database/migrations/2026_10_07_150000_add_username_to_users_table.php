<?php

use App\Support\Username;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', Username::MAX)->nullable()->unique()->after('name');
            // Tên bỏ dấu, chữ thường ("Đặng Bình" → "dang binh") để gợi ý @nhắc tên
            // khớp không dấu giống nhau trên mọi DB (collation MySQL không coi "đ" là "d").
            $table->string('name_search')->default('')->after('username')->index();
        });

        // Handle cũ (username = handle đã bỏ), để giới hạn số lần đổi và giữ chỗ.
        Schema::create('username_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', Username::MAX);
            $table->timestamp('created_at')->nullable();

            $table->index(['username', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Người dùng đã có: sinh handle từ tên.
        DB::table('users')->whereNull('username')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'username' => Username::generate($user->name),
                'name_search' => Username::searchable($user->name),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', Username::MAX)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('username_changes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropIndex(['name_search']);
            $table->dropColumn(['username', 'name_search']);
        });
    }
};
