<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lịch sử đổi handle: giới hạn số lần đổi và giữ handle cũ một thời gian
 * (xem App\Support\Username).
 */
class UsernameChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'username'];
}
