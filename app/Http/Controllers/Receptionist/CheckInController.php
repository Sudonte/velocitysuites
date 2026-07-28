<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\NotificationService;
use App\Services\RoomAvailabilityService;
use Illuminate\Http\RedirectResponse;
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

        $bookings = Booking::with(['reservation.guest.user', 'room', 'roomType'])
            ->where('booking_status', $tab === 'expected' ? 'confirmed' : 'checked_in')
            ->orderBy($tab === 'expected' ? 'check_in' : 'check_out')
            ->paginate(15)
            ->withQueryString();

        // Room assignment happens on the Expected tab only.
        $assignableRooms = collect();
        if ($tab === 'expected') {
            $assignableRooms = $bookings->getCollection()
                ->filter(fn (Booking $booking) => !$booking->room)
                ->mapWithKeys(fn (Booking $booking) => [$booking->id => $this->availability->assignableRooms($booking)]);
        }

        $expectedCount = Booking::where('booking_status', 'confirmed')->count();
        $checkedInCount = Booking::where('booking_status', 'checked_in')->count();

        return view('receptionist.check-in.index', compact('bookings', 'tab', 'assignableRooms', 'expectedCount', 'checkedInCount'));
    }

    /**
     * Assign a room to an unassigned confirmed booking. The room must
     * actually be free for the booking's dates (not just "available"
     * status) - reuses the same assignableRooms() query, so a stale/
     * concurrent selection can't double-book a room.
     */
    public function assignRoom(Request $request, Booking $booking): RedirectResponse
    {
        if ($booking->booking_status !== 'confirmed') {
            return back()->with('error', 'Only confirmed bookings can have a room assigned.');
        }

        $validated = $request->validate(['room_id' => 'required|exists:rooms,id']);

        $room = $this->availability->assignableRooms($booking)->firstWhere('id', (int) $validated['room_id']);

        if (!$room) {
            return back()->with('error', 'That room cannot be assigned: it is not an available ' . $booking->roomType->name . ' room for these dates.');
        }

        $booking->update(['room_id' => $room->id]);

        return back()->with('success', 'Room ' . $room->room_number . ' assigned.');
    }

    /**
     * Mark booking as checked in. Date is not a gate - the room's actual
     * status is: a room still occupied by the previous guest or under
     * maintenance can't receive a new check-in. Moves the booking from
     * "Expected Check-ins" to "Checked-in Guests" automatically.
     */
    public function checkIn(Booking $booking): RedirectResponse
    {
        if ($booking->booking_status !== 'confirmed') {
            return back()->with('error', 'Only confirmed bookings can be checked in.');
        }

        if (!$booking->room) {
            return back()->with('error', 'Assign a room to this booking before checking in.');
        }

        if (in_array($booking->room->status, ['occupied', 'maintenance'])) {
            return back()->with('error',
                'Room ' . $booking->room->room_number . ' is not ready (' . $booking->room->status . '). ' .
                'Free it up first or assign a different room.');
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['booking_status' => 'checked_in']);
            $booking->room->update(['status' => 'occupied']);

            $this->notificationService->notifyCheckIn(
                $booking->reservation->guest->user,
                $booking->room->room_name
            );
        });

        return redirect()->route('receptionist.check-in.index', ['tab' => 'checked_in'])->with('success', 'Guest checked in successfully!');
    }
}
