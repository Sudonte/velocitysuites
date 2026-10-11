<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Reservation;
use App\Services\AmenityRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Request Additional Amenities" in the guest's Payment Details: list this stay's requests with their status, and submit new
 * ones. Ownership and "is this stay still active?" are enforced here and in AmenityRequestService, never just in the app.
 * Works from either id the app holds - the booking, or the reservation that became it.
 */
class AdditionalAmenityController extends Controller
{
    public function __construct(private AmenityRequestService $service)
    {
    }

    public function indexForBooking(Booking $booking): JsonResponse
    {
        return $this->index($booking);
    }

    public function storeForBooking(Request $request, Booking $booking): JsonResponse
    {
        return $this->store($request, $booking);
    }

    public function indexForReservation(Reservation $reservation): JsonResponse
    {
        return $this->index($reservation->booking, $reservation);
    }

    public function storeForReservation(Request $request, Reservation $reservation): JsonResponse
    {
        return $this->store($request, $reservation->booking, $reservation);
    }

    private function owns(?Booking $booking, ?Reservation $reservation): bool
    {
        $guestId = auth()->user()->guest?->id;
        if ($guestId === null) {
            return false;
        }
        if ($reservation) {
            return $reservation->guest_id === $guestId;
        }

        return $booking && ($booking->reservation_id ? $booking->reservation?->guest_id : $booking->guest_id) === $guestId;
    }

    private function index(?Booking $booking, ?Reservation $reservation = null): JsonResponse
    {
        if (! $this->owns($booking, $reservation)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (! $booking) {
            return response()->json([
                'can_request' => false,
                'reason' => 'Extra amenities can be requested once your booking is confirmed.',
                'requests' => [],
                'total_approved' => 0,
            ]);
        }

        $reason = $this->service->blockedReason($booking);
        $rows = $this->requestsFor($booking);

        return response()->json([
            'booking_id' => $booking->id,
            'can_request' => $reason === null,
            'reason' => $reason,
            'requests' => $rows->map(fn ($r) => $this->present($r))->values(),
            'total_approved' => round((float) $rows->where('status', 'approved')->sum(fn ($r) => $r->charge * $r->quantity), 2),
        ]);
    }

    private function store(Request $request, ?Booking $booking, ?Reservation $reservation = null): JsonResponse
    {
        if (! $this->owns($booking, $reservation)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if (! $booking) {
            return response()->json(['message' => 'Extra amenities can be requested once your booking is confirmed.'], 422);
        }

        $data = $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.amenity_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $created = $this->service->submit($booking, $data['items'], $data['note'] ?? null);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'can_request' => true,
            'reason' => null,
            'requests' => $created->map(fn ($r) => $this->present($r))->values(),
        ], 201);
    }

    private function requestsFor(Booking $booking)
    {
        return AmenityRequest::where('origin', AmenityRequest::ORIGIN_GUEST_REQUEST)
            ->where($booking->reservation_id ? 'reservation_id' : 'booking_id', $booking->reservation_id ?? $booking->id)
            ->latest('id')
            ->get();
    }

    private function present(AmenityRequest $r): array
    {
        return [
            'id' => $r->id,
            'amenity_id' => $r->amenity_id,
            'name' => $r->amenity_name,
            'quantity' => (int) $r->quantity,
            'unit_price' => round((float) $r->charge, 2),
            'subtotal' => round((float) $r->charge * (int) $r->quantity, 2),
            'status' => $r->status,
            'rejection_reason' => $r->rejection_reason,
            'note' => $r->note,
            'requested_at' => $r->created_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
        ];
    }
}
