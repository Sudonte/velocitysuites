<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Rules\ValidPhoneNumber;
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
     * AJAX popup content: a Guest Details registration step (full name,
     * permanent/current address, contact number, actual adult/child count -
     * pre-filled from whatever's on file but editable, since whoever is
     * actually at the counter, and how many of them there are, can differ
     * from what was booked) followed by the room-assignment picker,
     * pre-filtered to rooms that actually match the booked room type and
     * are free for the reservation period. Both steps live in one form -
     * Guest Details, Assign Room, and Check In are a single action from
     * here, submitted together by store() below.
     */
    public function panel(Booking $booking)
    {
        if ($booking->booking_status !== 'confirmed') {
            abort(422, 'Only confirmed bookings can be checked in.');
        }

        $booking->load(['reservation.guest.user', 'guest.user', 'roomType', 'rooms']);
        $assignableRooms = $this->availability->assignableRooms($booking);
        $assignedRoomIds = $booking->rooms->pluck('id')->all();
        $accountGuest = $booking->account_guest;

        return view('receptionist.check-in.partials.panel', compact(
            'booking', 'assignableRooms', 'assignedRoomIds', 'accountGuest'
        ));
    }

    /**
     * Record the Guest Details registration step, assign the room(s), and
     * check the guest in - all as one step. A booking may request more
     * than one room (rooms_requested) - the receptionist must pick exactly
     * that many distinct rooms in the popup. Delegates the "pick N rooms,
     * make sure they're really free" step to
     * RoomAvailabilityService::assignRooms() - re-validates every room
     * against a fresh query, not just the list the popup was opened with,
     * so a stale/concurrent selection can't double-book a room.
     *
     * adults/children are overwritten with whatever the receptionist
     * confirms here (not just whatever the booking originally requested) -
     * CheckOutController::generateBilling() computes the extra-guest fee
     * straight off these columns, so correcting the headcount here is what
     * makes that fee accurate for walk-up additions.
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
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'checkin_permanent_address' => 'required|string|max:255',
            'current_address_same_as_permanent' => 'nullable|boolean',
            'checkin_current_address' => 'required_if:current_address_same_as_permanent,false|nullable|string|max:255',
            'checkin_contact_number' => [
                'required', 'string', 'max:20',
                new ValidPhoneNumber($booking->account_guest?->country ?? 'Philippines'),
            ],
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'room_ids' => 'required|array|size:' . $booking->rooms_requested,
            'room_ids.*' => 'required|integer|distinct',
        ], [
            'room_ids.size' => 'This booking needs exactly ' . $booking->rooms_requested . ' room(s) assigned - select ' . $booking->rooms_requested . '.',
            'room_ids.*.distinct' => 'The same room was selected more than once.',
            'checkin_current_address.required_if' => 'Enter the guest\'s current address, or check "Same as permanent address".',
        ]);

        $children = (int) ($validated['children'] ?? 0);
        $currentAddress = ($validated['current_address_same_as_permanent'] ?? false)
            ? $validated['checkin_permanent_address']
            : $validated['checkin_current_address'];

        try {
            $rooms = null;
            DB::transaction(function () use ($booking, $validated, $children, $currentAddress, &$rooms) {
                $rooms = $this->availability->assignRooms($booking, $validated['room_ids']);

                foreach ($rooms as $room) {
                    $room->update(['status' => 'occupied']);
                }

                $booking->update([
                    'guest_first_name' => $validated['guest_first_name'],
                    'guest_middle_name' => $validated['guest_middle_name'] ?? null,
                    'guest_last_name' => $validated['guest_last_name'],
                    'checkin_permanent_address' => $validated['checkin_permanent_address'],
                    'checkin_current_address' => $currentAddress,
                    'checkin_contact_number' => $validated['checkin_contact_number'],
                    'adults' => $validated['adults'],
                    'children' => $children,
                    'number_of_guests' => $validated['adults'] + $children,
                    'booking_status' => 'checked_in',
                ]);
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

