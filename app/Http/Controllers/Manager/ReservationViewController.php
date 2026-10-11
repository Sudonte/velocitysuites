<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Reservation;
use App\Support\MonitoringFilters;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class ReservationViewController extends Controller
{
    /**
     * Reservations / Bookings tabs, each with its own filters - see
     * App\Services\MonitoringListService (shared with the admin page).
     */
    public function index(Request $request): View
    {
        return view('manager.reservations.index', array_merge(
            app(\App\Services\MonitoringListService::class)->build($request, 'manager'),
            // Receptionist filter (Bookings tab) - staff who can appear as verified_by.
            ['receptionists' => User::where('role', 'receptionist')->orderBy('first_name')->get()]
        ));
    }

    /**
     * Display a single reservation (or a converted reservation-derived
     * booking - both share this one page, as before).
     */
    public function show(Reservation $reservation): View
    {
        $reservation->load(['guest.user', 'roomType', 'payments', 'booking.room', 'booking.billing.payments']);

        // allPayments() de-duplicates: a GCash payment linked to both the
        // reservation and the bill is still one payment.
        $gcashPayments = ($reservation->booking ? $reservation->booking->allPayments() : $reservation->payments)
            ->where('payment_method', 'gcash')
            ->sortByDesc('created_at')
            ->values();

        return view('manager.reservations.show', compact('reservation', 'gcashPayments'));
    }

    /**
     * Display a single direct booking (reservation_id null) - a genuinely
     * separate page from show() above since a direct booking has no
     * Reservation row to route-model-bind through. Read-only, same as the
     * rest of this monitoring module - verification/archival stay
     * receptionist-only actions.
     */
    public function showBooking(Booking $booking): View
    {
        abort_if($booking->reservation_id !== null, 404);

        $booking->load(['guest.user', 'roomType', 'room', 'payments']);
        $gcashPayments = $booking->allPayments()->where('payment_method', 'gcash')->sortByDesc('created_at')->values();

        return view('monitoring.booking-show', [
            'booking' => $booking,
            'gcashPayments' => $gcashPayments,
            'backRoute' => 'manager.reservations.index',
        ]);
    }
}
