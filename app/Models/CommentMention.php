<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mention in a comment: the handle as originally written + the referenced user.
 * Linked by user_id so if the user changes handle, the mention still resolves to the correct user.
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
