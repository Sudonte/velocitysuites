<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Support\MonitoringFilters;
use App\Support\PerPage;
use App\Support\TestAccountScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Reservation and Booking Monitoring list, shared by the admin and
 * manager pages (they differ only in route names and which filters the view
 * shows).
 *
 * Two tabs, each with its own filters:
 *  - Reservations: not yet converted. Filters: search, status (awaiting
 *    payment / rejected / cancelled), chosen payment method, check-in dates.
 *    Payments and staff handling only begin once a reservation converts, so
 *    those filters don't apply here.
 *  - Bookings: converted reservations + direct (pay-first) bookings. Filters:
 *    search, booking status, payment status, payment method, receptionist,
 *    check-in dates.
 * Only the open tab's filters are applied.
 */
class MonitoringListService
{
    public const RESERVATION_STATUSES = [
        'PENDING' => 'Awaiting Payment',
        Reservation::STATUS_REJECTED => 'Rejected',
        Reservation::STATUS_CANCELLED => 'Cancelled',
    ];

    public const BOOKING_STATUSES = [
        'AWAITING_VERIFICATION' => 'Awaiting Verification',
        Booking::STATUS_ACTIVE => 'Confirmed (Booked)',
        Booking::STATUS_CHECKED_IN => 'Checked-In',
        Booking::STATUS_COMPLETED => 'Checked-Out',
        Booking::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Old status values from earlier links, mapped onto the current ones. */
    private const LEGACY_STATUSES = [
        Reservation::STATUS_AWAITING_CASH => 'PENDING',
        Reservation::STATUS_AWAITING_GCASH => 'PENDING',
    ];

    /**
     * @param  string  $routePrefix  'admin' or 'manager' - for the row's detail link.
     */
    public function build(Request $request, string $routePrefix): array
    {
        $tab = $this->resolveTab($request);
        $filters = $this->filters($request, $tab);

        $items = $tab === 'reservations'
            ? $this->reservationRows($filters, $routePrefix, converted: false)
            : $this->reservationRows($filters, $routePrefix, converted: true)->concat($this->directBookingRows($filters, $routePrefix));

        // Newest first: the most recently created reservation/booking on top.
        $items = $items->sortByDesc(fn ($item) => [$item->created_at?->timestamp ?? 0, $item->id])->values();

        $perPage = PerPage::resolve($request);
        $page = max(1, (int) $request->get('page', 1));
        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return array_merge([
            'reservations' => $paginator,
            'tab' => $tab,
            'tabCounts' => $this->tabCounts(),
            'filters' => $filters,
            'statusOptions' => $tab === 'reservations' ? self::RESERVATION_STATUSES : self::BOOKING_STATUSES,
        ], $this->summary());
    }

    private function resolveTab(Request $request): string
    {
        $tab = $request->get('tab');
        if (in_array($tab, ['reservations', 'bookings'], true)) {
            return $tab;
        }

        // Legacy ?type= links and filter-only links (e.g. a dashboard card)
        // open the tab those filters belong to.
        $status = $request->get('status');
        if ($request->get('type') === 'booking'
            || array_key_exists((string) $status, self::BOOKING_STATUSES)
            || $request->filled('payment_status')
            || $request->filled('receptionist')) {
            return 'bookings';
        }

        return 'reservations';
    }

    private function filters(Request $request, string $tab): array
    {
        $status = (string) $request->get('status', '');
        $status = self::LEGACY_STATUSES[$status] ?? $status;
        $allowedStatuses = $tab === 'reservations' ? self::RESERVATION_STATUSES : self::BOOKING_STATUSES;
        $paymentMethod = $request->get('payment_method');
        $paymentStatus = $request->get('payment_status');

        return [
            'search' => trim((string) $request->get('search', '')),
            'status' => array_key_exists($status, $allowedStatuses) ? $status : null,
            'payment_method' => in_array($paymentMethod, ['cash', 'gcash'], true) ? $paymentMethod : null,
            'payment_status' => $tab === 'bookings' && MonitoringFilters::isPaymentStatus($paymentStatus) ? $paymentStatus : null,
            'receptionist' => $tab === 'bookings' && $request->filled('receptionist') ? (int) $request->get('receptionist') : null,
            'from' => $request->get('from') ?: null,
            'to' => $request->get('to') ?: null,
        ];
    }

    private function reservationRows(array $f, string $routePrefix, bool $converted): Collection
    {
        $query = Reservation::with(['guest.user', 'roomType', 'booking.room', 'booking.billing', 'payments',
            'verifier', 'payments.verifier', 'booking.verifier', 'booking.payments.verifier', 'booking.billing.payments.verifier']);
        $converted ? $query->has('booking') : $query->doesntHave('booking');

        if ($f['status']) {
            if (! $converted) {
                $f['status'] === 'PENDING'
                    ? $query->whereIn('status', Reservation::AWAITING_STATUSES)
                    : $query->where('status', $f['status']);
            } else {
                $query->whereHas('booking', fn ($q) => $this->bookingStatus($q, $f['status']));
            }
        }
        if ($f['payment_method']) {
            MonitoringFilters::paymentMethod($query, MonitoringFilters::RESERVATION_PAYMENTS, $f['payment_method']);
        }
        if ($f['payment_status']) {
            MonitoringFilters::paymentStatus($query, MonitoringFilters::RESERVATION_PAYMENTS, $f['payment_status']);
        }
        if ($f['receptionist']) {
            $id = $f['receptionist'];
            $query->where(function ($q) use ($id) {
                $q->where('verified_by', $id)->orWhereHas('booking', fn ($b) => $b->where('verified_by', $id));
                foreach (MonitoringFilters::RESERVATION_PAYMENTS as $relation) {
                    $q->orWhereHas($relation, fn ($p) => $p->where('verified_by', $id));
                }
            });
        }
        $this->dateAndSearch($query, $f, function ($q, $search) {
            if (is_numeric($search)) {
                $q->orWhere('id', (int) $search)->orWhereHas('booking', fn ($b) => $b->where('id', (int) $search));
            }
            $this->guestUserSearch($q, $search);
        });

        return $query->get()->map(function (Reservation $reservation) use ($routePrefix) {
            $booking = $reservation->booking;
            $reservation->monitor_type = 'reservation';
            // Once converted, the stay is identified by its Booking ID;
            // the original Reservation ID stays visible as a sub-label.
            $reservation->monitor_number_label = $booking ? "Booking #{$booking->id}" : "Reservation #{$reservation->id}";
            $reservation->monitor_origin_label = $booking ? "from Reservation #{$reservation->id}" : null;
            $reservation->monitor_badge = $booking ? 'Booking' : 'Reservation';
            $reservation->monitor_guest_name = $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A';
            $reservation->monitor_guest_email = $reservation->guest->user->email ?? '';
            $reservation->monitor_room_label = $reservation->roomType->name ?? 'N/A';
            $reservation->monitor_assigned_room = $booking?->room?->room_number;
            $reservation->monitor_status_value = $booking ? $booking->display_status : $reservation->status;
            $reservation->monitor_status_domain = $booking ? 'booking' : 'reservation';
            $reservation->monitor_show_route = route("{$routePrefix}.reservations.show", $reservation);
            $reservation->monitor_handled_by = $reservation->handledByNames();

            return $reservation;
        })->toBase();
    }

    private function directBookingRows(array $f, string $routePrefix): Collection
    {
        $query = Booking::whereNull('reservation_id')->with(['guest.user', 'roomType', 'room', 'payments',
            'verifier', 'payments.verifier', 'billing.payments.verifier']);

        if ($f['status']) {
            $this->bookingStatus($query, $f['status']);
        }
        if ($f['payment_method']) {
            MonitoringFilters::paymentMethod($query, MonitoringFilters::BOOKING_PAYMENTS, $f['payment_method']);
        }
        if ($f['payment_status']) {
            MonitoringFilters::paymentStatus($query, MonitoringFilters::BOOKING_PAYMENTS, $f['payment_status']);
        }
        if ($f['receptionist']) {
            $id = $f['receptionist'];
            $query->where(function ($q) use ($id) {
                $q->where('verified_by', $id);
                foreach (MonitoringFilters::BOOKING_PAYMENTS as $relation) {
                    $q->orWhereHas($relation, fn ($p) => $p->where('verified_by', $id));
                }
            });
        }
        $this->dateAndSearch($query, $f, function ($q, $search) {
            if (is_numeric($search)) {
                $q->orWhere('id', (int) $search);
            }
            $q->orWhere('guest_first_name', 'like', "%{$search}%")
                ->orWhere('guest_last_name', 'like', "%{$search}%");
            $this->guestUserSearch($q, $search);
        });

        return $query->get()->map(function (Booking $booking) use ($routePrefix) {
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
            $booking->monitor_show_route = route("{$routePrefix}.bookings.show", $booking);
            $booking->monitor_handled_by = $booking->handledByNames();

            return $booking;
        })->toBase();
    }

    /** Booking status as displayed: "Awaiting Verification" vs "Confirmed" split ACTIVE by verified_at. */
    private function bookingStatus(Builder $query, string $status): void
    {
        match ($status) {
            'AWAITING_VERIFICATION' => $query->where('booking_status', Booking::STATUS_ACTIVE)->whereNull('verified_at'),
            Booking::STATUS_ACTIVE => $query->where('booking_status', Booking::STATUS_ACTIVE)->whereNotNull('verified_at'),
            default => $query->where('booking_status', $status),
        };
    }

    private function dateAndSearch(Builder $query, array $f, callable $searchClauses): void
    {
        if ($f['from']) {
            $query->whereDate('check_in', '>=', $f['from']);
        }
        if ($f['to']) {
            $query->whereDate('check_in', '<=', $f['to']);
        }
        if ($f['search'] !== '') {
            $query->where(fn ($q) => $searchClauses($q, $f['search']));
        }
    }

    private function guestUserSearch($query, string $search): void
    {
        // full_name is a computed accessor, not a real column.
        $query->orWhereHas('guest.user', fn ($u) => $u->where('first_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%"));
    }

    /** Every row in each tab, unfiltered - the tab badges. */
    private function tabCounts(): array
    {
        return [
            'reservations' => Reservation::doesntHave('booking')->count(),
            'bookings' => Reservation::has('booking')->count() + Booking::whereNull('reservation_id')->count(),
        ];
    }

    /**
     * Summary cards: hotel-wide and unfiltered, excluding confirmed test
     * accounts so they match the dashboard cards that link here.
     */
    private function summary(): array
    {
        $reservations = TestAccountScope::excludeFromReservations(Reservation::doesntHave('booking'))->count();
        $bookings = TestAccountScope::excludeFromReservations(Reservation::has('booking'))->count()
            + TestAccountScope::excludeFromBookings(Booking::whereNull('reservation_id'))->count();
        $awaiting = TestAccountScope::excludeFromReservations(
            Reservation::doesntHave('booking')->whereIn('status', Reservation::AWAITING_STATUSES)
        )->count() + TestAccountScope::excludeFromBookings(
            Booking::where('booking_status', Booking::STATUS_ACTIVE)->whereNull('verified_at')
        )->count();

        return [
            'summaryTotal' => $reservations + $bookings,
            'summaryBookingCount' => $bookings,
            'summaryReservationCount' => $reservations,
            'summaryPendingCount' => $awaiting,
        ];
    }
}
