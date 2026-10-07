<?php

namespace App\Models;

use App\Support\Username;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
     * Mọi user đều có handle: không truyền username thì tự sinh từ tên.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->username = $user->username
                ? Username::normalize($user->username)
                : Username::generate((string) $user->name);
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

    /** Video hiện trên trang kênh: công khai, đã xử lý xong, mới nhất trước. */
    public function publicVideos(): HasMany
    {
        return $this->hasMany(Video::class)
            ->where('is_public', true)
            ->where('status', 'ready')
            ->latest()
            ->orderByDesc('id');
    }

    /**
     * Tìm user theo handle cho trang kênh /@handle. Handle vừa đổi (còn trong
     * thời gian giữ chỗ) vẫn tìm ra chủ cũ, để link cũ chuyển sang handle mới.
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
     * Đổi handle (đã validate bằng App\Rules\Username): ghi lại handle cũ để
     * giới hạn số lần đổi và giữ chỗ handle cũ.
     *
     * @throws \GraphQL\Error\Error khi đã đổi quá số lần cho phép.
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
            throw new \GraphQL\Error\Error(
                'Bạn chỉ được đổi tên người dùng '.Username::CHANGE_LIMIT.' lần trong '.Username::CHANGE_WINDOW_DAYS.' ngày.'
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
     * email/email_verified_at chỉ lộ cho chính chủ. Bất kỳ user nào đã đăng
     * nhập trước đây đều xem được email của MỌI user khác qua query
     * user()/users() — đây là rò rỉ thông tin, không phải hành vi mong muốn
     * (app này không có trang danh sách user, không có khái niệm "công khai
     * hồ sơ" như mạng xã hội).
     */
    private function isViewingSelf(): bool
    {
        return Auth::guard('api')->id() === $this->id;
    }

    public function emailForDisplay(): ?string
    {
        return $this->isViewingSelf() ? $this->email : null;
    }

    public function emailVerifiedAtForDisplay(): ?\Illuminate\Support\Carbon
    {
        return $this->isViewingSelf() ? $this->email_verified_at : null;
    }
}
