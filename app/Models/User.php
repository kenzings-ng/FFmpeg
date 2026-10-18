<?php

namespace App\Models;

use App\Support\Username;
use GraphQL\Error\Error;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract, OAuthenticatable
{
    use HasApiTokens, HasFactory, MustVerifyEmail, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'bio',
        'email',
        'password',
    ];

    /**
     * Every user has a handle: automatically generated if username is omitted.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->username = $user->username
                ? Username::normalize($user->username)
                : Username::generateRandom();
        });

        static::saving(function (User $user) {
            if ($user->isDirty('name') || ! $user->exists) {
                $user->name_search = Username::searchable((string) $user->name);
            }
        });
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /** Videos shown on channel page: public, ready, newest first. */
    public function publicVideos(): HasMany
    {
        return $this->hasMany(Video::class)
            ->where('is_public', true)
            ->where('status', 'ready')
            ->latest()
            ->orderByDesc('id');
    }

    /**
     * Resolve user by handle for channel page /@handle. Handles changed within the
     * reservation window still resolve to previous owner so old links redirect properly.
     */
    public static function findByHandle(string $handle): ?self
    {
        $handle = Username::normalize($handle);

        return static::where('username', $handle)->first()
            ?? static::whereKey(
                UsernameChange::where('username', $handle)
                    ->where('created_at', '>', now()->subDays(Username::CHANGE_WINDOW_DAYS))
                    ->latest('created_at')
                    ->value('user_id')
            )->first();
    }

    public function usernameChanges(): HasMany
    {
        return $this->hasMany(UsernameChange::class);
    }

    /**
     * Change handle (pre-validated via App\Rules\Username): records previous handle
     * to enforce change limits and handle reservations.
     *
     * @throws Error when change limit is exceeded.
     */
    public function changeUsername(string $value): void
    {
        $username = Username::normalize($value);

        if ($username === $this->username) {
            return;
        }

        $recent = $this->usernameChanges()
            ->where('created_at', '>', now()->subDays(Username::CHANGE_WINDOW_DAYS))
            ->count();

        if ($recent >= Username::CHANGE_LIMIT) {
            throw new Error(
                'You can only change your username '.Username::CHANGE_LIMIT.' times in '.Username::CHANGE_WINDOW_DAYS.' days.'
            );
        }

        $this->usernameChanges()->create(['username' => $this->username]);
        $this->username = $username;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'name_search',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * email/email_verified_at are only visible to the account owner.
     * Querying user()/users() must not leak email addresses to other users.
     */
    private function isViewingSelf(): bool
    {
        return Auth::guard('api')->id() === $this->id;
    }

    public function emailForDisplay(): ?string
    {
        return $this->isViewingSelf() ? $this->email : null;
    }

    public function emailVerifiedAtForDisplay(): ?Carbon
    {
        return $this->isViewingSelf() ? $this->email_verified_at : null;
    }
}
