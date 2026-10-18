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
     *
     * @Tên người dùng (kiểu Facebook).
     */
    public function syncMentions(): void
    {
        $matchedMentions = collect();

        // 1. Tìm tất cả các vị trí có thể là mention: @ theo sau bởi tối đa 255 ký tự (khớp users.name & comment_mentions.handle).
        // Không khớp email (không có ký tự chữ/số/._@ đứng trước @).
        if (! preg_match_all('/(?<![A-Za-z0-9._@])@([^\n\r@]{1,255})/u', $this->body, $matches)) {
            $this->mentions()->delete();

            return;
        }

        // 2. Thu thập các candidate display name và candidate handle từ text sau mỗi '@'
        $displayCandidates = [];
        $handleCandidates = [];

        foreach ($matches[1] as $textAfterAt) {
            // Tách các từ để sinh candidate display name (không quá 255 ký tự)
            $words = preg_split('/\s+/u', trim($textAfterAt));
            if (! empty($words)) {
                $phrase = '';
                foreach ($words as $i => $word) {
                    $phrase = ($i === 0) ? $word : $phrase.' '.$word;
                    if (mb_strlen($phrase) > 255) {
                        break;
                    }
                    $cleanPhrase = trim($phrase, " \t\n\r\0\x0B.,!?:;)\]}\"'");
                    if ($cleanPhrase !== '') {
                        $displayCandidates[] = $cleanPhrase;
                    }
                }
            }

            if (preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9._]*[A-Za-z0-9])?)/', $textAfterAt, $hMatch)) {
                $handleCandidates[] = Username::normalize($hMatch[1]);
            }
        }

        $displayCandidates = array_values(array_unique($displayCandidates));
        $handleCandidates = array_values(array_unique($handleCandidates));

        // 3. Query DB chính xác theo các candidate (không load toàn bộ comment của video hay like prefix giới hạn 50)
        $usersByName = collect();
        if (! empty($displayCandidates)) {
            $isSqlite = $this->getConnection()->getDriverName() === 'sqlite';
            $usersByName = User::where(function ($query) use ($displayCandidates, $isSqlite) {
                $query->whereIn('name', $displayCandidates);
                if ($isSqlite) {
                    foreach ($displayCandidates as $candidate) {
                        $query->orWhere('name', 'like', $candidate);
                    }
                }
            })->get();
        }

        $usersByHandle = ! empty($handleCandidates)
            ? User::whereIn('username', $handleCandidates)->get()->keyBy('username')
            : collect();

        // Kiểm tra xem trong số các user có tên khớp candidate, ai là người đã bình luận trên video này
        $candidateUserIds = $usersByName->pluck('id')->all();
        $participantIds = [];
        if (! empty($candidateUserIds)) {
            $participantIds = self::where('video_id', $this->video_id)
                ->whereIn('user_id', $candidateUserIds)
                ->distinct()
                ->pluck('user_id')
                ->flip()
                ->all();
        }

        $videoOwnerId = $this->video?->user_id;

        // Sắp xếp các user theo tên: ưu tiên tên dài hơn trước (vd "Nguyễn Văn Bảo" trước "Nguyễn Văn"),
        // nếu cùng độ dài thì ưu tiên chủ video -> người tham gia bình luận -> người khác.
        $sortedUsersByName = $usersByName->sort(function (User $a, User $b) use ($videoOwnerId, $participantIds) {
            $lenA = mb_strlen($a->name);
            $lenB = mb_strlen($b->name);
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA;
            }

            $scoreA = ($a->id === $videoOwnerId) ? 2 : (isset($participantIds[$a->id]) ? 1 : 0);
            $scoreB = ($b->id === $videoOwnerId) ? 2 : (isset($participantIds[$b->id]) ? 1 : 0);

            return $scoreB <=> $scoreA;
        })->values();

        // 4. Khớp từng vị trí '@': ưu tiên display name trước để tránh prefix của tên bị nhận nhầm thành handle
        foreach ($matches[1] as $textAfterAt) {
            $matchedUser = null;
            $matchedHandle = null;

            // Kiểm tra handle candidate có ở vị trí này không
            $hasHandleCandidate = preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9._]*[A-Za-z0-9])?)/', $textAfterAt, $hMatch);
            $handleToken = $hasHandleCandidate ? $hMatch[1] : '';
            $handleTokenLen = mb_strlen($handleToken);

            // Kiểm tra display name
            foreach ($sortedUsersByName as $user) {
                $name = $user->name;
                $len = mb_strlen($name);
                $prefix = mb_substr($textAfterAt, 0, $len);

                if (mb_strtolower($prefix) === mb_strtolower($name)) {
                    $charAfter = mb_substr($textAfterAt, $len, 1);
                    if ($charAfter === '' || preg_match('/[\s,;:!?.)\]}\'"]/u', $charAfter)) {
                        // Display name khớp:
                        // Nếu display name dài hơn handle token (vd "@John Doe" dài hơn "@John"),
                        // hoặc handle token này không tồn tại trong hệ thống,
                        // hoặc display name có khoảng trắng / ký tự unicode không phải ASCII handle,
                        // thì display name được ưu tiên tuyệt đối.
                        $normHandle = Username::normalize($handleToken);
                        $handleExists = $handleTokenLen > 0 && isset($usersByHandle[$normHandle]);

                        if ($len > $handleTokenLen || ! $handleExists || str_contains($name, ' ') || ! preg_match(Username::PATTERN, $normHandle)) {
                            $matchedUser = $user;
                            $matchedHandle = $name;
                            break;
                        }
                    }
                }
            }

            // Nếu không khớp display name, kiểm tra @handle
            if (! $matchedUser && $hasHandleCandidate) {
                $handle = Username::normalize($handleToken);
                $charAfter = mb_substr($textAfterAt, $handleTokenLen, 1);

                if (($charAfter === '' || preg_match('/[\s,;:!?.)\]}\'"]/u', $charAfter)) && isset($usersByHandle[$handle])) {
                    $user = $usersByHandle[$handle];
                    $matchedUser = $user;
                    $matchedHandle = $user->username;
                }
            }

            if ($matchedUser) {
                $matchedMentions->put($matchedUser->id, [
                    'user_id' => $matchedUser->id,
                    'handle' => $matchedHandle,
                ]);
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
