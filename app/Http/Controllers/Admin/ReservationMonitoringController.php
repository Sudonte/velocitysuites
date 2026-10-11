<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Reservation;
use App\Support\MonitoringFilters;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Booking & Reservation Monitoring, scoped to the System Administrator
 * role (same read-only query shape as Manager\ReservationViewController,
 * kept as a separate copy rather than a shared dependency so Manager's
 * controller/routes/views stay completely untouched), but under its own
 * admin.* routes and views so an admin never lands on a page branded for
 * a different role.
 */
class ReservationMonitoringController extends Controller
{
    /**
     * Reservations / Bookings tabs, each with its own filters - see
     * App\Services\MonitoringListService (shared with the manager page).
     */
    public function index(Request $request): View
    {
        return view('admin.reservations.index', app(\App\Services\MonitoringListService::class)->build($request, 'admin'));
    }

    /**
     * Display a single reservation.
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

        return view('admin.reservations.show', compact('reservation', 'gcashPayments'));
    }

    /**
     * Display a single direct booking (reservation_id null) - see
     * Manager\ReservationViewController::showBooking()'s docblock for why
     * this needs to be a separate method/route from show() above.
     */
    public function showBooking(Booking $booking): View
    {
        abort_if($booking->reservation_id !== null, 404);

        $booking->load(['guest.user', 'roomType', 'room', 'payments']);
        $gcashPayments = $booking->allPayments()->where('payment_method', 'gcash')->sortByDesc('created_at')->values();

        return view('monitoring.booking-show', [
            'booking' => $booking,
            'gcashPayments' => $gcashPayments,
            'backRoute' => 'admin.reservations.index',
        ]);
    }
}
