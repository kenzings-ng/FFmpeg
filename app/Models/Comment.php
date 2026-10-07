<?php

namespace App\Models;

use App\Support\Username;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bình luận 2 tầng kiểu YouTube: bình luận gốc (parent_id = null) và trả lời
 * (parent_id = id bình luận gốc). Quyền xem đi theo quyền xem video.
 */
class Comment extends Model
{
    public const MAX_LENGTH = 2000;

    protected $fillable = [
        'video_id',
        'user_id',
        'parent_id',
        'reply_to_user_id',
        'body',
        'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replyToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }

    /** Các lần nhắc tên (@handle như lúc viết → user). */
    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class);
    }

    /**
     * Ghi lại người được nhắc theo nội dung hiện tại: chỉ handle đang thuộc về
     * một user có thật, lưu kèm chữ đã gõ để FE thay bằng handle hiện tại.
     */
    public function syncMentions(): void
    {
        $handles = Username::mentionedIn($this->body);
        $users = $handles ? User::whereIn('username', $handles)->pluck('id', 'username') : collect();

        $this->mentions()->delete();
        $this->mentions()->createMany(
            $users->map(fn (int $userId, string $handle) => ['user_id' => $userId, 'handle' => $handle])->values()->all()
        );
    }

    /** Trả lời của bình luận gốc, cũ nhất trước (đọc như hội thoại). */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest()->orderBy('id');
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
