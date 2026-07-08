<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Get the physical rooms of this type.
     */
    public function rooms()
    {
        return $this->hasMany(Room::class);
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
