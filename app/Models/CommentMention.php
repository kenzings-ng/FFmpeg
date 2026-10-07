<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần nhắc tên trong bình luận: handle như lúc viết + người được nhắc.
 * Liên kết theo user_id nên người đó đổi handle vẫn trỏ đúng người.
 */
class CommentMention extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['comment_id', 'user_id', 'handle'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
