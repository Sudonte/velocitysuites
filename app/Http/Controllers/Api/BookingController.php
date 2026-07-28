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
            'guest_first_name' => 'required|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'id_card_type' => 'nullable|in:None,Senior Citizen,PWD',
            'additional_guests' => 'nullable|array',
            'additional_guests.*.name' => 'required_with:additional_guests|string|max:150',
            'additional_guests.*.age' => 'required_with:additional_guests|integer|min:0',
            'additional_guests.*.gender' => 'nullable|string|max:30',
            'additional_guests.*.relationship' => 'nullable|string|max:50',
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required|string|max:100',
            'amount_paid' => 'required|numeric|min:0.01',
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
            'guest_first_name' => $validated['guest_first_name'],
            'guest_last_name' => $validated['guest_last_name'],
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
        $minimumPayment = round($quote['total'] * (float) config('hotel.minimum_payment_ratio', 0.5), 2);

        if ($validated['amount_paid'] < $minimumPayment || $validated['amount_paid'] > $quote['total']) {
            $reservation->delete();

            return response()->json([
                'message' => 'Payment amount must be between the minimum and full total.',
                'minimum_payment' => $minimumPayment,
                'maximum_payment' => $quote['total'],
            ], 422);
        }

        $payment = $this->bookingService->recordPayment($reservation, [
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'],
            'amount_paid' => $validated['amount_paid'],
        ], staffRecorded: false);

        $this->notificationService->notifyPaymentSubmitted($user, $validated['amount_paid'], $roomType->name);

        $reservation->load(['room.roomType', 'roomType', 'booking.billing']);

        return response()->json([
            'reservation' => $reservation,
            'payment' => $payment,
        ], 201);
    }
}
