<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'device_name',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns the token.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
