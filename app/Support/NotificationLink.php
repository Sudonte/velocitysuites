<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;

/**
 * Where a notification leads for the user viewing it - the "Go to ..."
 * button in the notification modal. Returns ['url' => ..., 'label' => ...]
 * or null when there's no page worth opening (announcements, promotions,
 * or a stay the viewer's role has no page for).
 *
 * notifications.reference_id is untyped: most senders pass the reservation
 * id when the stay has one and the booking id otherwise
 * ("reservation_id ?? booking id"), and the two id ranges overlap. The
 * title tells us which for most notifications; for the rest both are tried
 * and the one belonging to this notification's guest wins.
 */
final class NotificationLink
{
    /** Titles whose reference_id is always a (direct) booking id. */
    private const BOOKING_TITLES = ['New Booking', 'Booking Pending', 'Booking Cancelled', 'Booking Cancelled - No Show'];

    /** Titles whose reference_id is always a reservation id. */
    private const RESERVATION_TITLES = ['New Reservation', 'Payment Deadline Approaching', 'New Amenity Request', 'Amenity Request Approved', 'Amenity Request Rejected'];

    public static function for(Notification $notification, User $viewer): ?array
    {
        $role = $viewer->role;
        $category = $notification->category ?? 'general';

        if ($category === 'account' && $role === 'admin') {
            return str_contains($notification->title, 'Password')
                ? ['url' => route('admin.users.password-requests.index'), 'label' => 'Open Password Reset Requests']
                : ['url' => route('admin.users.index'), 'label' => 'Open Users'];
        }
        if ($category === 'room' && $role === 'admin') {
            return ['url' => route('admin.room-types.index'), 'label' => 'Open Rooms'];
        }
        if ($category === 'amenity' && $role === 'admin') {
            return ['url' => route('admin.amenities.index'), 'label' => 'Open Amenities'];
        }
        if ($category === 'amenity' && $role === 'receptionist') {
            return ['url' => route('receptionist.amenities.index'), 'label' => 'Open Amenity Requests'];
        }
        if (in_array($category, ['announcement', 'promotion', 'general', 'account', 'room'], true) || ! $notification->reference_id) {
            return null;
        }

        $stay = self::resolveStay($notification, $viewer);
        if (! $stay) {
            return null;
        }

        return self::stayLink($stay, $role);
    }

    /** @return Reservation|Booking|null - a converted booking resolves to its reservation. */
    private static function resolveStay(Notification $notification, User $viewer): Reservation|Booking|null
    {
        $ref = (int) $notification->reference_id;
        $title = $notification->title;

        if (in_array($title, self::BOOKING_TITLES, true)) {
            $booking = Booking::find($ref);

            return $booking?->reservation ?? $booking;
        }
        if (in_array($title, self::RESERVATION_TITLES, true) || str_starts_with($title, 'Reservation ')) {
            return Reservation::find($ref);
        }

        // "reservation_id ?? booking id" senders (check-in/out, payments...).
        $reservation = Reservation::find($ref);
        $directBooking = Booking::whereNull('reservation_id')->find($ref);
        if (! $reservation || ! $directBooking) {
            return $reservation ?? $directBooking;
        }

        return self::belongsToNotification($directBooking, $notification, $viewer) && ! self::belongsToNotification($reservation, $notification, $viewer)
            ? $directBooking
            : $reservation;
    }

    /** Guests: is it their own stay? Staff: does the message name this stay's guest? */
    private static function belongsToNotification(Reservation|Booking $stay, Notification $notification, User $viewer): bool
    {
        if ($viewer->role === 'guest') {
            $ownerId = $stay instanceof Reservation ? $stay->guest?->user_id : $stay->account_guest?->user_id;

            return $ownerId === $viewer->id;
        }
        $name = trim((string) $stay->guest_display_name);

        return $name !== '' && str_contains($notification->message, $name);
    }

    private static function stayLink(Reservation|Booking $stay, string $role): ?array
    {
        $booking = $stay instanceof Booking ? $stay : $stay->booking;
        $label = $booking ? "View Booking #{$booking->id}" : "View Reservation #{$stay->id}";

        return match ($role) {
            'admin', 'manager' => [
                'url' => $stay instanceof Reservation ? route("{$role}.reservations.show", $stay) : route("{$role}.bookings.show", $stay),
                'label' => $label,
            ],
            'receptionist' => $booking
                ? ['url' => route('receptionist.bookings.show', $booking), 'label' => $label]
                // Awaiting reservations live in the receptionist's Reservations queue (details open in a modal).
                : (in_array($stay->status, Reservation::AWAITING_STATUSES, true)
                    ? ['url' => route('receptionist.reservations.index', ['open' => $stay->id]), 'label' => $label]
                    : null),
            'guest' => $stay instanceof Reservation
                ? ['url' => route('guest.reservations.show', $stay), 'label' => $label]
                : ['url' => route('guest.reservations.index'), 'label' => 'View My Bookings'],
            default => null,
        };
    }
}
