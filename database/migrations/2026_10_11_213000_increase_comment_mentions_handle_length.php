<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comment_mentions', function (Blueprint $table) {
            $table->string('handle', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('comment_mentions', function (Blueprint $table) {
            $table->string('handle', 30)->change();
        });
    }
};
