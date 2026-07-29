<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationViewController extends Controller
{
    /**
     * Display list of all reservations. The status filter spans both the
     * pre-booking Reservation status and the post-conversion Booking
     * status - a reservation only has one of the two active at a time, so
     * the options are presented as a single list even though they filter
     * different columns underneath.
     */
    public function index(Request $request): View
    {
        $query = Reservation::with(['guest.user', 'roomType', 'booking.room', 'booking.billing']);

        if ($request->filled('status')) {
            $status = $request->status;
            if (in_array($status, ['confirmed', 'checked_in', 'checked_out'])) {
                $query->whereHas('booking', fn ($q) => $q->where('booking_status', $status));
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('check_in', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('check_in', '<=', $request->to);
        }

        // Closest check-in date first, same priority ordering as the
        // receptionist's own reservation/booking lists.
        $reservations = $query->orderBy('check_in')->paginate(15)->withQueryString();

        return view('manager.reservations.index', compact('reservations'));
    }

    /**
     * Display a single reservation.
     */
    public function show(Reservation $reservation): View
    {
        $reservation->load(['guest.user', 'roomType', 'booking.room', 'booking.billing.payments']);

        return view('manager.reservations.show', compact('reservation'));
    }
}
