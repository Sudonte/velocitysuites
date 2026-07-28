<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Submit a guest-side deposit payment claim against an existing
 * Reservation - the mobile equivalent of the website's Pay Now (GCash) /
 * Pay Later (Cash) deposit flow. Uses the exact same
 * ReservationWorkflowService the website uses, so a mobile guest and a
 * website guest land in identical states: no Booking is created here, no
 * discount is auto-applied - both only ever happen when a receptionist
 * reviews the reservation (see Receptionist\ReservationController).
 */
class PaymentController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ReservationWorkflowService $workflow,
    ) {
    }

    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($reservation->status, ['pending_review', 'ready_for_booking'])) {
            return response()->json(['message' => 'This reservation is not payable.'], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:100',
            'amount_paid' => 'required|numeric|min:1',
        ]);

        if ($validated['payment_method'] === 'gcash') {
            $payment = $this->workflow->recordDepositPayment($reservation, [
                'payment_method' => 'gcash',
                'reference_number' => $validated['reference_number'],
                'amount_paid' => $validated['amount_paid'],
            ]);
        } else {
            $payment = $this->workflow->recordCashIntent($reservation, (float) $validated['amount_paid']);
        }

        $user = auth()->user();
        $reservation->refresh()->loadMissing('roomType');
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $reservation->roomType->name);

        return response()->json([
            'payment' => $payment,
            'reservation' => $reservation,
        ], 201);
    }
}
