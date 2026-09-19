<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingRoomLine;
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
 *
 * Multi-room-type support (2026-09-19): a single "New Booking" transaction
 * can now cover several distinct room types in one call (e.g. Deluxe x2 +
 * Suite x1) instead of exactly one - see MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md.
 * `$roomLines` throughout this class is an array of
 * ['room_type' => RoomType, 'quantity' => int] pairs, always at least one
 * entry (a genuinely single-room-type booking is simply a one-entry array,
 * not a separate code path).
 */
class DirectBookingService
{
    public function __construct(
        private RoomAvailabilityService $availability,
        private ReservationAmenityService $amenityService,
    ) {
    }

    /**
     * Confirms every requested room type/quantity actually has enough
     * available inventory for the dates - the exact same check
     * Api\ReservationController::store() implicitly relies on later at
     * conversion time, applied up front here since a direct booking
     * consumes inventory immediately (booking_status starts at
     * 'confirmed', not a pending/reviewable state). Throws on the first
     * room type that fails, naming that room type specifically - callers
     * should validate every line before creating anything (see
     * Api\BookingController::store()'s all-or-nothing contract).
     */
    public function validateRoomTypeAvailability(RoomType $roomType, Carbon $checkIn, Carbon $checkOut, int $roomsRequested): void
    {
        if ($roomType->status !== 'active') {
            throw ValidationException::withMessages(['room_type_id' => "{$roomType->name} is not currently offered."]);
        }

        $available = $this->availability->availableCount($roomType, $checkIn, $checkOut);
        if ($available < $roomsRequested) {
            throw ValidationException::withMessages([
                'rooms_requested' => $roomsRequested > 1
                    ? "Not enough {$roomType->name} rooms available for these dates (needs {$roomsRequested}, only {$available} free)."
                    : "{$roomType->name} is fully booked for these dates.",
            ]);
        }
    }

    /**
     * Validates every line in a multi-room-type selection - see this
     * class's own doc for the `$roomLines` shape. All-or-nothing: throws on
     * the first line that fails (never creates a booking containing only
     * the room types that happened to pass).
     */
    public function validateRoomLinesAvailability(array $roomLines, Carbon $checkIn, Carbon $checkOut): void
    {
        foreach ($roomLines as $line) {
            $this->validateRoomTypeAvailability($line['room_type'], $checkIn, $checkOut, $line['quantity']);
        }
    }

    /**
     * The full amount due for a direct booking - every room line's own
     * rate x nights x quantity, summed, plus every selected paid amenity's
     * subtotal. amount_paid may now be less than this (a partial/deposit
     * payment, mirroring ReservationWorkflowService::depositRange()) - see
     * Api\BookingController::store()'s validation and the `payment_stage`
     * it computes against this total.
     */
    public function totalAmountDueForLines(array $roomLines, int $nights, Collection $resolvedAmenities): float
    {
        $roomTotal = collect($roomLines)->sum(
            fn (array $line) => (float) $line['room_type']->rate * max(1, $nights) * max(1, $line['quantity'])
        );
        $amenityTotal = $resolvedAmenities->sum(fn (array $entry) => (float) $entry['amenity']->charge * $entry['quantity']);

        return round($roomTotal + $amenityTotal, 2);
    }

