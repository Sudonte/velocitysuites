<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\RoomType;
use App\Support\StaysInput;
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
                'rooms_requested' => $available > 0
                    ? "No more {$roomType->name} rooms available for these dates ({$available} available)."
                    : "{$roomType->name} is fully booked for these dates.",
            ]);
        }
    }

    /**
     * Validates every line in a multi-room-type selection - see this
     * class's own doc for the `$roomLines` shape. All-or-nothing: throws on
     * the first line that fails (never creates a booking containing only
     * the room types that happened to pass).
     *
     * Quantities are summed PER DISTINCT room_type_id before any single
     * availability check runs - previously each raw line was checked
     * independently, so a request submitting the same room type across two
     * separate lines (e.g. "Deluxe x3" twice, with only 3 Deluxe rooms
     * physically existing) passed availableCount() twice against the same
     * free inventory and created a direct Booking with rooms_requested=6
     * for a type with only 3 real rooms - confirmed live. A direct Booking
     * consumes inventory immediately (see validateRoomTypeAvailability()'s
     * own doc), so this must never under-validate the true combined
     * requirement, exactly like RoomAvailabilityService::
     * resolveAndValidateRoomLines()'s identical fix on the receptionist
     * side.
     */
    public function validateRoomLinesAvailability(array $roomLines, Carbon $checkIn, Carbon $checkOut): void
    {
        $quantitiesByRoomTypeId = [];
        $roomTypesById = [];
        foreach ($roomLines as $line) {
            $roomTypeId = $line['room_type']->id;
            $roomTypesById[$roomTypeId] = $line['room_type'];
            $quantitiesByRoomTypeId[$roomTypeId] = ($quantitiesByRoomTypeId[$roomTypeId] ?? 0) + (int) $line['quantity'];
        }

        foreach ($quantitiesByRoomTypeId as $roomTypeId => $quantity) {
            $this->validateRoomTypeAvailability($roomTypesById[$roomTypeId], $checkIn, $checkOut, $quantity);
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
     * A one-stay booking: the stay's rooms, dates and amenities. Same as createWithStays() with a single stay.
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
        return $this->createWithStays(
            $guest,
            [['check_in' => $checkIn, 'check_out' => $checkOut, 'lines' => $roomLines, 'amenities' => $resolvedAmenities]],
            $adults, $children, $guestName, $additionalGuests, $idCard, $paymentData, $idempotencyKey
        );
    }

    /**
     * Creates ONE Booking - one transaction, one ID - holding every stay (see StaysInput for the shape), its itemized room lines per
     * stay, its Payment, and any paid-amenity requests atomically: either the whole transaction lands, or none of it does. The parent
     * Booking row's own check_in / check_out are the overall span and room_type_id / rooms_requested stay the first type and the total
     * room count, purely for backward-compatible display. `$paymentData` mirrors Api\PaymentController::store()'s already-validated
     * shape (payment_method, reference_number, gcash_number, receipt_path, amount_paid). `$idCard` is ['type' => ..., 'path' => ...]
     * or null - one ID for the whole transaction.
     *
     * @param  array<int,array{check_in: Carbon, check_out: Carbon, lines: array, amenities: Collection}>  $stays
     */
    public function createWithStays(
        Guest $guest,
        array $stays,
        int $adults,
        int $children,
        array $guestName,
        ?array $additionalGuests,
        ?array $idCard,
        array $paymentData,
        ?string $idempotencyKey = null
    ): Booking {
        // array_values(), not the raw validated array - a multipart request whose additional_guests[i][*] fields arrived out of index
        // order produces a PHP array whose keys are not in 0..n-1 order, which json_encode() then serializes as a JSON OBJECT instead
        // of a JSON ARRAY and crashes Android's Gson (confirmed live via 4 corrupted rows). array_values() guarantees a real list.
        $additionalGuests = $additionalGuests !== null ? array_values($additionalGuests) : null;
        $stays = array_values($stays);
        $recorder = app(StayRecorder::class);

        return DB::transaction(function () use ($guest, $stays, $adults, $children, $guestName, $additionalGuests, $idCard, $paymentData, $idempotencyKey, $recorder) {
            // The caller already ran a fast-fail availability check, but that was a snapshot that could be stale by now: two guests
            // racing for the last room of a type could both pass it. Locking every requested room_type row (stable ascending order,
            // so two overlapping bookings can't deadlock) makes a truly concurrent create wait for this one to commit, so the re-check
            // right below sees the other request's rooms.
            $this->availability->lockRoomTypesForAvailabilityCheck(collect($stays)->flatMap(fn ($s) => collect($s['lines'])->pluck('room_type.id')));
            if ($shortfall = $this->availability->staysShortfall($stays)) {
                StaysInput::throwShortfall($shortfall);
            }

            $summary = $recorder->summaryColumns($stays);

            // idempotency_key is set as part of THIS insert, not a later update: a collision (two requests racing with the same key)
            // must fail at the earliest point, before any child row exists, so the rollback leaves nothing behind for the loser.
            $booking = Booking::create([
                'reservation_id' => null,
                'guest_id' => $guest->id,
                'guest_first_name' => $guestName['first_name'],
                'guest_middle_name' => $guestName['middle_name'] ?? null,
                'guest_last_name' => $guestName['last_name'],
                'room_type_id' => $summary['room_type_id'],
                'rooms_requested' => $summary['rooms_requested'],
                'check_in' => $summary['check_in'],
                'check_out' => $summary['check_out'],
                'adults' => $adults,
                'children' => $children,
                'number_of_guests' => $adults + $children,
                'confirmed_at' => now(),
                'booking_status' => Booking::STATUS_ACTIVE,
                'payment_method' => $paymentData['payment_method'],
                'id_card_type' => $idCard['type'] ?? null,
                'discount_id' => $idCard['discount_id'] ?? null,
                'id_card_image_path' => $idCard['path'] ?? null,
                'discount_requested' => $idCard !== null,
                'discount_verification_status' => $idCard !== null ? 'pending' : 'not_requested',
                'additional_guest_details' => $additionalGuests,
                'idempotency_key' => $idempotencyKey,
                // No verified_by - no staff member actually verified anything here, the system is just recognizing there's nothing to
                // verify (a Cash booking); a GCash booking still starts unverified, needing Receptionist\PaymentController::verify().
                'verified_at' => $paymentData['payment_method'] === 'gcash' ? null : now(),
            ]);

            $savedStays = $recorder->record($booking, $stays);

            $payment = Payment::create([
                'reservation_id' => null,
                'booking_id' => $booking->id,
                'payment_method' => $paymentData['payment_method'],
                'reference_number' => $paymentData['reference_number'] ?? null,
                'gcash_number' => $paymentData['gcash_number'] ?? null,
                'receipt_path' => $paymentData['receipt_path'] ?? null,
                'amount_paid' => $paymentData['amount_paid'],
                'payment_status' => $paymentData['payment_method'] === 'gcash' ? 'pending' : 'completed',
                // Computed by the caller against the transaction total; 'final' for a caller that doesn't set it.
                'payment_stage' => $paymentData['payment_stage'] ?? 'final',
                'payment_date' => now(),
            ]);

            // A direct booking has no separate "verify the transaction" step the way a Reservation does - it's created already confirmed.
            // The equivalent gate here is payment verification: a Cash payment is already 'completed' at creation, so its amenity
            // requests can start approved; a GCash payment starts 'pending' until the receptionist verifies it, so its amenity requests
            // start pending too and get bulk-flipped to approved at that same verification step.
            $initialAmenityStatus = $payment->payment_status === 'completed' ? 'approved' : 'pending';

            foreach ($stays as $i => $stay) {
                foreach ($stay['amenities'] as $entry) {
                    /** @var Amenity $amenity */
                    $amenity = $entry['amenity'];
                    AmenityRequest::create([
                        'guest_id' => $guest->id,
                        'reservation_id' => null,
                        'booking_id' => $booking->id,
                        'stay_id' => $savedStays[$i]->id,
                        // keyed to the first room type of THIS stay (amenities aren't attributed to a specific room type downstream)
                        'room_type_id' => $stay['lines'][0]['room_type']->id,
                        'amenity_id' => $amenity->id,
                        'amenity_name' => $amenity->amenity_name,
                        'category' => $amenity->category,
                        'quantity' => $entry['quantity'],
                        'charge' => $amenity->charge,
                        'status' => $initialAmenityStatus,
                    ]);
                }
            }

            return $booking->fresh(['roomType', 'guest.user', 'payments', 'roomLines']);
        });
    }
}
