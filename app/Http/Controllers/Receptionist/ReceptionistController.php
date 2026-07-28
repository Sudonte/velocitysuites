<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
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
        $bookingRequests = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])->count();
        $awaitingCheckIn = Booking::where('booking_status', 'confirmed')->count();
        $inHouseGuests = Booking::where('booking_status', 'checked_in')->count();

        // Today's schedule stays date-based - it's a schedule. Arrivals due
        // today (not yet checked in) and departures due today (still in house).
        $todayArrivals = Booking::with(['reservation.guest.user', 'room', 'roomType'])
            ->whereDate('check_in', today())
            ->where('booking_status', 'confirmed')
            ->get();

        $todayDepartures = Booking::with(['reservation.guest.user', 'room'])
            ->whereDate('check_out', today())
            ->where('booking_status', 'checked_in')
            ->get();

        return view('receptionist.dashboard', compact(
            'availableRooms',
            'bookingRequests',
            'awaitingCheckIn',
            'inHouseGuests',
            'todayArrivals',
            'todayDepartures'
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
     * Rooms of one type with their live statuses (read-only).
     */
    public function roomsShow(\App\Models\RoomType $roomType): View
    {
        $rooms = $roomType->rooms()->orderBy('room_number')->paginate(20);

        return view('receptionist.rooms.show', compact('roomType', 'rooms'));
    }

    /**
     * List all amenity requests.
     */
    public function amenitiesIndex(Request $request): View
    {
        $query = AmenityRequest::with(['guest.user', 'amenity']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $amenityRequests = $query->latest()->paginate(15);

        return view('receptionist.amenities.index', compact('amenityRequests'));
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

        $amenities = Amenity::where('status', 'active')
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
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $amenity = Amenity::findOrFail($validated['amenity_id']);

        AmenityRequest::create([
            'guest_id' => $reservation->guest_id,
            'reservation_id' => $reservation->id,
            'amenity_id' => $amenity->id,
            'quantity' => $validated['quantity'],
            // Snapshot the current charge per unit at the time of the request
            'charge' => (float) $amenity->charge,
            'status' => $validated['status'],
        ]);

        return redirect()->route('receptionist.amenities.index')->with('success', 'Amenity request added.');
    }

    /**
     * Update an amenity request status.
     */
    public function amenitiesUpdate(Request $request, AmenityRequest $amenityRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $amenityRequest->update($validated);

        return back()->with('success', 'Amenity request updated.');
    }
}
