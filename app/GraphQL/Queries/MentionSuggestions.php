<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Support\Username;
use GraphQL\Error\Error;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class MentionSuggestions
{
    private const LIMIT = 8;

    /**
     * Gợi ý khi gõ "@" trong bình luận của một video: người đã tham gia bình
     * luận video đó và chủ video trước, rồi tới người dùng khác khớp handle / tên.
     *
     * @param  null  $_
     * @param  array{video_id: string, query?: string|null}  $args
     * @return Collection<int, User>
     */
    public function __invoke($_, array $args): Collection
    {
        $me = Auth::guard('api')->user();
        $video = Video::find($args['video_id']);

        if (! $video || Gate::forUser($me)->denies('view', $video)) {
            throw new Error('Không tìm thấy video.');
        }

        $raw = (string) ($args['query'] ?? '');
        // Handle chỉ có a-z 0-9 . _ ; tên thì so khớp không dấu qua cột name_search.
        $term = Username::normalize($raw);
        $nameTerm = Username::searchable(ltrim($raw, '@'));

        $participantIds = Comment::where('video_id', $video->id)
            ->select('user_id')
            ->groupBy('user_id')
            ->orderByRaw('MAX(created_at) DESC')
            ->limit(50)
            ->pluck('user_id')
            ->prepend($video->user_id)
            ->unique()
            ->reject(fn ($id) => $id === $me->id)
            ->values();

        $participants = User::whereIn('id', $participantIds)
            ->when($term !== '', fn (Builder $q) => $this->matching($q, $term, $nameTerm))
            ->get()
            ->sortBy(fn (User $user) => $participantIds->search($user->id))
            ->take(self::LIMIT);

        if ($term === '' || $participants->count() >= self::LIMIT) {
            return $participants->values();
        }

        $others = $this->matching(User::query(), $term, $nameTerm)
            ->whereNotIn('id', $participantIds->push($me->id))
            // Khớp đầu handle trước, rồi theo handle ngắn (gần đúng hơn).
            ->orderByRaw("CASE WHEN username LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$this->prefix($term)])
            ->orderByRaw('LENGTH(username)')
            ->limit(self::LIMIT - $participants->count())
            ->get();

        return $participants->values()->concat($others);
    }

    private function matching(Builder $query, string $term, string $nameTerm): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereRaw("username LIKE ? ESCAPE '!'", [$this->prefix($term)])
            // Đầu tên hoặc đầu một từ trong tên: "binh" khớp "Bình An" và "Lê Bình".
            ->orWhereRaw("name_search LIKE ? ESCAPE '!'", [$this->prefix($nameTerm)])
            ->orWhereRaw("name_search LIKE ? ESCAPE '!'", ['% '.$this->prefix($nameTerm)]));
    }

    private function prefix(string $term): string
    {
        return $this->escape($term).'%';
    }

    /** Escape ký tự đặc biệt của LIKE ('_' có trong handle). */
    private function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
