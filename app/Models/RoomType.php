<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rate',
        'capacity',
        'description',
        'number_format',
        'status',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];

    protected $appends = [
        'image_url',
    ];

    /**
     * `rooms` gets eager-loaded (constrained to one image-bearing row) by
     * PublicRoomController/Api\RoomController purely so getImageUrlAttribute()
     * can avoid an N+1 query - it must never actually serialize into a
     * response, or individual room data (room_number etc.) would leak to
     * guests who are only supposed to see the grouped type. Blade views
     * that need real per-room listings (e.g. admin/room-types/show) pass
     * rooms as their own separate variable, not via this relation, so
     * hiding it here doesn't affect them.
     */
    protected $hidden = [
        'rooms',
    ];

    /**
     * Get the physical rooms of this type.
     */
    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    /**
     * A room type has no image of its own (there's no per-type upload UI -
     * guests never see individual rooms, so one representative photo from
     * any of the type's physical rooms stands in). If the `rooms` relation
     * is already eager-loaded (see PublicRoomController/Api\RoomController,
     * which constrain it to whereNotNull('image')->limit(1) to avoid
     * N+1s), this reads from that; otherwise it queries directly.
     */
    public function getImageUrlAttribute(): ?string
    {
        $room = $this->relationLoaded('rooms')
            ? $this->rooms->first(fn ($r) => $r->image !== null)
            : $this->rooms()->whereNotNull('image')->first();

        return $room && $room->image ? Storage::disk('public')->url($room->image) : null;
    }

    /**
     * Get the reservations requesting this type.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Generate the next $count room numbers from this type's number_format.
     * The format's run of '#' is a zero-padded sequence counter, e.g.
     * "1##" -> 101, 102... "D-##" -> D-01, D-02...
     * Continues after the highest existing number matching the format and
     * skips any number already taken by another room (numbers are globally
     * unique). Returns fewer than $count only if the format's digit width
     * runs out of room (e.g. "1##" caps at 199).
     */
    public function nextRoomNumbers(int $count): array
    {
        $format = $this->number_format ?: '###';

        if (!preg_match('/^(.*?)(#+)(.*)$/', $format, $m)) {
            // No '#' placeholder: treat the whole format as a prefix with
            // a two-digit counter appended.
            [$prefix, $width, $suffix] = [$format, 2, ''];
        } else {
            [$prefix, $width, $suffix] = [$m[1], strlen($m[2]), $m[3]];
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{' . $width . '})' . preg_quote($suffix, '/') . '$/';
        $start = 0;
        foreach (Room::pluck('room_number') as $existing) {
            if (preg_match($pattern, $existing, $hit)) {
                $start = max($start, (int) $hit[1]);
            }
        }

        $taken = Room::pluck('room_number')->flip();
        $numbers = [];
        $seq = $start;
        $max = (10 ** $width) - 1;

        while (count($numbers) < $count && $seq < $max) {
            $seq++;
            $candidate = $prefix . str_pad((string) $seq, $width, '0', STR_PAD_LEFT) . $suffix;
            if (!isset($taken[$candidate])) {
                $numbers[] = $candidate;
            }
        }

        return $numbers;
    }
}
