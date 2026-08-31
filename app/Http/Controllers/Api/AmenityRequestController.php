<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Post-booking Additional Amenity Requests for the Guest mobile app - a
 * guest may only request more of a Paid/Additional amenity that was
 * already selected during that reservation's original booking (see
 * ReservationAmenity, the snapshot table this is validated against).
 * Free/Included amenities and anything not originally selected are
 * rejected here at the backend, not just hidden in the UI.
 */
class AmenityRequestController extends Controller
{
    /**
     * List this reservation's originally-selected Paid/Additional
     * amenities, each with how much has already been requested since, so
     * the app can show "booked 2, already requested 1 more" and let the
     * guest request further quantities of the same amenities only.
     */
    public function requestable(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $alreadyRequested = AmenityRequest::where('reservation_id', $reservation->id)
            ->selectRaw('amenity_id, SUM(quantity) as total_qty')
            ->groupBy('amenity_id')
            ->pluck('total_qty', 'amenity_id');

        $items = $reservation->bookingAmenities->map(function ($row) use ($alreadyRequested) {
            return [
                'amenity_id' => $row->amenity_id,
                'amenity_name' => $row->amenity_name,
                'category' => $row->category,
                'price' => (float) $row->charge,
                'original_quantity' => $row->quantity,
                'already_requested_quantity' => (int) ($alreadyRequested[$row->amenity_id] ?? 0),
            ];
        })->values();

        return response()->json($items);
    }

    /**
     * Submit a request for additional quantity of an amenity already
     * selected during this reservation's original booking. Rejects
     * (422) an amenity_id that isn't in this reservation's
     * reservation_amenities - real backend enforcement, not a UI filter -
     * and a cancelled/rejected reservation (nothing left to fulfill).
     */
    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (in_array($reservation->status, ['cancelled', 'rejected'], true)) {
            return response()->json(['message' => 'This reservation is no longer active.'], 422);
        }

        $validated = $request->validate([
            'amenity_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        // Eligibility check only - was this amenity_id part of the
        // reservation's ORIGINAL booking-time selection at all? The row's
        // own name/category/price are not used below; they're that
        // original transaction's frozen values, not this new one's.
        $bookedAmenity = $reservation->bookingAmenities()
            ->where('amenity_id', $validated['amenity_id'])
            ->first();

        if (! $bookedAmenity) {
            return response()->json([
                'message' => 'You can only request additional quantities of amenities you already selected when booking.',
            ], 422);
        }

        // The live catalog amenity must still exist, be active, and be
        // paid - an amenity could theoretically be deactivated between
        // booking and this later request. Snapshot ITS current name/
        // category/price, same as the receptionist-created path
        // (Receptionist\ReceptionistController::amenitiesStore()) - a
        // post-booking request is its own transaction, frozen at its own
        // moment, independent of what the original booking snapshotted.
        $liveAmenity = Amenity::where('status', 'active')
            ->where('charge', '>', 0)
            ->find($validated['amenity_id']);

        if (! $liveAmenity) {
            return response()->json([
                'message' => 'This amenity is no longer available to request.',
            ], 422);
        }

        // Same stock ceiling ReservationAmenityService::validateSelection()
        // enforces at booking time - a post-booking top-up request must not
        // be able to ask for more than the amenity's own configured stock
        // either.
        if ($validated['quantity'] > $liveAmenity->quantity) {
            return response()->json([
                'message' => "\"{$liveAmenity->amenity_name}\" only has {$liveAmenity->quantity} available - please lower the quantity.",
            ], 422);
        }

        $amenityRequest = AmenityRequest::create([
            'guest_id' => $reservation->guest_id,
            'reservation_id' => $reservation->id,
            'room_id' => $reservation->booking->room_id ?? null,
            'room_type_id' => $reservation->room_type_id,
            'amenity_id' => $liveAmenity->id,
            'amenity_name' => $liveAmenity->amenity_name,
            'category' => $liveAmenity->category,
            'quantity' => $validated['quantity'],
            'charge' => $liveAmenity->charge,
            'status' => 'pending',
        ]);

        return response()->json($amenityRequest, 201);
    }

    /**
     * List this guest's own amenity requests for a reservation, most
     * recent first - lets the app show request history/status alongside
     * the requestable() summary above.
     */
    public function index(Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $requests = AmenityRequest::where('reservation_id', $reservation->id)
            ->latest()
            ->get();

        return response()->json($requests);
    }
}
