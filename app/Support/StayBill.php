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
 * Nights are CALENDAR days in Asia/Manila, minimum 1: check-in Oct 5 -> check-out Oct 6 is 1 night, and a
 * guest actually checked out on Oct 10 is 5 nights, the 4 extra nights billed. A room that was checked out on
 * its own is billed up to its own check-out day; a room still occupied is billed up to today (FINAL), or - while
 * the stay is still running and nothing has been settled - up to the later of today and the scheduled check-out
 * (PROJECTED, so a guest mid-stay is never shown a smaller total than the one they booked).
 * Early check-out keeps its existing rule: the guest pays the nights actually stayed (at least 1).
 */
final class StayBill
{
    public const TIMEZONE = 'Asia/Manila';

    /** Active rooms billed up to today - what the receptionist's check-out bill and a completed stay use. */
    public const FINAL = 'final';

    /** Active rooms billed up to the later of today and the scheduled check-out - a stay still in progress. */
    public const PROJECTED = 'projected';

    /** @return array<string,mixed> */
    public static function forBooking(Booking $booking, string $mode = self::FINAL, ?CarbonInterface $asOf = null, ?Billing $billing = null): array
    {
        $booking->loadMissing(['rooms.roomType', 'room.roomType', 'reservation']);
        $billing ??= $booking->billing()->with('additionalCharges')->first();

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

        $build = function (?int $forcedNights) use ($rooms, $assigned, $checkIn, $scheduledNights, $scheduledOut, $stayEnded, $endedOn, $mode, $today): array {
            $lines = [];
            $roomCharge = 0.0;
            $scheduledRoomCharge = 0.0;
            $latestOut = null;

            foreach ($rooms as $room) {
                $checkedOutAt = $room->pivot->checked_out_at ?? null;
                $isCheckedOut = (bool) $checkedOutAt;

                if (! $assigned) {
                    $billedUntil = $scheduledOut;
                } elseif ($forcedNights !== null) {
                    $billedUntil = $checkIn->copy()->addDays($forcedNights);
                    $isCheckedOut = true;
                } elseif ($isCheckedOut) {
                    $billedUntil = self::day(Carbon::parse($checkedOutAt));
                } elseif ($stayEnded) {
                    // Single-room stays never go through the room-by-room check-out, so their pivot row has no
                    // timestamp: the stay's own check-out moment (else the scheduled day) closes the room - never
                    // "today", or a finished bill would grow by a night every midnight.
                    $billedUntil = $endedOn ?? $scheduledOut;
                    $isCheckedOut = true;
                } elseif ($mode === self::PROJECTED) {
                    $billedUntil = $today->gt($scheduledOut) ? $today : $scheduledOut;
                } else {
                    $billedUntil = $today;
                }

                $nights = max(1, self::daysBetween($checkIn, $billedUntil));
                $rate = (float) $room->room_rate;
                $subtotal = round($rate * $nights, 2);
                $scheduledSubtotal = round($rate * $scheduledNights, 2);
                $extraNights = max(0, $nights - $scheduledNights);

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
                    'checked_out_on' => $isCheckedOut ? $billedUntil->toDateString() : null,
                    'billed_until' => $billedUntil->toDateString(),
                    'scheduled_nights' => $scheduledNights,
                    'nights' => $nights,
                    'extra_nights' => $extraNights,
                    'scheduled_subtotal' => $scheduledSubtotal,
                    'subtotal' => $subtotal,
                    'extra_nights_charge' => round($rate * $extraNights, 2),
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

        $amenityCharge = $billing ? round((float) $billing->amenity_charge, 2) : self::amenityCharge($booking);
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
        if ($stayEnded && $billing) {
            // Settled: the discount is the amount that was actually taken off, whatever the Discount module says today.
            $discountAmount = round((float) $billing->discount, 2);
            $discount = $discountAmount > 0 ? ($billing->discountApplied ?? $discount) : null;
        }

        $total = round(max(0.0, $subtotal - $discountAmount), 2);
        $paid = round((float) $booking->allPayments()->where('payment_status', 'completed')->sum('amount_paid'), 2);

        return [
            'mode' => $mode,
            'check_in' => $checkIn->toDateString(),
            'scheduled_check_out' => $scheduledOut->toDateString(),
            'actual_check_out' => $actualOut->toDateString(),
            'scheduled_nights' => $scheduledNights,
            'actual_nights' => $actualNights,
            'extra_nights' => max(0, $actualNights - $scheduledNights),
            'is_late_checkout' => $actualOut->gt($scheduledOut),
            'is_early_checkout' => $actualOut->lt($scheduledOut),
            'rooms' => $lines,
            'scheduled_room_charge' => $scheduledRoomCharge,
            'room_charge' => $roomCharge,
            'extra_nights_charge' => round(array_sum(array_column($lines, 'extra_nights_charge')), 2),
            'amenity_charge' => $amenityCharge,
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
        ];
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
