<?php

namespace App\Support;

use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Discount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * THE one calculation of what a stay costs. The receptionist's Check-out bill (CheckOutController -> Billing)
 * and everything the guest's app prints (the billing's `stay_bill`, the Payment Receipt, Booking Details,
 * Payment History) read this and nothing else, so the line items and the amounts can never disagree.
 *
 * Order of the maths, always:
 *   1. each room:   rate x nights                      (rounded to 2 decimals, per room)
 *   2. + paid amenities / services, + extra charges     (add-ons)
 *   3. - discount, taken from the WHOLE of (1) + (2)    (App\Support\BillDiscount) - and only once the guest's
 *                                                       discount ID is APPROVED; never before
 *   4. = total;  - verified payments = balance
 * There is no tax/VAT/service fee anywhere in this hotel's billing.
 *
 * Nights are the BOOKED nights, CALENDAR days in Asia/Manila, minimum 1: check-in Oct 5 -> check-out Oct 6 is 1
 * night. The hotel has NO REFUNDS: a guest who leaves early still owes every booked night, so there is no credit
 * and no "stay length adjustment" anywhere - the room charge is the same on the day of booking, at check-in and
 * at check-out. A late check-out (or anything else extra) is a charge the front desk adds (additional charges),
 * never a recalculation of the nights. Total = room charge (booked nights) - discount + approved amenities +
 * other charges added by the front desk; balance = total - verified payments.
 */
final class StayBill
{
    public const TIMEZONE = 'Asia/Manila';

    /** Printed on the check-out bill and the final receipt. */
    public const NO_REFUND_NOTE = 'No refunds for early check-out (hotel policy).';

    /** Active rooms billed up to today - what the receptionist's check-out bill and a completed stay use. */
    public const FINAL = 'final';

    /** Active rooms billed up to the later of today and the scheduled check-out - a stay still in progress. */
    public const PROJECTED = 'projected';

    /** FINAL once the stay is over, PROJECTED while it is still running (the bill exists from check-in). */
    public static function modeFor(Booking $booking): string
    {
        return $booking->booking_status === Booking::STATUS_COMPLETED ? self::FINAL : self::PROJECTED;
    }

