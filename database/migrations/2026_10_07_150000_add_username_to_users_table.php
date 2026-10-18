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
            // Accent-stripped, lowercase name (e.g. "Dang Binh" -> "dang binh") for mention suggestions
            // to match diacritic-insensitively across DBs (MySQL default collations do not treat special chars as basic letters).
            $table->string('name_search')->default('')->after('username')->index();
        });

        // Old handles (username = previously released handle), used to rate limit changes and reserve handles.
        Schema::create('username_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', Username::MAX);
            $table->timestamp('created_at')->nullable();

            $table->index(['username', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Existing users: generate handle from name.
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
