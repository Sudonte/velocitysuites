<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountReactivation extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'reactivation_token',
        'otp_hash',
        'attempts',
        'expires_at',
        'last_sent_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
