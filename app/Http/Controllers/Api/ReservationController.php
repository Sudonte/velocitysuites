<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * List the authenticated guest's reservations, same query as
     * Guest\GuestController@bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;
        $query = $guest->reservations()->with(['room.roomType', 'roomType', 'booking.billing']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest('check_in')->paginate(15));
    }

    /**
     * Show a single reservation (ownership-checked).
     */
    public function show(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reservation->load(['room.roomType', 'roomType', 'booking.billing']);

        return response()->json($reservation);
    }

    /**
     * Create a reservation. Identical validation/creation logic to
     * Guest\ReservationController@store - the reservation records the
     * requested room TYPE, not the specific room; a receptionist assigns
     * an actual room at confirmation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);
        $children = $validated['children'] ?? 0;

        $user = auth()->user();
        $guest = $user->guest;

        $room = Room::findOrFail($validated['room_id']);
        $roomType = $room->roomType;

        if ($roomType->status !== 'active') {
            return response()->json(['message' => 'This room type is not currently offered.'], 422);
        }

        if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return response()->json(['message' => 'No rooms of this type are currently in service.'], 422);
        }

        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'room_type_id' => $roomType->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => 'pending',
        ]);

        Booking::create([
            'reservation_id' => $reservation->id,
            'booking_date' => now(),
            'booking_status' => 'pending',
        ]);

        $this->notificationService->notifyNewBooking($user, $roomType->name);

        $reservation->load(['room.roomType', 'roomType', 'booking']);

        return response()->json($reservation, 201);
    }

    /**
     * Update a pending reservation's dates/guest counts. Same rules as
     * Guest\ReservationController@update.
     */
    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($reservation->status !== 'pending') {
            return response()->json(['message' => 'Can only modify pending reservations.'], 422);
        }

        $validated = $request->validate([
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);
        $children = $validated['children'] ?? 0;

        $reservation->update([
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
        ]);

        return response()->json($reservation);
    }

    /**
     * Cancel a reservation. Same transaction/release logic as
     * Guest\ReservationController@cancel.
     */
    public function cancel(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($reservation->status, ['pending', 'confirmed'])) {
            return response()->json(['message' => 'Cannot cancel this reservation.'], 422);
        }

        $user = auth()->user();
        $roomName = $reservation->room->room_name ?? $reservation->roomType->name;

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'cancelled']);
            $reservation->booking->update(['booking_status' => 'cancelled']);

            if ($reservation->room && $reservation->room->status === 'reserved') {
                $reservation->room->update(['status' => 'available']);
            }
        });

        $this->notificationService->notifyReservationCancelled($user, $roomName);

        return response()->json($reservation->fresh(['room.roomType', 'roomType', 'booking']));
    }
}
