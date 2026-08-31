<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\NotificationService;
use App\Services\RoomAvailabilityService;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The Check-in Module: two tabs - "Expected Check-ins" (confirmed bookings
 * awaiting arrival, where the receptionist assigns the actual room) and
 * "Checked-in Guests" (already checked in). Room assignment happens only
 * here, at check-in - never at reservation or booking time.
 */
class CheckInController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private RoomAvailabilityService $availability,
    ) {
    }

    public function index(Request $request): View
    {
        $tab = $request->get('tab', 'expected');
        if (!in_array($tab, ['expected', 'checked_in'])) {
            $tab = 'expected';
        }

        $bookings = Booking::with(['reservation.guest.user', 'guest.user', 'rooms', 'roomType'])
            ->where('booking_status', $tab === 'expected' ? 'confirmed' : 'checked_in')
            ->orderBy($tab === 'expected' ? 'check_in' : 'check_out')
            ->paginate(15)
            ->withQueryString();

        $expectedCount = Booking::where('booking_status', 'confirmed')->count();
        $checkedInCount = Booking::where('booking_status', 'checked_in')->count();

        return view('receptionist.check-in.index', compact('bookings', 'tab', 'expectedCount', 'checkedInCount'));
    }

    /**
     * AJAX popup content: full booking/guest details plus the room-
     * assignment picker, pre-filtered to rooms that actually match the
     * booked room type and are free for the reservation period. Assign
     * Room and Check In are a single action from here - there's no
     * separate "assign, then check in" step anymore. If the receptionist
     * already pre-assigned room(s) ahead of arrival (Receptionist\
     * BookingController's Assign Rooms action), those are pre-selected
     * here rather than making the receptionist pick again - Check In just
     * confirms them (still re-validated fresh at submit time either way).
     */
    public function panel(Booking $booking)
    {
        if ($booking->booking_status !== 'confirmed') {
            abort(422, 'Only confirmed bookings can be checked in.');
        }

        $booking->load(['reservation.guest.user', 'guest.user', 'roomType', 'rooms']);
        $assignableRooms = $this->availability->assignableRooms($booking);
        $assignedRoomIds = $booking->rooms->pluck('id')->all();

        return view('receptionist.check-in.partials.panel', compact('booking', 'assignableRooms', 'assignedRoomIds'));
    }

    /**
     * Assign the room(s) and check the guest in as one step. A booking may
     * request more than one room (rooms_requested) - the receptionist must
     * pick exactly that many distinct rooms in the popup. Delegates the
     * "pick N rooms, make sure they're really free" step to
     * RoomAvailabilityService::assignRooms() (shared with early assignment
     * in Receptionist\BookingController) - re-validates every room against
     * a fresh query, not just the list the popup was opened with, so a
     * stale/concurrent selection can't double-book a room.
     */
    public function store(Request $request, Booking $booking)
    {
        if ($booking->booking_status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed bookings can be checked in.'], 422);
        }

        // Guest must not be checked in earlier than the scheduled check-in
        // date - calendar-day comparison, since no specific "check-in hour"
        // concept exists anywhere else in this app.
        if (now()->startOfDay()->lt($booking->check_in->copy()->startOfDay())) {
            return response()->json([
                'message' => "This guest isn't scheduled to check in until {$booking->check_in->format('M d, Y')}. Early check-in isn't allowed.",
            ], 422);
        }

        $validated = $request->validate([
            'room_ids' => 'required|array|size:' . $booking->rooms_requested,
            'room_ids.*' => 'required|integer|distinct',
        ], [
            'room_ids.size' => 'This booking needs exactly ' . $booking->rooms_requested . ' room(s) assigned - select ' . $booking->rooms_requested . '.',
            'room_ids.*.distinct' => 'The same room was selected more than once.',
        ]);

        try {
            $rooms = null;
            DB::transaction(function () use ($booking, $validated, &$rooms) {
                $rooms = $this->availability->assignRooms($booking, $validated['room_ids']);

                foreach ($rooms as $room) {
                    $room->update(['status' => 'occupied']);
                }

                $booking->update(['booking_status' => 'checked_in']);
            });
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $accountGuest = $booking->account_guest?->user;
        if ($accountGuest) {
            $this->notificationService->notifyCheckIn(
                $accountGuest,
                $rooms->pluck('room_name')->implode(', '),
                $booking->reservation_id ?? $booking->id
            );
        }

        Activity::log(
            'Checked in guest',
            "Booking #{$booking->id} - {$booking->account_guest_full_name} to " . $rooms->pluck('room_number')->implode(', '),
            $booking
        );

        $roomLabel = $rooms->count() > 1 ? 'Rooms ' . $rooms->pluck('room_number')->implode(', ') : 'Room ' . $rooms->first()->room_number;

        return response()->json(['message' => 'Guest checked in to ' . $roomLabel . '!']);
    }
}