    /** @return array<string,mixed> */
    public static function forBooking(Booking $booking, string $mode = self::FINAL, ?CarbonInterface $asOf = null, ?Billing $billing = null): array
    {
        $booking->loadMissing(['rooms.roomType', 'room.roomType', 'reservation']);
        $billing ??= $booking->listPreloaded ? $booking->billing : $booking->billing()->with('additionalCharges')->first();

        $today = self::day($asOf ?? Carbon::now());
        $checkIn = self::calendarDate($booking->check_in);
        $scheduledOut = self::calendarDate($booking->check_out);
        $scheduledNights = max(1, self::daysBetween($checkIn, $scheduledOut));

        $rooms = $booking->rooms->isNotEmpty() ? $booking->rooms : collect([$booking->room])->filter();
        // Booked but no physical room assigned yet: bill what was booked (the room lines' frozen rates, else the room
        // type's), for the scheduled nights - nothing can have been extended or cut short before check-in.
        $assigned = $rooms->isNotEmpty();
        if (! $assigned) {
            $rooms = self::bookedRooms($booking);
        }
        $stayEnded = $booking->booking_status === Booking::STATUS_COMPLETED;
        $endedAt = $booking->checked_out_at ?? $booking->completed_at;
        $endedOn = $endedAt ? self::day($endedAt) : null;

        $build = function (?int $forcedNights) use ($rooms, $assigned, $checkIn, $scheduledNights, $scheduledOut, $stayEnded, $endedOn): array {
            $lines = [];
            $roomCharge = 0.0;
            $scheduledRoomCharge = 0.0;
            $latestOut = null;

            foreach ($rooms as $room) {
                $checkedOutAt = $room->pivot->checked_out_at ?? null;
                $isCheckedOut = (bool) $checkedOutAt;

                // Always the BOOKED nights (no refunds, no credit for nights not used). $forcedNights only exists to read an
                // older, already settled bill that was once priced on the nights stayed (see the reconciliation below).
                if ($forcedNights !== null) {
                    $billedUntil = $checkIn->copy()->addDays($forcedNights);
                    $isCheckedOut = true;
                } else {
                    $billedUntil = $scheduledOut;
                    $isCheckedOut = $isCheckedOut || ($assigned && $stayEnded);
                }
                $leftOn = $checkedOutAt ? self::day(Carbon::parse($checkedOutAt)) : ($endedOn ?? $billedUntil);

                $nights = max(1, self::daysBetween($checkIn, $billedUntil));
                $rate = (float) $room->room_rate;
                $subtotal = round($rate * $nights, 2);
                $scheduledSubtotal = round($rate * $scheduledNights, 2);

                $roomCharge += $subtotal;
                $scheduledRoomCharge += $scheduledSubtotal;
                $latestOut = $latestOut === null || $billedUntil->gt($latestOut) ? $billedUntil : $latestOut;

                $lines[] = [
                    'room_id' => $room->id,
                    'room_number' => $room->room_number,
                    'room_type_id' => isset($room->room_type_id) ? (string) $room->room_type_id : null,
                    'room_type' => $room->roomType?->name,
                    'rate' => round($rate, 2),
                    'status' => $isCheckedOut ? 'checked_out' : 'active',
                    'checked_out_on' => $isCheckedOut ? $leftOn->toDateString() : null,
                    'billed_until' => $billedUntil->toDateString(),
                    'scheduled_nights' => $scheduledNights,
                    'nights' => $nights,
                    'scheduled_subtotal' => $scheduledSubtotal,
                    'subtotal' => $subtotal,
                ];
            }

            return [$lines, round($roomCharge, 2), round($scheduledRoomCharge, 2), $latestOut];
        };

        [$lines, $roomCharge, $scheduledRoomCharge, $latestOut] = $build(null);

        // A finished stay is a fact already written on its billing. When the recomputed room charge differs from it
        // (a bill settled before nights were counted in hotel-local days, e.g. a 7 AM check-out the old UTC clock
        // still dated the day before), keep the recorded charge and take the whole-night count that explains it, so
        // the lines the guest sees always add up to the amount they actually paid.
        if ($stayEnded && $billing && abs($roomCharge - (float) $billing->room_charge) > 0.009) {
            $rates = (float) $rooms->sum(fn ($r) => (float) $r->room_rate);
            $implied = $rates > 0 ? (float) $billing->room_charge / $rates : 0.0;
            if ($implied >= 1 && abs($implied - round($implied)) < 0.0001) {
                [$lines, $roomCharge, $scheduledRoomCharge, $latestOut] = $build((int) round($implied));
            }
        }

        $actualOut = $latestOut ?? $scheduledOut;
        $actualNights = max(1, self::daysBetween($checkIn, $actualOut));

        // Information only, never a price: the day the guest actually leaves (a finished stay's check-out day; today while the
        // desk is billing it) against the booked check-out. An early departure is shown with the no-refund note.
        $departure = $stayEnded ? ($endedOn ?? $scheduledOut) : ($mode === self::FINAL ? $today : null);
        $leftEarly = $departure !== null && $departure->lt($scheduledOut);
        $leftLate = $departure !== null && $departure->gt($scheduledOut);

        $amenityCharge = $billing ? round((float) $billing->amenity_charge, 2) : self::amenityCharge($booking);
        // Itemized amenities: what is on the bill (approved only for a guest request), and the "Additional amenities" among
        // them - those the guest requested after booking or the desk added - as their own lines.
        $amenityRows = $booking->preloadedAmenityRequests !== null
            ? ($billing ? $booking->preloadedBilledAmenityRequests : $booking->preloadedAmenityRequests)
            : ($billing ? $booking->billedAmenityRequests()->with('room')->get() : $booking->billableAmenityRequests()->with('room')->get());
        // The amenity rows frozen on the bill at check-in (listed in its receipt snapshot); older stays fall back to timestamps.
        $checkinAmenityIds = isset($billing?->checkin_receipt_snapshot['amenity_ids']) ? array_map('intval', $billing->checkin_receipt_snapshot['amenity_ids']) : null;
        $amenityLines = $amenityRows->map(fn ($r) => [
            // The room it was requested for (null: one booked for the whole stay). Only read when the relation is loaded.
            'room_id' => $r->room_id,
            'room_number' => $r->relationLoaded('room') ? $r->room?->room_number : null,
            'amenity_name' => $r->amenity_name,
            'quantity' => (int) $r->quantity,
            'unit_price' => round((float) $r->charge, 2),
            'subtotal' => round((float) $r->charge * (int) $r->quantity, 2),
            'additional' => $r->origin !== AmenityRequest::ORIGIN_BOOKING,
            // Was it already on the bill the guest paid at check-in? (only meaningful once the stay was settled there)
            'on_checkin_bill' => $checkinAmenityIds !== null
                ? in_array((int) $r->id, $checkinAmenityIds, true)
                : CheckInSettlement::wasOnCheckInBill($billing, self::onBillSince($r)),
        ])->values()->all();
        // The guest's requests the desk has not approved: listed apart from the bill, never part of any total.
        $undecided = $booking->preloadedUndecidedAmenityRequests ?? AmenityRequest::query()
            ->where($booking->reservation_id ? 'reservation_id' : 'booking_id', $booking->reservation_id ?? $booking->id)
            ->where('origin', AmenityRequest::ORIGIN_GUEST_REQUEST)
            ->whereIn('status', ['pending', 'rejected'])
            ->get();
        $requestedAmenities = $undecided->map(fn ($r) => [
            'amenity_name' => $r->amenity_name,
            'quantity' => (int) $r->quantity,
            'subtotal' => round((float) $r->charge * (int) $r->quantity, 2),
            'status' => $r->status,
            'reason' => $r->status === 'rejected' ? $r->rejection_reason : null,
        ])->values()->all();
        $additionalAmenities = array_values(array_filter($amenityLines, fn ($l) => $l['additional']));
        $additionalAmenitiesTotal = round(array_sum(array_column($additionalAmenities, 'subtotal')), 2);
        $guestFee = $billing ? round((float) $billing->additional_guest_fee, 2) : 0.0;
        $extraCharges = $billing
            ? $billing->additionalCharges->map(fn ($c) => [
                'id' => $c->id,
                'description' => $c->label,
                'amount' => round((float) $c->amount, 2),
            ])->all()
            : [];
        $extraChargesTotal = round(array_sum(array_column($extraCharges, 'amount')), 2);
        $addOns = round($amenityCharge + $guestFee + $extraChargesTotal, 2);
        $subtotal = round($roomCharge + $addOns, 2);

        $idStatus = self::discountIdStatus($booking);
        $discount = $idStatus === 'approved' ? self::approvedDiscount($booking, $billing) : null;
        $discountAmount = round(BillDiscount::amount($discount, $roomCharge, $addOns), 2);

        // Paid in full at check-in => the discount was taken off THEN, once. It is never recalculated afterwards, so
        // an amenity or desk charge added during the stay is billed at full price and not discounted a second time.
        $settledAtCheckIn = CheckInSettlement::settledAt($billing) !== null;
        $snapshot = $settledAtCheckIn ? (array) ($billing->checkin_receipt_snapshot ?? []) : [];
        $amenityAtCheckIn = 0.0;
        $roomAtCheckIn = $scheduledRoomCharge;
        $discountAtCheckIn = 0.0;
        if ($settledAtCheckIn) {
            $amenityAtCheckIn = isset($snapshot['amenity_charge'])
                ? round((float) $snapshot['amenity_charge'], 2)
                : round(array_sum(array_map(fn ($l) => $l['on_checkin_bill'] ? $l['subtotal'] : 0.0, $amenityLines)), 2);
            $roomAtCheckIn = isset($snapshot['room_charge']) ? round((float) $snapshot['room_charge'], 2) : $scheduledRoomCharge;
            $discountAtCheckIn = round((float) ($snapshot['discount']['amount'] ?? $billing->discount), 2);
            $discountAmount = min($discountAtCheckIn, $subtotal);
            $discount ??= $billing->discountApplied;
        }
        if ($stayEnded && $billing) {
            // Settled: the discount is the amount that was actually taken off, whatever the Discount module says today.
            $discountAmount = round((float) $billing->discount, 2);
            $discount = $discountAmount > 0 ? ($billing->discountApplied ?? $discount) : null;
        }

        $total = round(max(0.0, $subtotal - $discountAmount), 2);
        $paid = round((float) $booking->allPayments()->where('payment_status', 'completed')->sum('amount_paid'), 2);

        $breakdown = $settledAtCheckIn
            ? self::receiptBreakdown($billing, $snapshot, $discount, $roomAtCheckIn, $amenityAtCheckIn, $discountAtCheckIn, $roomCharge, $amenityCharge, $amenityLines, $guestFee, $extraCharges, $extraChargesTotal, $discountAmount, $total, $paid)
            : null;

        return [
            'mode' => $mode,
            'check_in' => $checkIn->toDateString(),
            'scheduled_check_out' => $scheduledOut->toDateString(),
            'actual_check_out' => $actualOut->toDateString(),
            'scheduled_nights' => $scheduledNights,
            'actual_nights' => $actualNights,
            'is_late_checkout' => $leftLate,
            'is_early_checkout' => $leftEarly,
            'no_refund_note' => self::NO_REFUND_NOTE,
            'rooms' => $lines,
            'scheduled_room_charge' => $scheduledRoomCharge,
            'room_charge' => $roomCharge,
            'amenity_charge' => $amenityCharge,
            'amenity_lines' => $amenityLines,
            'additional_amenities' => $additionalAmenities,
            'additional_amenities_total' => $additionalAmenitiesTotal,
            'requested_amenities' => $requestedAmenities,
            'additional_guest_fee' => $guestFee,
            'additional_charges' => $extraCharges,
            'additional_charges_total' => $extraChargesTotal,
            'add_ons_total' => $addOns,
            'subtotal' => $subtotal,
            'discount_id_status' => $idStatus,
            'discount_id' => $discount?->id,
            'discount_name' => $discount?->name,
            'discount' => $discountAmount,
            'total' => $total,
            'total_paid' => $paid,
            'balance' => round(max(0.0, $total - $paid), 2),
            'receipt_breakdown' => $breakdown,
        ];
    }

