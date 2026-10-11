<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Reservation;
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
        // Legacy ?type= links map onto the tabs (see $tab below).
        $type = $request->get('type');
        $status = $request->get('status');
        $paymentStatus = $request->get('payment_status');
        $search = trim((string) $request->get('search', ''));

        $items = collect();

        {
            $reservationQuery = Reservation::with(['guest.user', 'roomType', 'booking.room', 'booking.billing', 'payments']);

            if ($status) {
                if ($status === 'PENDING') {
                    // Synthetic value matching the Admin dashboard's
                    // "Pending Reservations" card (DashboardStatsService::
                    // computeAdminStats()'s $pendingReservations, which
                    // counts BOTH awaiting-cash and awaiting-gcash
                    // reservations combined) - a single real status column
                    // value can't express "either of these two", and without
                    // this the card linked to status=AWAITING_CASH_CONFIRMATION
                    // only, silently hiding every awaiting-GCash reservation
                    // from the page the card promised to show. Never matches
                    // a Booking - awaiting-payment is a pre-conversion
                    // Reservation-only concept - so the booking branch below
                    // correctly falls through to its existing "no match" case.
                    $reservationQuery->whereIn('status', Reservation::ACTIVE_STATUSES);
                } elseif (in_array($status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED], true)) {
                    $reservationQuery->whereHas('booking', fn ($q) => $q->where('booking_status', $status));
                } else {
                    $reservationQuery->where('status', $status);
                }
            }
            if ($paymentStatus) {
                $reservationQuery->whereHas('payments', fn ($q) => $q->where('payment_status', $paymentStatus));
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
                        $q->orWhere('id', (int) $search)
                            ->orWhereHas('booking', fn ($qq) => $qq->where('id', (int) $search));
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
                // Once converted, the stay is identified by its Booking ID;
                // the original Reservation ID stays visible as a sub-label.
                $reservation->monitor_number_label = $reservation->booking
                    ? "Booking #{$reservation->booking->id}"
                    : "Reservation #{$reservation->id}";
                $reservation->monitor_origin_label = $reservation->booking ? "from Reservation #{$reservation->id}" : null;
                $reservation->monitor_badge = $reservation->booking ? 'Booking' : 'Reservation';
                $reservation->monitor_guest_name = $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A';
                $reservation->monitor_guest_email = $reservation->guest->user->email ?? '';
                $reservation->monitor_room_label = $reservation->roomType->name ?? 'N/A';
                $reservation->monitor_assigned_room = $reservation->booking?->room?->room_number;
                $reservation->monitor_status_value = $reservation->booking ? $reservation->booking->display_status : $reservation->status;
                $reservation->monitor_status_domain = $reservation->booking ? 'booking' : 'reservation';
                $reservation->monitor_latest_payment = $reservation->payments->sortByDesc('created_at')->first();
                $reservation->monitor_show_route = route('admin.reservations.show', $reservation);
                $items->push($reservation);
            }
        }

        {
            $bookingQuery = Booking::whereNull('reservation_id')->with(['guest.user', 'roomType', 'room', 'payments']);

            if ($status) {
                if (in_array($status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED], true)) {
                    $bookingQuery->where('booking_status', $status);
                } else {
                    $bookingQuery->whereRaw('1 = 0');
                }
            }
            if ($paymentStatus) {
                $bookingQuery->whereHas('payments', fn ($q) => $q->where('payment_status', $paymentStatus));
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
                $booking->monitor_origin_label = null;
                $booking->monitor_badge = 'Booking';
                $booking->monitor_guest_name = $booking->stay_guest_full_name ?? $booking->account_guest_full_name ?? 'N/A';
                $booking->monitor_guest_email = $booking->account_guest?->user?->email ?? '';
                $booking->monitor_room_label = $booking->roomType->name ?? 'N/A';
                $booking->monitor_assigned_room = $booking->room?->room_number;
                $booking->monitor_status_value = $booking->display_status;
                $booking->monitor_status_domain = 'booking';
                $booking->monitor_latest_payment = $booking->allPayments()->sortByDesc('created_at')->first();
                $booking->monitor_show_route = route('admin.bookings.show', $booking);
                $items->push($booking);
            }
        }

        $items = $items->sortBy('check_in')->values();

        // Quick-glance counts for the summary cards atop the list - scoped
        // to whatever search/type/status/date filters are currently
        // applied (the same $items set the table itself renders), not a
        // separate unfiltered global count. Also excludes confirmed
        // internal/test accounts (App\Support\TestAccountScope) so these
        // numbers always match the dashboard cards that link here - the
        // Admin Dashboard's own Pending/Active Reservations and Total
        // Bookings figures already exclude test accounts, so without this
        // the same "Bookings" figure would silently disagree depending on
        // whether you were looking at the dashboard or this page. The
        // detailed list below is untouched - every record, test account or
        // not, still shows there for genuine administrative troubleshooting.
        $businessItems = $items->reject(fn ($item) => $item->monitor_type === 'reservation'
            ? (bool) ($item->guest?->user?->is_test_account ?? false)
            : (bool) ($item->account_guest?->user?->is_test_account ?? false));

        $summaryTotal = $businessItems->count();
        // Converted reservations count as bookings (matches the tabs below).
        $summaryBookingCount = $businessItems->where('monitor_badge', 'Booking')->count();
        $summaryReservationCount = $businessItems->where('monitor_badge', 'Reservation')->count();
        // 'AWAITING_VERIFICATION' (Booking::display_status - see that
        // accessor) replaces Booking::STATUS_ACTIVE here: an ACTIVE
        // booking that's already been verified isn't "pending" anything,
        // and monitor_status_value now holds display_status, not the raw
        // booking_status, for every booking row above.
        $summaryPendingCount = $businessItems->whereIn('monitor_status_value', [Reservation::STATUS_AWAITING_CASH, Reservation::STATUS_AWAITING_GCASH, 'AWAITING_VERIFICATION'])->count();

        // Tabs: Reservations = not yet converted; Bookings = converted
        // reservations plus direct bookings. Without an explicit tab, a
        // filtered link (e.g. a dashboard card) opens whichever tab has results.
        $tabCounts = [
            'reservations' => $items->where('monitor_badge', 'Reservation')->count(),
            'bookings' => $items->where('monitor_badge', 'Booking')->count(),
        ];
        $tab = $request->get('tab', $type === 'booking' ? 'bookings' : ($type === 'reservation' ? 'reservations' : null));
        if (! in_array($tab, ['reservations', 'bookings'], true)) {
            $tab = $tabCounts['reservations'] === 0 && $tabCounts['bookings'] > 0 ? 'bookings' : 'reservations';
        }
        $items = $items->where('monitor_badge', $tab === 'bookings' ? 'Booking' : 'Reservation')->values();

        // Length-aware over the merged in-memory list, so the page shows
        // totals and numbered links.
        $perPage = \App\Support\PerPage::resolve($request);
        $page = max(1, (int) $request->get('page', 1));
        $reservations = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.reservations.index', compact(
            'reservations', 'summaryTotal', 'summaryBookingCount', 'summaryReservationCount', 'summaryPendingCount',
            'tab', 'tabCounts'
        ));
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
