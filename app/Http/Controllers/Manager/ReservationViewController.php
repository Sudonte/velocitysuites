<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class ReservationViewController extends Controller
{
    /**
     * Display list of all reservations AND direct bookings (the mobile
     * "New Booking" pay-first path, reservation_id = null - never has a
     * Reservation row at all, so it's fetched from Booking directly and
     * merged in here rather than via Reservation::booking()). Every row is
     * tagged monitor_type so the view can tell the two apart at a glance
     * without either type's underlying table/model being altered or
     * conflated - see the monitor_* properties set below.
     */
    public function index(Request $request): View
    {
        $type = $request->get('type');
        $status = $request->get('status');
        $paymentStatus = $request->get('payment_status');
        $paymentMethod = $request->get('payment_method');
        $receptionistId = $request->get('receptionist');
        $search = trim((string) $request->get('search', ''));

        $items = collect();

        if ($type !== 'booking') {
            $reservationQuery = Reservation::with(['guest.user', 'roomType', 'booking.room', 'booking.billing', 'payments']);

            if ($status) {
                if (in_array($status, ['confirmed', 'checked_in', 'checked_out'], true)) {
                    $reservationQuery->whereHas('booking', fn ($q) => $q->where('booking_status', $status));
                } else {
                    $reservationQuery->where('status', $status);
                }
            }
            if ($paymentStatus) {
                $reservationQuery->whereHas('payments', fn ($q) => $q->where('payment_status', $paymentStatus));
            }
            if ($paymentMethod) {
                $reservationQuery->whereHas('payments', fn ($q) => $q->where('payment_method', $paymentMethod));
            }
            if ($receptionistId) {
                $reservationQuery->where(function ($q) use ($receptionistId) {
                    $q->where('verified_by', $receptionistId)
                        ->orWhereHas('payments', fn ($pq) => $pq->where('verified_by', $receptionistId));
                });
            }
            if ($request->filled('from')) {
                $reservationQuery->whereDate('check_in', '>=', $request->from);
            }
            if ($request->filled('to')) {
                $reservationQuery->whereDate('check_in', '<=', $request->to);
            }
            if ($search !== '') {
                $reservationQuery->where(function ($q) use ($search) {
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                    $q->orWhereHas('guest.user', function ($qq) use ($search) {
                        // full_name is a computed accessor (User::getFullNameAttribute()),
                        // not a real column - querying it directly throws a SQL error.
                        $qq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                });
            }

            foreach ($reservationQuery->get() as $reservation) {
                $reservation->monitor_type = 'reservation';
                $reservation->monitor_number_label = "Reservation #{$reservation->id}";
                $reservation->monitor_badge = $reservation->booking ? 'Booking' : 'Reservation';
                $reservation->monitor_guest_name = $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A';
                $reservation->monitor_guest_email = $reservation->guest->user->email ?? '';
                $reservation->monitor_room_label = $reservation->roomType->name ?? 'N/A';
                $reservation->monitor_assigned_room = $reservation->booking?->room?->room_number;
                $reservation->monitor_status_value = $reservation->booking ? $reservation->booking->booking_status : $reservation->status;
                $reservation->monitor_status_domain = $reservation->booking ? 'booking' : 'reservation';
                $reservation->monitor_latest_payment = $reservation->payments->sortByDesc('created_at')->first();
                $reservation->monitor_show_route = route('manager.reservations.show', $reservation);
                $items->push($reservation);
            }
        }

        if ($type !== 'reservation') {
            $bookingQuery = Booking::whereNull('reservation_id')->with(['guest.user', 'roomType', 'room', 'payments']);

            if ($status) {
                if (in_array($status, ['confirmed', 'checked_in', 'checked_out', 'cancelled'], true)) {
                    $bookingQuery->where('booking_status', $status);
                } else {
                    // A reservation-only status (pending_review/ready_for_booking/
                    // rejected) can never match a direct booking.
                    $bookingQuery->whereRaw('1 = 0');
                }
            }
            if ($paymentStatus) {
                $bookingQuery->whereHas('payments', fn ($q) => $q->where('payment_status', $paymentStatus));
            }
            if ($paymentMethod) {
                $bookingQuery->whereHas('payments', fn ($q) => $q->where('payment_method', $paymentMethod));
            }
            if ($receptionistId) {
                $bookingQuery->where(function ($q) use ($receptionistId) {
                    $q->where('verified_by', $receptionistId)
                        ->orWhereHas('payments', fn ($pq) => $pq->where('verified_by', $receptionistId));
                });
            }
            if ($request->filled('from')) {
                $bookingQuery->whereDate('check_in', '>=', $request->from);
            }
            if ($request->filled('to')) {
                $bookingQuery->whereDate('check_in', '<=', $request->to);
            }
            if ($search !== '') {
                $bookingQuery->where(function ($q) use ($search) {
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                    $q->orWhere('guest_first_name', 'like', "%{$search}%")
                        ->orWhere('guest_last_name', 'like', "%{$search}%")
                        ->orWhereHas('guest.user', function ($qq) use ($search) {
                            $qq->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            foreach ($bookingQuery->get() as $booking) {
                $booking->monitor_type = 'booking';
                $booking->monitor_number_label = "Booking #{$booking->id}";
                $booking->monitor_badge = 'Booking';
                $booking->monitor_guest_name = $booking->stay_guest_full_name ?? $booking->account_guest_full_name ?? 'N/A';
                $booking->monitor_guest_email = $booking->account_guest?->user?->email ?? '';
                $booking->monitor_room_label = $booking->roomType->name ?? 'N/A';
                $booking->monitor_assigned_room = $booking->room?->room_number;
                $booking->monitor_status_value = $booking->booking_status;
                $booking->monitor_status_domain = 'booking';
                $booking->monitor_latest_payment = $booking->allPayments()->sortByDesc('created_at')->first();
                $booking->monitor_show_route = route('manager.bookings.show', $booking);
                $items->push($booking);
            }
        }

        $items = $items->sortBy('check_in')->values();

        // Quick-glance counts for the summary cards atop the list - scoped
        // to whatever filters are currently applied (the same $items set
        // the table itself renders), not a separate unfiltered global count.
        $summaryTotal = $items->count();
        $summaryBookingCount = $items->where('monitor_type', 'booking')->count();
        $summaryReservationCount = $items->where('monitor_type', 'reservation')->count();
        $summaryPendingCount = $items->whereIn('monitor_status_value', ['pending_review', 'ready_for_booking', 'confirmed'])->count();

        $perPage = 15;
        $page = max(1, (int) $request->get('page', 1));
        $reservations = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // For the Receptionist filter dropdown - every staff account that
        // could plausibly appear as a verified_by actor on a transaction.
        $receptionists = User::where('role', 'receptionist')->orderBy('first_name')->get();

        return view('manager.reservations.index', compact(
            'reservations', 'receptionists', 'summaryTotal', 'summaryBookingCount', 'summaryReservationCount', 'summaryPendingCount'
        ));
    }

    /**
     * Display a single reservation (or a converted reservation-derived
     * booking - both share this one page, as before).
     */
    public function show(Reservation $reservation): View
    {
        $reservation->load(['guest.user', 'roomType', 'payments', 'booking.room', 'booking.billing.payments']);

        $gcashPayments = $reservation->payments
            ->concat($reservation->booking?->billing?->payments ?? collect())
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
