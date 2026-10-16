<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RoomImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_type_id',
        'room_id',
        'image_path',
        'sort_order',
    ];

    protected $appends = [
        'url',
    ];

    /** The room type whose gallery this photo belongs to. */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * The room this photo belonged to before galleries moved to the room type
     * (null for room type photos).
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }
}
