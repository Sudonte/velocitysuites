<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Booking Module: a view-only registry of bookings still awaiting
 * check-in (booking_status = confirmed). Same single-stage-at-a-time rule
 * as every other module - once a guest checks in, the record disappears
 * from here and shows up in the Check-in Module's "Checked-in Guests" tab
 * (and later the Check-out Module) instead, not here as well. Room
 * assignment and check-in live in the Check-in Module; this is just "View
 * Booking Details" - nothing here changes a booking's state.
 */
class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $query = Booking::with(['reservation.guest.user', 'roomType', 'room'])
            ->where('booking_status', 'confirmed');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('reservation.guest.user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%");
            });
        }

        // Closest check-in date first - whoever is arriving soonest is the
        // receptionist's priority, not whoever was most recently confirmed.
        $bookings = $query->orderBy('check_in')->paginate(15)->withQueryString();

        return view('receptionist.bookings.index', compact('bookings'));
    }

    public function show(Booking $booking): View
    {
        $booking->load(['reservation.guest.user', 'roomType', 'room', 'billing']);

        return view('receptionist.bookings.show', compact('booking'));
    }
}
