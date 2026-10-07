<?php

namespace App\Models;

use App\Video\StreamToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'user_id',
        'is_public',
        'status',
        'original_path',
        'hls_playlist_path',
        'hls_disk',
        'poster_path',
        'encrypted_key',
    ];

    /**
     * Khóa mã hóa segment HLS không bao giờ lộ ra ngoài, kể cả đã bọc Crypt.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'encrypted_key',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    /** Mọi bình luận (gốc + trả lời), dùng để đếm tổng. */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Bình luận gốc, mới nhất trước. */
    public function topLevelComments(): HasMany
    {
        return $this->hasMany(Comment::class)->whereNull('parent_id')->latest()->orderByDesc('id');
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(VideoRendition::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Giới hạn danh sách video: public, hoặc của chính user đang đăng nhập.
     * Dùng cho directive @all(scopes: ["viewableBy"]).
     *
     * @param  array<string, mixed>  $args
     */
    public function scopeViewableBy(Builder $query, array $args = []): Builder
    {
        $user = Auth::guard('api')->user();

        // mine: true  -> chỉ video của chính mình (kể cả private)
        // mine: false -> chỉ video public
        // không truyền -> public + của chính mình (mặc định cũ, giữ tương thích ngược)
        if (($args['mine'] ?? null) === true) {
            return $query->where('user_id', $user?->id);
        }

        if (($args['mine'] ?? null) === false) {
            return $query->where('is_public', true);
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->where('is_public', true);

            if ($user) {
                $query->orWhere('user_id', $user->id);
            }
        });
    }

    /**
     * URL master playlist.
     * - HLS trên R2: URL trên CDN (Worker), không kèm token — player phải tự
     *   gắn stream token đổi được từ streamAccess().
     * - HLS trên local (video cũ), qua VideoStreamController:
     *   - Video public: URL ổn định, ai cũng xem được.
     *   - Video private: URL ký (signed) có hạn, chỉ owner lấy được từ GraphQL.
     */
    public function hlsUrl(): ?string
    {
        if (! $this->hls_playlist_path) {
            return null;
        }

        $file = basename($this->hls_playlist_path);

        if ($this->isOnR2()) {
            return config('video.cdn_url').'/'.$this->hls_playlist_path;
        }

        if ($this->is_public) {
            return route('videos.hls', ['video' => $this->id, 'file' => $file]);
        }

        return URL::temporarySignedRoute(
            'videos.hls',
            now()->addMinutes(30),
            ['video' => $this->id, 'file' => $file],
        );
    }

    /**
     * Ảnh bìa (lưu ở dạng mã hóa, xem PosterVault). Trên R2: URL có chữ ký và
     * hạn dùng (StreamToken::signPoster), Worker kiểm tra rồi mới giải mã trả
     * JPEG — chỉ ai thấy được video (policy "view") mới nhận được URL, kể cả
     * video private. Trên local: cùng quy tắc với HLS (public / URL ký).
     */
    public function posterUrl(): ?string
    {
        if (! $this->poster_path) {
            return null;
        }

        if ($this->isOnR2()) {
            return config('video.cdn_url').'/'.$this->poster_path.'?'
                .http_build_query(StreamToken::fromConfig()->signPoster($this->poster_path));
        }

        $parameters = ['video' => $this->id, 'file' => basename($this->poster_path)];

        return $this->is_public
            ? route('videos.hls', $parameters)
            : URL::temporarySignedRoute('videos.hls', now()->addHours(6), $parameters);
    }

    public function isOnR2(): bool
    {
        return $this->hls_disk === 'r2';
    }

    /**
     * Thư mục HLS trên R2, vd. "hls/12-AbC…". Là phạm vi của grant/stream token.
     */
    public function hlsPrefix(): ?string
    {
        return $this->hls_playlist_path ? dirname($this->hls_playlist_path) : null;
    }

    /**
     * Grant ngắn hạn để player đổi lấy stream token tại Worker. Chỉ có với
     * video đã READY trên R2; ai thấy được field Video (policy "view") thì
     * được cấp.
     *
     * @return array{playlist_url: string, token_url: string, grant: string, expires_at: int}|null
     */
    public function streamAccess(): ?array
    {
        if (! $this->isOnR2() || $this->status !== 'ready' || ! $this->hls_playlist_path) {
            return null;
        }

        $prefix = $this->hlsPrefix();

        return [
            'playlist_url' => $this->hlsUrl(),
            'token_url' => config('video.cdn_url')."/{$prefix}/token",
            ...StreamToken::fromConfig()->grant($prefix, (int) config('video.grant_ttl')),
        ];
    }
}
