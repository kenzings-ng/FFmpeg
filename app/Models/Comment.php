<?php

namespace App\Models;

use App\Support\Username;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 2-level comments (YouTube style): top-level comments (parent_id = null) and replies
 * (parent_id = id of root comment). View permissions follow video view policy.
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

    /** Mentions recorded for this comment (@handle / @Display Name -> user). */
    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class);
    }

    /**
     * Sync mentions based on current comment body: supports both @handle
     * and @Display Name (Facebook style).
     */
    public function syncMentions(): void
    {
        $matchedMentions = collect();

        // 1. Find all possible mention positions: @ followed by up to 255 characters (matching users.name & comment_mentions.handle).
        // Does not match email addresses (no alphanumeric/._@ characters immediately preceding @).
        if (! preg_match_all('/(?<![A-Za-z0-9._@])@([^\n\r@]{1,255})/u', $this->body, $matches)) {
            $this->mentions()->delete();

            return;
        }

        // 2. Collect candidate display names and candidate handles from text after each '@'
        $displayCandidates = [];
        $handleCandidates = [];

        foreach ($matches[1] as $textAfterAt) {
            // Split words to generate candidate display names (up to 255 characters)
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

        // 3. Query DB precisely for candidates (no unbounded comment loading or 50-row limit prefix query)
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

        // Check which matching candidates are participants who have already commented on this video
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

        // Sort candidate users by name: prefer longer names first (e.g. "Nguyen Van Bao" before "Nguyen Van"),
        // then prioritize video owner -> comment participants -> other users.
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

        // 4. Match each '@' position: prefer display name first to avoid name prefixes being misidentified as handles
        foreach ($matches[1] as $textAfterAt) {
            $matchedUser = null;
            $matchedHandle = null;

            // Check if a handle candidate exists at this position
            $hasHandleCandidate = preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9._]*[A-Za-z0-9])?)/', $textAfterAt, $hMatch);
            $handleToken = $hasHandleCandidate ? $hMatch[1] : '';
            $handleTokenLen = mb_strlen($handleToken);

            // Check display name candidates
            foreach ($sortedUsersByName as $user) {
                $name = $user->name;
                $len = mb_strlen($name);
                $prefix = mb_substr($textAfterAt, 0, $len);

                if (mb_strtolower($prefix) === mb_strtolower($name)) {
                    $charAfter = mb_substr($textAfterAt, $len, 1);
                    if ($charAfter === '' || preg_match('/[\s,;:!?.)\]}\'"]/u', $charAfter)) {
                        // Display name match:
                        // If display name is longer than handle token (e.g. "@John Doe" longer than "@John"),
                        // or handle token does not exist in system,
                        // or display name has spaces / unicode characters not matching ASCII handle,
                        // prioritize the display name.
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

            // If no display name matched, check @handle candidate
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

    /** Replies to root comment, oldest first (chronological conversation). */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest()->orderBy('id');
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