    /**
     * The 3rd receipt's balance breakdown, shared word for word by the receptionist's web view and the guest app:
     * CHECK-IN SUMMARY (frozen when the guest paid; room x nights, the one-time discount, grand total, amount paid),
     * then what was added since (amenities + the desk's additional charges), the REMAINING BALANCE, and the
     * FINAL GRAND TOTAL = check-in grand total + everything added since. null for a stay not settled at check-in.
     *
     * @return array<string,mixed>
     */
    private static function receiptBreakdown(
        Billing $billing, array $snapshot, ?Discount $discount, float $roomAtCheckIn, float $amenityAtCheckIn, float $discountAtCheckIn,
        float $roomCharge, float $amenityCharge, array $amenityLines, float $guestFee, array $extraCharges, float $extraChargesTotal,
        float $discountAmount, float $total, float $paid,
    ): array {
        $checkinSubtotal = round($roomAtCheckIn + $amenityAtCheckIn, 2);
        $checkinTotal = round(max(0.0, $checkinSubtotal - $discountAtCheckIn), 2);
        $paidAtCheckIn = round((float) ($snapshot['total_paid'] ?? $billing->checkin_settled_total ?? $checkinTotal), 2);

        $newAmenities = array_values(array_map(fn ($l) => [
            'name' => $l['amenity_name'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'subtotal' => $l['subtotal'],
        ], array_filter($amenityLines, fn ($l) => ! $l['on_checkin_bill'])));
        $newAmenitiesTotal = round($amenityCharge - $amenityAtCheckIn, 2);
        // There is no stay-length adjustment any more (booked nights are always billed), so this is 0 for every new stay. It only
        // catches an older settled bill that was once priced on the nights stayed: whatever its total holds beyond the check-in
        // bill and the lines listed is shown as one plain "Adjustment" charge so the receipt still adds up.
        $legacy = round(($roomCharge - $roomAtCheckIn) - ($discountAmount - $discountAtCheckIn) + $guestFee, 2);
        $chargeLines = array_map(fn ($c) => ['name' => $c['description'], 'quantity' => 1, 'unit_price' => $c['amount'], 'subtotal' => $c['amount']], $extraCharges);
        if (abs($legacy) >= 0.005) {
            $chargeLines[] = ['name' => 'Adjustment', 'quantity' => 1, 'unit_price' => $legacy, 'subtotal' => $legacy];
        }
        $chargesTotal = round($extraChargesTotal + (abs($legacy) >= 0.005 ? $legacy : 0.0), 2);
        $afterSubtotal = round($newAmenitiesTotal + $chargesTotal, 2);
        $percent = $discount && $discount->discount_type === 'percentage' ? (float) $discount->value : null;

        return [
            'checkin' => [
                'room_charge' => $roomAtCheckIn,
                'booked_amenities' => $amenityAtCheckIn,
                'discount_name' => $discountAtCheckIn > 0 ? $discount?->name : null,
                'discount_percent' => $discountAtCheckIn > 0 ? $percent : null,
                'discount' => $discountAtCheckIn,
                'grand_total' => $checkinTotal,
                'amount_paid' => $paidAtCheckIn,
                'paid_in_full' => $paidAtCheckIn + 0.004 >= $checkinTotal,
                'status' => $paidAtCheckIn + 0.004 >= $checkinTotal ? 'PAID IN FULL' : 'PARTIALLY PAID',
            ],
            'after_checkin' => [
                'amenities' => $newAmenities,
                'amenities_total' => $newAmenitiesTotal,
                'charges' => $chargeLines,
                'charges_total' => $chargesTotal,
                'subtotal' => $afterSubtotal,
            ],
            'remaining_balance' => round(max(0.0, $afterSubtotal), 2),
            'final_grand_total' => $total,
            'total_paid' => $paid,
            'balance_due' => round(max(0.0, $total - $paid), 2),
        ];
    }

    /** When an amenity line landed on the bill: a guest request counts from its approval, anything else from its creation. */
    private static function onBillSince(AmenityRequest $r)
    {
        return $r->origin === AmenityRequest::ORIGIN_GUEST_REQUEST && $r->decided_at ? $r->decided_at : $r->created_at;
    }

    /**
     * One placeholder per booked room when none is physically assigned: each room line's frozen rate x quantity, or
     * the room type's rate x rooms_requested for a booking without lines.
     */
    private static function bookedRooms(Booking $booking): \Illuminate\Support\Collection
    {
        $make = fn (float $rate, ?string $type) => (object) [
            'id' => null,
            'room_number' => null,
            'room_rate' => $rate,
            'roomType' => (object) ['name' => $type],
            'pivot' => null,
        ];

        $lines = $booking->room_lines;
        if (! empty($lines)) {
            return collect($lines)->flatMap(fn ($line) => array_fill(0, max(1, (int) $line['quantity']), $make((float) $line['price_per_night'], $line['room_type'] ?? null)))->values();
        }

        return collect(array_fill(0, max(1, (int) $booking->rooms_requested), $make((float) ($booking->roomType->rate ?? 0), $booking->roomType?->name)));
    }

    /** "1 night" / "5 nights" - one wording everywhere a night count is spoken. */
    public static function nightsLabel(int $nights): string
    {
        return $nights.($nights === 1 ? ' night' : ' nights');
    }

    /** not_requested | pending | approved | rejected - the booking's own status, falling back to its reservation's. */
    public static function discountIdStatus(Booking $booking): string
    {
        $status = $booking->discount_verification_status ?: $booking->reservation?->discount_verification_status;

        return in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'not_requested';
    }

    /** The discount the guest claimed (even one that has expired since - it is honored for them). */
    public static function approvedDiscount(Booking $booking, ?Billing $billing = null): ?Discount
    {
        $holder = $booking->reservation ?? $booking;
        $id = $booking->discount_id ?? $holder->discount_id ?? $billing?->discount_id;
        [$discount] = DiscountSelection::resolve($id, $holder->id_card_type ?? $booking->id_card_type, null, false);

        return $discount;
    }

    private static function amenityCharge(Booking $booking): float
    {
        // Before a Billing exists the amenities are the ones the guest committed to and paid for at booking time
        // (everything not rejected) - exactly what Booking::total_amount_due counts.
        return $booking->billableAmenityTotal();
    }

    /** A moment -> the hotel-local calendar day at 00:00. */
    private static function day(CarbonInterface $moment): Carbon
    {
        return Carbon::instance($moment)->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * Date-only columns (check_in / check_out) are calendar dates, not moments - read their Y-m-d as the hotel's
     * own day instead of shifting a UTC midnight across the Manila offset.
     */
    private static function calendarDate(CarbonInterface $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date->format('Y-m-d'), self::TIMEZONE)->startOfDay();
    }

    private static function daysBetween(Carbon $from, Carbon $to): int
    {
        return (int) $from->diffInDays($to, false);
    }
}
