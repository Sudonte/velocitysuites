<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a "New Booking" transaction - a guest mobile-app path that is a
 * genuinely independent record, never derived from or routed through a
 * Reservation (contrast with the existing Reservation -> receptionist
 * Convert-to-Booking path, which this service does not touch or replace).
 * A Booking created here has `reservation_id = null` for its entire
 * lifetime; its own auto-increment `id` is the Booking #.
 *
 * Mirrors the validation/creation conventions already established by
 * Api\ReservationController::store() and ReservationWorkflowService as
 * closely as possible so the two transaction types stay behaviorally
 * consistent, while never creating a Reservation row.
 */
class DirectBookingService
{
    public function __construct(
        private RoomAvailabilityService $availability,
        private ReservationAmenityService $amenityService,
    ) {
    }

    /**
     * Confirms the requested room type actually has enough available
     * inventory for the dates - the exact same check
     * Api\ReservationController::store() implicitly relies on later at
     * conversion time, applied up front here since a direct booking
     * consumes inventory immediately (booking_status starts at
     * 'confirmed', not a pending/reviewable state).
     */
    public function validateRoomAvailability(RoomType $roomType, Carbon $checkIn, Carbon $checkOut, int $roomsRequested): void
    {
        if ($roomType->status !== 'active') {
            throw ValidationException::withMessages(['room_type_id' => 'This room type is not currently offered.']);
        }

        $available = $this->availability->availableCount($roomType, $checkIn, $checkOut);
        if ($available < $roomsRequested) {
            throw ValidationException::withMessages([
                'rooms_requested' => $roomsRequested > 1
                    ? "Not enough {$roomType->name} rooms available for these dates (needs {$roomsRequested}, only {$available} free)."
                    : "This room type is fully booked for these dates.",
            ]);
        }
    }

    /**
     * The full amount due for a direct booking - room charge (rate x
     * nights x rooms) plus every selected paid amenity's subtotal.
     * amount_paid may now be less than this (a partial/deposit payment,
     * mirroring ReservationWorkflowService::depositRange()) - see
     * Api\BookingController::store()'s validation and the `payment_stage`
     * it computes against this total.
     */
    public function totalAmountDue(RoomType $roomType, int $nights, int $roomsRequested, Collection $resolvedAmenities): float
    {
        $roomTotal = (float) $roomType->rate * max(1, $nights) * max(1, $roomsRequested);
        $amenityTotal = $resolvedAmenities->sum(fn (array $entry) => (float) $entry['amenity']->charge * $entry['quantity']);

        return round($roomTotal + $amenityTotal, 2);
    }

    /**
     * Creates the Booking, its Payment, and any paid-amenity request rows
     * atomically - either the whole transaction lands, or none of it does.
     * `$paymentData` mirrors Api\PaymentController::store()'s already-
     * validated shape (payment_method, reference_number, gcash_number,
     * receipt_path, amount_paid). `$idCard` is ['type' => ..., 'path' => ...]
     * or null when the guest didn't need to attach one.
     */
    public function create(
        Guest $guest,
        RoomType $roomType,
        Carbon $checkIn,
        Carbon $checkOut,
        int $roomsRequested,
        int $adults,
        int $children,
        array $guestName,
        ?array $additionalGuests,
        ?array $idCard,
        array $paymentData,
        Collection $resolvedAmenities
    ): Booking {
        return DB::transaction(function () use (
            $guest, $roomType, $checkIn, $checkOut, $roomsRequested, $adults, $children,
            $guestName, $additionalGuests, $idCard, $paymentData, $resolvedAmenities
        ) {
            $booking = Booking::create([
                'reservation_id' => null,
                'guest_id' => $guest->id,
                'guest_first_name' => $guestName['first_name'],
                'guest_middle_name' => $guestName['middle_name'] ?? null,
                'guest_last_name' => $guestName['last_name'],
                'room_type_id' => $roomType->id,
                'rooms_requested' => $roomsRequested,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $adults,
                'children' => $children,
                'number_of_guests' => $adults + $children,
                'confirmed_at' => now(),
                'booking_status' => 'confirmed',
                'payment_method' => $paymentData['payment_method'],
                'id_card_type' => $idCard['type'] ?? null,
                'id_card_image_path' => $idCard['path'] ?? null,
                'discount_requested' => $idCard !== null,
                'discount_verification_status' => $idCard !== null ? 'pending' : 'not_requested',
                'additional_guest_details' => $additionalGuests,
            ]);

            $payment = Payment::create([
                'reservation_id' => null,
                'booking_id' => $booking->id,
                'payment_method' => $paymentData['payment_method'],
                'reference_number' => $paymentData['reference_number'] ?? null,
                'gcash_number' => $paymentData['gcash_number'] ?? null,
                'receipt_path' => $paymentData['receipt_path'] ?? null,
                'amount_paid' => $paymentData['amount_paid'],
                'payment_status' => $paymentData['payment_method'] === 'gcash' ? 'pending' : 'completed',
                // Computed by the caller against totalAmountDue() - defaults
                // to 'final' for any caller that doesn't set it (e.g. a
                // future full-payment-only path), preserving prior behavior.
                'payment_stage' => $paymentData['payment_stage'] ?? 'final',
                'payment_date' => now(),
            ]);

            // A direct booking has no separate "verify the transaction" step
            // the way a Reservation does (Receptionist\ReservationController
            // ::verify()) - it's created already confirmed. The equivalent
            // gate here is payment verification: a Cash payment is already
            // 'completed' at creation (nothing left to verify), so its
            // amenity requests can start approved; a GCash payment starts
            // 'pending' until the receptionist verifies it
            // (Receptionist\PaymentController), so its amenity requests
            // start pending too and get bulk-flipped to approved at that
            // same verification step (see the PaymentController audit).
            $initialAmenityStatus = $payment->payment_status === 'completed' ? 'approved' : 'pending';

            foreach ($resolvedAmenities as $entry) {
                /** @var Amenity $amenity */
                $amenity = $entry['amenity'];
                $quantity = $entry['quantity'];

                AmenityRequest::create([
                    'guest_id' => $guest->id,
                    'reservation_id' => null,
                    'booking_id' => $booking->id,
                    'room_type_id' => $roomType->id,
                    'amenity_id' => $amenity->id,
                    'amenity_name' => $amenity->amenity_name,
                    'category' => $amenity->category,
                    'quantity' => $quantity,
                    'charge' => $amenity->charge,
                    'status' => $initialAmenityStatus,
                ]);
            }

            return $booking->fresh(['roomType', 'guest.user', 'payments']);
        });
    }
}
