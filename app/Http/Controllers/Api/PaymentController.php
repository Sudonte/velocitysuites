<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected NotificationService $notificationService;
    protected BookingService $bookingService;

    public function __construct(NotificationService $notificationService, BookingService $bookingService)
    {
        $this->notificationService = $notificationService;
        $this->bookingService = $bookingService;
    }

    /**
     * Submit a guest-side payment claim (e.g. GCash reference number)
     * against an already-created Reservation - the "convert my
     * Reservation into a Booking" self-service path (paying fresh at
     * booking time instead goes through Api\BookingController@store).
     * Both go through BookingService with staffRecorded=false, so they
     * land in the identical pending-verification state.
     */
    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($reservation->status, ['pending', 'confirmed'])) {
            return response()->json(['message' => 'This reservation is not payable.'], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required|string|max:100',
            'amount_paid' => 'required|numeric|min:1',
        ]);

        $payment = $this->bookingService->recordPayment($reservation, $validated, staffRecorded: false);

        $user = auth()->user();
        $reservation->loadMissing(['roomType', 'room']);
        $roomName = $reservation->room->room_name ?? $reservation->roomType->name;
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $roomName);

        return response()->json([
            'payment' => $payment,
            'billing' => $payment->billing->fresh(),
        ], 201);
    }
}
