<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shared by Guest\ReservationController and Api\ReservationController so
 * booking-time Paid/Additional amenity selection is validated and
 * snapshotted identically on both the website and the mobile app.
 */
class ReservationAmenityService
{
    /**
     * Validates a raw `amenities` request array (each item shaped
     * ['amenity_id' => int, 'quantity' => int]) against the live catalog -
     * every amenity_id must resolve to a currently active, Paid/Additional
     * (charge > 0) amenity. Throws a normal Laravel ValidationException on
     * any invalid selection (inactive, free, or nonexistent amenity) so
     * the whole submission is rejected rather than silently dropping
     * items - this is real backend enforcement of "guests cannot select
     * inactive/free amenities as paid add-ons", not just a UI filter.
     * Also enforces the amenity's own configured stock (`amenities.quantity`)
     * as a hard per-selection ceiling - the Android app already caps the
     * quantity stepper at this same value client-side
     * (AddOnAmenity#getMaxQuantity()), but nothing previously stopped a
     * direct API call from requesting more than exists.
     * Returns the resolved [Amenity, quantity] pairs, ready to snapshot.
     */
    public function validateSelection(array $items): Collection
    {
        $resolved = collect();

        foreach ($items as $item) {
            $amenity = Amenity::where('status', 'active')
                ->where('charge', '>', 0)
                ->find($item['amenity_id'] ?? null);

            if (! $amenity) {
                throw ValidationException::withMessages([
                    'amenities' => 'One of the selected additional amenities is no longer available. Please review your selection.',
                ]);
            }

            $quantity = (int) $item['quantity'];

            if ($quantity > $amenity->quantity) {
                throw ValidationException::withMessages([
                    'amenities' => "\"{$amenity->amenity_name}\" only has {$amenity->quantity} available - please lower the quantity.",
                ]);
            }

            $resolved->push(['amenity' => $amenity, 'quantity' => $quantity]);
        }

        return $resolved;
    }

    /**
     * Writes the historical snapshot rows for a newly-created reservation,
     * and, alongside them, one `pending` AmenityRequest per selected
     * amenity - this is the "an amenity request only exists once the
     * booking transaction exists and is Pending" rule. `room_id` is left
     * null (no physical room is assigned yet at booking time);
     * `room_type_id` is the reservation's. These pending rows are later
     * flipped to approved/rejected in lockstep with the reservation itself
     * by Receptionist\ReservationController::verify() and
     * ReservationWorkflowService::reject(). Never touches the
     * reservation/booking's own price columns - the base room price is
     * computed and stored exactly as it already was.
     */
    public function snapshot(Reservation $reservation, Collection $resolved): void
    {
        foreach ($resolved as $entry) {
            $amenity = $entry['amenity'];
            $quantity = $entry['quantity'];

            $reservation->bookingAmenities()->create([
                'amenity_id' => $amenity->id,
                'amenity_name' => $amenity->amenity_name,
                'category' => $amenity->category,
                'charge' => $amenity->charge,
                'quantity' => $quantity,
                'subtotal' => $amenity->charge * $quantity,
            ]);

            AmenityRequest::create([
                'guest_id' => $reservation->guest_id,
                'reservation_id' => $reservation->id,
                'room_id' => null,
                'room_type_id' => $reservation->room_type_id,
                'amenity_id' => $amenity->id,
                'amenity_name' => $amenity->amenity_name,
                'category' => $amenity->category,
                'quantity' => $quantity,
                'charge' => $amenity->charge,
                'status' => 'pending',
            ]);
        }
    }
}
