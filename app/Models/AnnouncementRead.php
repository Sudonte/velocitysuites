<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A guest has opened (read) an announcement's System notification on the website. Deleting the row marks it unread again. */
class AnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'announcement_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
