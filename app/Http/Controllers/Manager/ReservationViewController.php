<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationViewController extends Controller
{
    /**
     * Display list of all reservations.
     */
    public function index(Request $request): View
    {
        $query = Reservation::with(['guest.user', 'room', 'booking.billing']);

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('from') && $request->from) {
            $query->whereDate('check_in', '>=', $request->from);
        }

        if ($request->has('to') && $request->to) {
            $query->whereDate('check_in', '<=', $request->to);
        }

        $reservations = $query->latest('check_in')->paginate(15);

        return view('manager.reservations.index', compact('reservations'));
    }

    /**
     * Display a single reservation.
     */
    public function show(Reservation $reservation): View
    {
        $reservation->load(['guest.user', 'room', 'booking.billing.payments']);

        return view('manager.reservations.show', compact('reservation'));
    }
}
