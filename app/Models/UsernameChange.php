<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Username change history: used to rate limit changes and reserve old handles temporarily
 * (see App\Support\Username).
 */
class UsernameChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'username'];
}
