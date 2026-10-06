<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class VideoRendition extends Model
{
    protected $fillable = [
        'video_id',
        'hls_dir',
        'height',
        'playlist_name',
        'key_id',
        'encrypted_key',
        'stream_inf',
        'encoded_at',
    ];

    protected $hidden = [
        'encrypted_key',
    ];

    protected $casts = [
        'encoded_at' => 'datetime',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /** Khóa AES-128 thô (16 byte). */
    public function key(): string
    {
        return Crypt::decryptString($this->encrypted_key);
    }

    /** Tên file khóa ghi trong playlist (URI tương đối, cùng thư mục với playlist). */
    public function keyFilename(): string
    {
        return "{$this->key_id}.key";
    }
}
