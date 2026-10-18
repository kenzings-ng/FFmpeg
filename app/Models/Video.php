<?php

namespace App\Models;

use App\Video\StreamToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
     * HLS segment encryption key is never exposed, even when wrapped in Crypt.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'encrypted_key',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    /** All comments (top-level + replies), used for total counting. */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Top-level comments, newest first. */
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
     * Scope videos: public or owned by current authenticated user.
     * Used by directive @all(scopes: ["viewableBy"]).
     *
     * @param  array<string, mixed>  $args
     */
    public function scopeViewableBy(Builder $query, array $args = []): Builder
    {
        $user = Auth::guard('api')->user();

        // mine: true  -> only own videos (including private)
        // mine: false -> only public videos
        // omitted     -> public + own videos (legacy default, maintains backward compatibility)
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
     * Master playlist URL.
     * - HLS on R2: CDN (Worker) URL without token; player exchanges grant for stream token via streamAccess().
     * - HLS on local (legacy video) via VideoStreamController:
     *   - Public video: stable URL accessible to anyone.
     *   - Private video: temporary signed URL, accessible only by owner via GraphQL.
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
     * Poster image (stored encrypted, see PosterVault). On R2: signed URL with expiration
     * (StreamToken::signPoster), verified by Worker before decrypting to JPEG — accessible
     * only by users who can view the video (policy "view"), even for private videos.
     * On local: follows the same rules as HLS (public / signed URL).
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
     * HLS directory prefix on R2, e.g. "hls/12-AbC...". Defines the scope of the grant / stream token.
     */
    public function hlsPrefix(): ?string
    {
        return $this->hls_playlist_path ? dirname($this->hls_playlist_path) : null;
    }

    /**
     * Short-lived grant for player to exchange for a stream token at Worker.
     * Available only for READY videos on R2; granted to anyone with "view" policy access.
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

    /**
     * Check whether video has a complete and valid HLS rendition (master playlist and encoded renditions).
     */
    public function hasValidHls(?string $excludeHlsDir = null): bool
    {
        if (! $this->hls_playlist_path) {
            return false;
        }

        $disk = Storage::disk($this->hls_disk ?: 'local');
        if (! $disk->exists($this->hls_playlist_path)) {
            return false;
        }

        $query = $this->renditions()->whereNotNull('stream_inf');
        if ($excludeHlsDir) {
            $query->where('hls_dir', '!=', $excludeHlsDir);
        }

        return $query->exists();
    }
}