    /**
     * Creates the Booking, its itemized booking_room_lines (one per distinct
     * room type), its Payment, and any paid-amenity request rows atomically -
     * either the whole transaction lands, or none of it does. `$roomLines`
     * is never empty (see this class's own doc). The parent Booking row's
     * own `room_type_id`/`rooms_requested` are kept in sync from the first
     * line's type and the summed quantity across every line, purely for
     * backward-compatible display - identical convention to
     * Api\ReservationController::update()'s patched multi-room path.
     * `$paymentData` mirrors Api\PaymentController::store()'s already-
     * validated shape (payment_method, reference_number, gcash_number,
     * receipt_path, amount_paid). `$idCard` is ['type' => ..., 'path' => ...]
     * or null when the guest didn't need to attach one.
     */
    public function create(
        Guest $guest,
        array $roomLines,
        Carbon $checkIn,
        Carbon $checkOut,
        int $adults,
        int $children,
        array $guestName,
        ?array $additionalGuests,
        ?array $idCard,
        array $paymentData,
        Collection $resolvedAmenities,
        ?string $idempotencyKey = null
    ): Booking {
        return DB::transaction(function () use (
            $guest, $roomLines, $checkIn, $checkOut, $adults, $children,
            $guestName, $additionalGuests, $idCard, $paymentData, $resolvedAmenities, $idempotencyKey
        ) {
            $nights = max(1, abs($checkOut->diffInDays($checkIn)));
            $firstRoomType = $roomLines[0]['room_type'];
            $totalRoomsRequested = collect($roomLines)->sum('quantity');

            // idempotency_key is set here, as part of THIS insert, rather
            // than in a separate update() after the fact - a collision (two
            // requests racing with the same key) must fail at the earliest
            // possible point, before any room_lines/payment/amenity_request
            // rows are created, so the DB::transaction() rollback below
            // leaves nothing behind for the loser to clean up. See
            // Api\BookingController::store()'s catch of the resulting
            // UniqueConstraintViolationException for the re-fetch-and-return
            // side of this protection.
            $booking = Booking::create([
                'reservation_id' => null,
                'guest_id' => $guest->id,
                'guest_first_name' => $guestName['first_name'],
                'guest_middle_name' => $guestName['middle_name'] ?? null,
                'guest_last_name' => $guestName['last_name'],
                'room_type_id' => $firstRoomType->id,
                'rooms_requested' => $totalRoomsRequested,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $adults,
                'children' => $children,
                'number_of_guests' => $adults + $children,
                'confirmed_at' => now(),
                'booking_status' => Booking::STATUS_ACTIVE,
                'payment_method' => $paymentData['payment_method'],
                'id_card_type' => $idCard['type'] ?? null,
                'id_card_image_path' => $idCard['path'] ?? null,
                'discount_requested' => $idCard !== null,
                'discount_verification_status' => $idCard !== null ? 'pending' : 'not_requested',
                'additional_guest_details' => $additionalGuests,
                'idempotency_key' => $idempotencyKey,
                // No verified_by - no staff member actually verified
                // anything here, the system is just recognizing there's
                // nothing to verify (mirrors payment_status starting
                // 'completed' for Cash below, and
                // ReservationWorkflowService::convertToBooking()'s
                // identical auto-verify for a Cash reservation) - a GCash
                // booking still starts unverified, needing
                // Receptionist\PaymentController::verify() before it
                // leaves the Bookings module's "For Verification" tab.
                'verified_at' => $paymentData['payment_method'] === 'gcash' ? null : now(),
            ]);

            foreach ($roomLines as $line) {
                /** @var RoomType $roomType */
                $roomType = $line['room_type'];
                $quantity = $line['quantity'];

                BookingRoomLine::create([
                    'booking_id' => $booking->id,
                    'room_type_id' => $roomType->id,
                    'room_type_name' => $roomType->name,
                    'quantity' => $quantity,
                    'price_per_night' => $roomType->rate,
                    'number_of_nights' => $nights,
                    'subtotal' => round((float) $roomType->rate * $nights * $quantity, 2),
                ]);
            }

            $payment = Payment::create([
                'reservation_id' => null,
                'booking_id' => $booking->id,
                'payment_method' => $paymentData['payment_method'],
                'reference_number' => $paymentData['reference_number'] ?? null,
                'gcash_number' => $paymentData['gcash_number'] ?? null,
                'receipt_path' => $paymentData['receipt_path'] ?? null,
                'amount_paid' => $paymentData['amount_paid'],
                'payment_status' => $paymentData['payment_method'] === 'gcash' ? 'pending' : 'completed',
                // Computed by the caller against totalAmountDueForLines() -
                // defaults to 'final' for any caller that doesn't set it
                // (e.g. a future full-payment-only path), preserving prior
                // behavior.
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
                    // Amenity requests were always keyed to a single
                    // room_type_id even before multi-room-type support -
                    // kept pointed at the first line's type (the same
                    // "first line" convention used for the parent row's own
                    // room_type_id above), since amenities aren't currently
                    // attributed to a specific room type anywhere downstream.
                    'room_type_id' => $firstRoomType->id,
                    'amenity_id' => $amenity->id,
                    'amenity_name' => $amenity->amenity_name,
                    'category' => $amenity->category,
                    'quantity' => $quantity,
                    'charge' => $amenity->charge,
                    'status' => $initialAmenityStatus,
                ]);
            }

            return $booking->fresh(['roomType', 'guest.user', 'payments', 'roomLines']);
        });
    }
}
