<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Booking Module: a view-only registry of every confirmed booking
 * (regardless of downstream check-in/check-out status). Room assignment
 * and check-in live in the Check-in Module; this is just "View Booking
 * Details" - nothing here changes a booking's state.
 */
class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $query = Booking::with(['reservation.guest.user', 'roomType', 'room']);

        if ($request->filled('status')) {
            $query->where('booking_status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('reservation.guest.user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%");
            });
        }

        $bookings = $query->latest('confirmed_at')->paginate(15)->withQueryString();

        return view('receptionist.bookings.index', compact('bookings'));
    }

    public function show(Booking $booking): View
    {
        $booking->load(['reservation.guest.user', 'roomType', 'room', 'billing']);

        return view('receptionist.bookings.show', compact('booking'));
    }
}
