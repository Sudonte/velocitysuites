<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Reservation;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Submit a guest-side payment claim (e.g. GCash reference number) for
     * a reservation. Mirrors how a receptionist records a payment, but
     * always lands as payment_status=pending since there's no in-app way
     * to verify the guest actually paid - staff confirm it the same way
     * they would a walk-in payment. Auto-creates the Billing row if the
     * reservation hasn't been confirmed/billed yet, using the same
     * room-type-rate * nights calculation as Guest\ReservationController@create.
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

        $reservation->loadMissing(['booking', 'roomType', 'room']);
        $booking = $reservation->booking;

        $billing = $booking->billing;
        if (! $billing) {
            $roomCharge = $reservation->roomType->rate * $reservation->number_of_nights;

            // Senior citizen / PWD statutory 20% discount, applied here
            // since this is the first point a Billing row exists for a
            // reservation that was never confirmed by a receptionist -
            // see Api\ReservationController::store for where id_card_type
            // gets recorded.
            $discount = in_array($reservation->id_card_type, ['Senior Citizen', 'PWD'], true)
                ? round($roomCharge * 0.20, 2)
                : 0;

            $billing = Billing::create([
                'booking_id' => $booking->id,
                'room_charge' => $roomCharge,
                'discount' => $discount,
                'total_amount' => $roomCharge - $discount,
                'billing_status' => 'pending',
            ]);
        }

        $payment = $billing->payments()->create([
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'],
            'amount_paid' => $validated['amount_paid'],
            'payment_status' => 'pending',
            'payment_date' => now(),
        ]);

        $user = auth()->user();
        $roomName = $reservation->room->room_name ?? $reservation->roomType->name;
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $roomName);

        return response()->json([
            'payment' => $payment,
            'billing' => $billing->fresh(),
        ], 201);
    }
}
