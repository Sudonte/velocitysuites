<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\NotificationService;
use App\Services\RoomAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReceptionistController extends Controller
{
    protected NotificationService $notificationService;
    protected RoomAvailabilityService $availability;

    public function __construct(NotificationService $notificationService, RoomAvailabilityService $availability)
    {
        $this->notificationService = $notificationService;
        $this->availability = $availability;
    }

    /**
     * Display the receptionist dashboard.
     */
    public function dashboard(): View
    {
        // Status-driven counts that mirror the actual work queues, so any
        // action (accept, convert, check-in, check-out) moves these
        // immediately - check-in/check-out are no longer date-gated.
        $availableRooms = Room::where('status', 'available')->count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $maintenanceRooms = Room::where('status', 'maintenance')->count();
        $bookingRequests = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])->count();
        $awaitingCheckIn = Booking::where('booking_status', 'confirmed')->count();
        $inHouseGuests = Booking::where('booking_status', 'checked_in')->count();

        // Today's schedule stays date-based - it's a schedule. Arrivals due
        // today (not yet checked in, i.e. still pending arrival) and
        // departures due today (still in house).
        $pendingArrivals = Booking::with(['reservation.guest.user', 'guest.user', 'room', 'roomType'])
            ->whereDate('check_in', today())
            ->where('booking_status', 'confirmed')
            ->get();

        $todayDepartures = Booking::with(['reservation.guest.user', 'guest.user', 'room'])
            ->whereDate('check_out', today())
            ->where('booking_status', 'checked_in')
            ->get();

        // Current Booking & Reservations widget - deliberately queries
        // Reservation (not just Booking, unlike the earlier version of this
        // widget) so both not-yet-converted reservation requests
        // (pending_review/ready_for_booking) and already-converted,
        // still-awaiting-check-in bookings (booking_status=confirmed) show
        // up together, distinguished by a Type badge in the view - "current"
        // meaning still active/actionable, not completed/cancelled/rejected.
        // No cap - small enough that "Expand" can show every matching row.
        $currentReservations = Reservation::with(['guest.user', 'roomType', 'booking.room'])
            ->where(function ($q) {
                $q->whereIn('status', ['pending_review', 'ready_for_booking'])
                    ->orWhereHas('booking', fn ($q2) => $q2->where('booking_status', 'confirmed'));
            })
            ->orderBy('check_in')
            ->get();

        // Recent Booking Activities - the curated log (see App\Support\Activity),
        // filtered to booking/reservation-relevant entries only; user-
        // management/promotion entries belong on the Admin feed instead.
        // ActivityLog is an ever-growing event stream - capped at a
        // generous 50 so "Expand" reveals a genuinely comprehensive recent
        // window without rendering the entire historical log inline.
        $recentBookingActivities = ActivityLog::with('user')
            ->whereIn('subject_type', ['reservation', 'booking'])
            ->latest()
            ->limit(50)
            ->get();

        return view('receptionist.dashboard', compact(
            'availableRooms',
            'occupiedRooms',
            'maintenanceRooms',
            'bookingRequests',
            'awaitingCheckIn',
            'inHouseGuests',
            'pendingArrivals',
            'todayDepartures',
            'currentReservations',
            'recentBookingActivities'
        ));
    }

    // NOTE: check-in listing/room-assignment/check-in-store now live in
    // Receptionist\CheckInController (two-tab Check-in Module).
    // NOTE: check-out listing/billing/payment/additional-charges/discount
    // now live in Receptionist\CheckOutController (two-tab Check-out Module).

    /**
     * Browse room types (read-only card grid). The receptionist can see
     * inventory and status but cannot add or edit types/rooms.
     */
    public function roomsIndex(): View
    {
        $roomTypes = \App\Models\RoomType::withCount([
            'rooms',
            'rooms as available_rooms_count' => function ($q) {
                $q->where('status', 'available');
            },
        ])->orderBy('name')->get();

        return view('receptionist.rooms.index', compact('roomTypes'));
    }

    /**
     * Rooms of one type with their live statuses (read-only), plus the
     * merged gallery of every room's photos, each labeled by room.
     */
    public function roomsShow(\App\Models\RoomType $roomType): View
    {
        $rooms = $roomType->rooms()->with('images')->orderBy('room_number')->paginate(20);
        $mergedGallery = collect($roomType->gallery);

        return view('receptionist.rooms.show', compact('roomType', 'rooms', 'mergedGallery'));
    }

    /**
     * Full details for one specific physical room (read-only), plus that
     * room's own gallery (galleries belong to the individual room again) -
     * the receptionist can see everything the System Administrator sees,
     * but has no access to any upload/replace/remove route (those only
     * exist under admin.*), so this view-only access is enforced
     * structurally, not just by hiding buttons.
     */
    public function roomDetails(\App\Models\RoomType $roomType, Room $room): View
    {
        abort_if($room->room_type_id !== $roomType->id, 404);

        $gallery = collect($room->gallery);

        return view('receptionist.rooms.room-details', compact('roomType', 'room', 'gallery'));
    }

    /**
     * List all amenity requests - guest-submitted (via the mobile app,
     * Api\AmenityRequestController) and receptionist-submitted (below)
     * both land in the same table and show up here identically.
     */
    public function amenitiesIndex(Request $request): View
    {
        $this->archiveDueAmenityRequests();

        $query = AmenityRequest::with(['guest.user', 'amenity', 'room', 'roomType', 'reservation.booking', 'booking'])
            ->whereNull('archived_at');

        // Search by guest name, or the transaction's own reference number -
        // either the reservation's numeric id (same value shown as
        // "Reservation #" throughout the guest-facing app) or, for a
        // request tied directly to a "New Booking" transaction
        // (reservation_id null, booking_id set), the booking's own id.
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('guest.user', fn ($u) => $u->searchName($search))
                    ->orWhere('reservation_id', is_numeric($search) ? (int) $search : -1)
                    ->orWhere('booking_id', is_numeric($search) ? (int) $search : -1);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $amenityRequests = $query->latest()->paginate(15)->withQueryString();
        $this->applyTransactionDisplayAttributes($amenityRequests);

        // Lifetime distinct-guest count for Paid/Additional amenity requests
        // specifically (charge > 0) - a running engagement figure, not
        // scoped to the current search/filter/pagination above it.
        $paidAmenityGuestCount = AmenityRequest::where('charge', '>', 0)->distinct('guest_id')->count('guest_id');

        return view('receptionist.amenities.index', compact('amenityRequests', 'paidAmenityGuestCount'));
    }

    /**
     * Determines whether each amenity request belongs to a direct Booking
     * or a Reservation, and sets the correct transaction number to display -
     * the single source of truth both amenities.index and amenities.archived
     * render from, so a request can never show a null/blank/wrong-type
     * reference. Three cases: (1) booking_id set directly (a "New Booking"
     * transaction's own request - see DirectBookingService::create()),
     * (2) reservation_id set and that reservation has since converted to a
     * Booking (Reservation::booking()), (3) reservation_id set and still
     * unconverted. Anything else (should never happen given the DB
     * constraints, but handled defensively) falls back to a graceful
     * "not available" label rather than guessing.
     */
    private function applyTransactionDisplayAttributes(iterable $amenityRequests): void
    {
        foreach ($amenityRequests as $req) {
            $convertedBooking = $req->reservation?->booking;
            if ($req->booking) {
                $req->display_is_booking = true;
                $req->display_transaction_number = $req->booking->id;
            } elseif ($convertedBooking) {
                $req->display_is_booking = true;
                $req->display_transaction_number = $convertedBooking->id;
            } elseif ($req->reservation) {
                $req->display_is_booking = false;
                $req->display_transaction_number = $req->reservation->id;
            } else {
                $req->display_is_booking = null;
                $req->display_transaction_number = null;
            }
        }
    }

    /**
     * Read-only view of amenity requests that auto-archived after sitting
     * Completed for a week or more - same filters as the active list, but
     * scoped to archived_at rows only and with no status-update action.
     */
    public function amenitiesArchived(Request $request): View
    {
        $this->archiveDueAmenityRequests();

        $query = AmenityRequest::with(['guest.user', 'amenity', 'room', 'roomType', 'reservation.booking', 'booking'])
            ->whereNotNull('archived_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('guest.user', fn ($u) => $u->searchName($search))
                    ->orWhere('reservation_id', is_numeric($search) ? (int) $search : -1)
                    ->orWhere('booking_id', is_numeric($search) ? (int) $search : -1);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $amenityRequests = $query->latest('archived_at')->paginate(15)->withQueryString();
        $this->applyTransactionDisplayAttributes($amenityRequests);

        // How many distinct guests have availed each Paid/Additional
        // amenity, across the full archived set (not the current search/
        // filter above) - a historical usage breakdown, same "lifetime
        // figure" pattern as $paidAmenityGuestCount on the active list.
        $archivedAmenityGuestCounts = AmenityRequest::whereNotNull('archived_at')
            ->where('charge', '>', 0)
            ->select('amenity_name')
            ->selectRaw('COUNT(DISTINCT guest_id) as guest_count')
            ->groupBy('amenity_name')
            ->orderByDesc('guest_count')
            ->get();

        return view('receptionist.amenities.archived', compact('amenityRequests', 'archivedAmenityGuestCounts'));
    }

    /**
     * Lazy safety-net sweep, same query as
     * Console\Commands\ArchiveCompletedAmenityRequests - this Hostinger
     * plan has no crontab access over SSH (hPanel cron still isn't
     * configured, see routes/console.php), so this runs the same one-line
     * archive on every load of either Amenity Requests list, mirroring the
     * PurgeExpiredDeletedAccounts lazy-check-on-load pattern.
     */
    private function archiveDueAmenityRequests(): void
    {
        AmenityRequest::where('status', 'completed')
            ->whereNull('archived_at')
            ->where('updated_at', '<=', now()->subWeek())
            ->update(['archived_at' => now()]);
    }

    /**
     * Show form to create an amenity request for a reservation. Amenity
     * Requests are only available for guests who are actually checked in -
     * a room-service or extra-bed request makes no sense before the guest
     * has a room, and once checked out billing is already closed.
     */
    public function amenitiesCreate(Reservation $reservation): View
    {
        if (!$reservation->booking || $reservation->booking->booking_status !== 'checked_in') {
            abort(404);
        }

        // Only Paid/Additional amenities are ever requestable - Free/
        // Included amenities (Complimentary Breakfast, Free Wi-Fi, etc.)
        // must never appear as a chargeable request option here either.
        $amenities = Amenity::where('status', 'active')
            ->where('charge', '>', 0)
            ->orderBy('category')
            ->orderBy('amenity_name')
            ->get();

        return view('receptionist.amenities.create', compact('reservation', 'amenities'));
    }

    /**
     * Store a new amenity request, snapshotting the amenity's current charge.
     */
    public function amenitiesStore(Request $request, Reservation $reservation): RedirectResponse
    {
        if (!$reservation->booking || $reservation->booking->booking_status !== 'checked_in') {
            return back()->with('error', 'Amenity requests can only be added for checked-in guests.');
        }

        $validated = $request->validate([
            'amenity_id' => 'required|exists:amenities,id',
            'quantity' => 'required|integer|min:1',
            'status' => 'required|in:pending,approved,in_progress,completed,rejected',
        ]);

        // Backend enforcement, not just the dropdown's own filtering - a
        // Free/Included amenity can never become a chargeable request.
        $amenity = Amenity::where('charge', '>', 0)->find($validated['amenity_id']);
        if (! $amenity) {
            return back()->withInput()->with('error', 'Only Paid/Additional amenities can be requested.');
        }

        AmenityRequest::create([
            'guest_id' => $reservation->guest_id,
            'reservation_id' => $reservation->id,
            'room_id' => $reservation->booking->room_id,
            'room_type_id' => $reservation->room_type_id,
            'amenity_id' => $amenity->id,
            // Snapshot the amenity's current name/category/charge at the
            // time of the request - a later admin rename/recategorize/price
            // change must not rewrite this historical record.
            'amenity_name' => $amenity->amenity_name,
            'category' => $amenity->category,
            'quantity' => $validated['quantity'],
            'charge' => (float) $amenity->charge,
            'status' => $validated['status'],
        ]);

        return redirect()->route('receptionist.amenities.index')->with('success', 'Amenity request added.');
    }
}
