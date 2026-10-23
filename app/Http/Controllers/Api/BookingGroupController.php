<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

/**
 * OLD groups only. Before one booking could hold several stays, a guest booking several stays got a group of separate bookings (or
 * reservations), tied by a booking_groups row. Those records stay as they are, and this controller keeps what they still need: show a
 * group, and "Cancel all stays" for the guest. New transactions never create a group - several stays are ONE booking with its own
 * stays (see Api\BookingController::store).
 */
class BookingGroupController extends Controller
{
    /** Cancel every stay of the group that is not verified yet; verified ones stay (same rule as a single booking). */
    public function cancel(BookingGroup $group): JsonResponse
    {
        if ($group->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cancelled = 0;
        $kept = 0;
        foreach ($group->bookings()->get() as $booking) {
            $open = $booking->booking_status === Booking::STATUS_ACTIVE && $booking->verified_at === null;
            if (! $open) {
                $kept += in_array($booking->booking_status, Booking::CLOSED_STATUSES, true) ? 0 : 1;
                continue;
            }
            if (app(BookingController::class)->cancel($booking)->getStatusCode() < 400) {
                $cancelled++;
            }
        }
        foreach ($group->reservations()->get() as $reservation) {
            if (in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
                if (app(ReservationController::class)->cancel($reservation)->getStatusCode() < 400) {
                    $cancelled++;
                }
            }
        }

        if ($cancelled === 0) {
            return response()->json(['message' => 'None of these stays can be cancelled any more: the front desk has verified them.'], 422);
        }

        $payload = $this->payload($group, $group->reservations()->exists() ? 'reservation' : 'booking', 200)->getData(true);
        $payload['cancelled'] = $cancelled;
        $payload['kept_verified'] = $kept;

        return response()->json($payload);
    }

    public function show(BookingGroup $group): JsonResponse
    {
        if ($group->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $this->payload($group, $group->reservations()->exists() ? 'reservation' : 'booking', 200);
    }

    private function payload(BookingGroup $group, string $type, int $status): JsonResponse
    {
        $bookings = $group->bookings()->get()->each->append(['total_amount_due', 'amenities']);
        $reservations = $group->reservations()->get()->each->append(['total_amount_due', 'amenities']);

        return response()->json([
            'group_id' => $group->id,
            'type' => $type,
            'total' => round((float) $bookings->sum('total_amount_due') + (float) $reservations->sum('total_amount_due'), 2),
            'bookings' => $bookings,
            'reservations' => $reservations,
        ], $status);
    }
}
