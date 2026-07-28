<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationOtp extends Model
{
    protected $fillable = [
        'email',
        'otp',
        'payload',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
