<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
