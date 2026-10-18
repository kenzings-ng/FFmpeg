<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each rendition level of an encode run: encoded by an isolated job
        // (EncodeRenditionJob) with an individual AES-128 key.
        Schema::create('video_renditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            // HLS directory for this encode run (randomized name). Resegmenting encodes
            // to a new directory; previous rendition remains playable until the new one finishes.
            $table->string('hls_dir');
            $table->unsignedSmallInteger('height');
            // Randomized filename: prevents leaking resolution / video ID on storage.
            $table->string('playlist_name');
            $table->string('key_id', 64)->unique();
            $table->text('encrypted_key');
            // #EXT-X-STREAM-INF line for master playlist, populated once encoding completes.
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
