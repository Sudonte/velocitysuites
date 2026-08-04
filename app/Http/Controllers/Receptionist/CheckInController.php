<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\NotificationService;
use App\Services\RoomAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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

        $bookings = Booking::with(['reservation.guest.user', 'rooms', 'roomType'])
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
     * separate "assign, then check in" step anymore.
     */
    public function panel(Booking $booking)
    {
        if ($booking->booking_status !== 'confirmed') {
            abort(422, 'Only confirmed bookings can be checked in.');
        }

        $booking->load(['reservation.guest.user', 'roomType']);
        $assignableRooms = $this->availability->assignableRooms($booking);

        return view('receptionist.check-in.partials.panel', compact('booking', 'assignableRooms'));
    }

    /**
     * Assign the room(s) and check the guest in as one step. A booking may
     * request more than one room (rooms_requested) - the receptionist must
     * pick exactly that many distinct rooms in the popup. Re-validates
     * every room against a fresh assignableRooms() query (not just the
     * list the popup was opened with) so a stale/concurrent selection
     * can't double-book a room.
     */
    public function store(Request $request, Booking $booking)
    {
        if ($booking->booking_status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed bookings can be checked in.'], 422);
        }

        $validated = $request->validate([
            'room_ids' => 'required|array|size:' . $booking->rooms_requested,
            'room_ids.*' => 'required|integer|distinct',
        ], [
            'room_ids.size' => 'This booking needs exactly ' . $booking->rooms_requested . ' room(s) assigned - select ' . $booking->rooms_requested . '.',
            'room_ids.*.distinct' => 'The same room was selected more than once.',
        ]);

        $assignable = $this->availability->assignableRooms($booking)->keyBy('id');
        $rooms = collect($validated['room_ids'])->map(fn ($id) => $assignable->get((int) $id));

        if ($rooms->contains(null)) {
            return response()->json([
                'message' => 'One or more selected rooms are no longer available: they are not a free ' . $booking->roomType->name . ' room for these dates. Please re-open this panel and try again.',
            ], 422);
        }

        DB::transaction(function () use ($booking, $rooms) {
            $booking->rooms()->sync($rooms->pluck('id'));
            $booking->update(['room_id' => $rooms->first()->id, 'booking_status' => 'checked_in']);

            foreach ($rooms as $room) {
                $room->update(['status' => 'occupied']);
            }

            $this->notificationService->notifyCheckIn(
                $booking->reservation->guest->user,
                $rooms->pluck('room_name')->implode(', ')
            );
        });

        $roomLabel = $rooms->count() > 1 ? 'Rooms ' . $rooms->pluck('room_number')->implode(', ') : 'Room ' . $rooms->first()->room_number;

        return response()->json(['message' => 'Guest checked in to ' . $roomLabel . '!']);
    }
}
