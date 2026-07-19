<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile "Book & Pay" path - mirrors Guest\BookingController (web).
 * Api\ReservationController@store stays Reserve-only (no payment, no
 * Booking row); this creates the Reservation and a pending-verification
 * payment together in one call.
 */
class BookingController extends Controller
{
    protected BookingService $bookingService;
    protected NotificationService $notificationService;

    public function __construct(BookingService $bookingService, NotificationService $notificationService)
    {
        $this->bookingService = $bookingService;
        $this->notificationService = $notificationService;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required|string|max:100',
            'payment_type' => 'required|in:partial,full',
        ]);
        $children = $validated['children'] ?? 0;

        $user = auth()->user();
        $guest = $user->guest;

        $roomType = RoomType::findOrFail($validated['room_type_id']);

        if ($roomType->status !== 'active') {
            return response()->json(['message' => 'This room type is not currently offered.'], 422);
        }

        if (! $roomType->rooms()->where('status', '!=', 'maintenance')->exists()) {
            return response()->json(['message' => 'No rooms of this type are currently in service.'], 422);
        }

        $idCardType = $validated['id_card_type'] ?? 'None';

        $reservation = Reservation::create([
            'guest_id' => $guest->id,
            'room_type_id' => $roomType->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'adults' => $validated['adults'],
            'children' => $children,
            'number_of_guests' => $validated['adults'] + $children,
            'status' => 'pending',
            'id_card_type' => $idCardType === 'None' ? null : $idCardType,
            'additional_guest_details' => $validated['additional_guests'] ?? null,
        ]);

        $quote = $this->bookingService->quoteRoomCharge($reservation);
        $amountPaid = $validated['payment_type'] === 'full'
            ? $quote['total']
            : round($quote['total'] * (float) config('hotel.partial_payment_ratio', 0.5), 2);

        $payment = $this->bookingService->recordPayment($reservation, [
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'],
            'amount_paid' => $amountPaid,
        ], staffRecorded: false);

        $this->notificationService->notifyPaymentSubmitted($user, $amountPaid, $roomType->name);

        $reservation->load(['room.roomType', 'roomType', 'booking.billing']);

        return response()->json([
            'reservation' => $reservation,
            'payment' => $payment,
        ], 201);
    }
}
