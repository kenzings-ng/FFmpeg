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
     * Ghi lại người được nhắc theo nội dung hiện tại: hỗ trợ cả @handle lẫn
     * @Tên người dùng (kiểu Facebook).
     */
    public function syncMentions(): void
    {
        $matchedMentions = collect();

        // 1. Tìm các @handle truyền thống (a-z0-9._)
        $handles = Username::mentionedIn($this->body);
        if (! empty($handles)) {
            $byUsername = User::whereIn('username', $handles)->get();
            foreach ($byUsername as $user) {
                $matchedMentions->put($user->id, [
                    'user_id' => $user->id,
                    'handle' => $user->username,
                ]);
            }
        }

        // 2. Tìm các @Tên người dùng (hỗ trợ tiếng Việt có dấu, khoảng trắng)
        if (preg_match_all('/(?:^|(?<=[\s(]))@([^\n\r@]{1,60})/u', $this->body, $matches)) {
            // Lấy danh sách user tham gia video + chủ video
            $relevantUserIds = self::where('video_id', $this->video_id)
                ->pluck('user_id')
                ->prepend($this->video?->user_id)
                ->filter()
                ->unique();

            $users = User::whereIn('id', $relevantUserIds)->get();

            // Tìm thêm user trong hệ thống theo các từ đầu tiên sau '@'
            $firstWords = collect($matches[1])
                ->map(fn ($text) => trim(explode(' ', trim($text))[0] ?? ''))
                ->filter()
                ->unique();

            if ($firstWords->isNotEmpty()) {
                $otherUsers = User::where(function ($query) use ($firstWords) {
                    foreach ($firstWords as $word) {
                        $query->orWhere('name', 'like', $word.'%');
                    }
                })->limit(50)->get();

                $users = $users->concat($otherUsers)->unique('id');
            }

            // Sắp xếp ưu tiên tên dài hơn trước (vd: "Nguyễn Văn Bảo" trước "Nguyễn Văn")
            $sortedUsers = $users->sortByDesc(fn (User $u) => mb_strlen($u->name));

            foreach ($matches[1] as $textAfterAt) {
                foreach ($sortedUsers as $user) {
                    $name = $user->name;
                    $len = mb_strlen($name);
                    $prefix = mb_substr($textAfterAt, 0, $len);

                    if (mb_strtolower($prefix) === mb_strtolower($name)) {
                        $charAfter = mb_substr($textAfterAt, $len, 1);
                        if ($charAfter === '' || preg_match('/[\s,;:!?]/u', $charAfter)) {
                            $matchedMentions->put($user->id, [
                                'user_id' => $user->id,
                                'handle' => $name,
                            ]);
                            break;
                        }
                    }
                }
            }
        }

        $this->mentions()->delete();
        if ($matchedMentions->isNotEmpty()) {
            $this->mentions()->createMany($matchedMentions->values()->all());
        }
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
