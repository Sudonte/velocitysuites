<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'ip_address',
    ];

    /**
     * Get the user associated with the activity log.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Best-effort "View" link for the affected record, resolved from
     * subject_type (a short model-name string, not a full class-string -
     * see Support\Activity::log()) + subject_id. Returns null for entries
     * with no subject (e.g. profile edits) or a subject type this hasn't
     * been taught to link yet, so callers can simply hide the link.
     */
    public function subjectUrl(): ?string
    {
        if (! $this->subject_type || ! $this->subject_id) {
            return null;
        }

        // 'reservation' entries show up in more than one role's activity
        // feed (Admin's Recent Activities, Manager's own feed, Receptionist's
        // Recent Booking Activities) - each role has its own equivalent
        // view-reservation page, and a role only has route access to its
        // own. A single hardcoded route here would 403/redirect whichever
        // role isn't that one, exactly the "sent to another role's page"
        // bug this module was built to avoid.
        // The log outlives the records it mentions; never link to one that's gone.
        if (in_array($this->subject_type, ['reservation', 'booking'], true)) {
            $model = $this->subject_type === 'reservation' ? Reservation::class : Booking::class;
            if (! $model::whereKey($this->subject_id)->exists()) {
                return null;
            }
        }

        if ($this->subject_type === 'booking') {
            $role = auth()->user()?->role;
            if ($role === 'receptionist') {
                return route('receptionist.bookings.show', $this->subject_id);
            }
            if (! in_array($role, ['admin', 'manager'], true)) {
                return null;
            }
            // Admin/Manager open a converted booking through its reservation.
            $reservationId = Booking::whereKey($this->subject_id)->value('reservation_id');

            return $reservationId
                ? route("{$role}.reservations.show", $reservationId)
                : route("{$role}.bookings.show", $this->subject_id);
        }

        if ($this->subject_type === 'reservation') {
            return match (auth()->user()?->role) {
                'admin' => route('admin.reservations.show', $this->subject_id),
                'manager' => route('manager.reservations.show', $this->subject_id),
                'receptionist' => route('receptionist.reservations.index', ['open' => $this->subject_id]),
                default => null,
            };
        }

        return match ($this->subject_type) {
            'user' => route('admin.users.show', $this->subject_id),
            'promotion' => route('admin.promotions.index'),
            'discount' => route('admin.discounts.index'),
            'staff_password_reset_request' => route('admin.users.password-requests.index'),
            'room' => auth()->user()?->role === 'admin' ? route('admin.rooms.edit', $this->subject_id) : null,
            'room_type' => auth()->user()?->role === 'admin' ? route('admin.room-types.show', $this->subject_id) : null,
            'amenity' => auth()->user()?->role === 'admin' ? route('admin.amenities.index') : null,
            'announcement' => auth()->user()?->role === 'admin' ? route('admin.announcements.index') : null,
            default => null,
        };
    }
}
